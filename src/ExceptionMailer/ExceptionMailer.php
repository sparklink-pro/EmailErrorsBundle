<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\ExceptionMailer;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Sparklink\EmailErrorsBundle\Twig\DataPanel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Environment;

class ExceptionMailer
{
    /**
     * Header names (lowercase) whose values are redacted in the email: they carry
     * credentials that would allow whoever reads the mailbox to impersonate the user.
     */
    protected const SENSITIVE_HEADERS = ['authorization', 'cookie', 'php-auth-pw', 'php-auth-user', 'x-api-key', 'x-auth-token', 'x-csrf-token'];

    /**
     * Cookies are redacted when their lowercase name contains one of these patterns
     * (session ids, auth/remember-me tokens...) or matches an explicit name below.
     */
    protected const SENSITIVE_COOKIE_PATTERNS = ['sess', 'token', 'auth', 'remember'];
    protected const SENSITIVE_COOKIES = ['cf_clearance'];

    /**
     * Request parameters (query, form fields, JSON body at any depth) are fully
     * redacted when their lowercase key contains one of these patterns: a sign-up
     * or login request would otherwise put the user's plain password in the email.
     */
    protected const SENSITIVE_PARAMETER_PATTERNS = ['password', 'passwd', 'secret'];

    protected ?\Throwable $currentException = null;

    public function __construct(
        protected MailerInterface $mailer,
        protected Environment $twig,
        protected ?LoggerInterface $logger,
        protected string $from,
        protected string $to,
        protected string $subject,
        protected array $ignoredClasses = [],
        protected array $ignoredMessages = [],
        protected array $ignoredIpAddresses = [],
        protected ?TokenStorageInterface $tokenStorage = null,
        protected ?CacheItemPoolInterface $cache = null,
        protected int $throttle = 0,
    ) {
    }

    public function sendException(\Throwable $exception, ?Request $request = null, ?HttpKernelInterface $kernel = null)
    {
        $this->currentException = $exception;

        try {
            // Ignored exceptions
            if (\in_array($exception::class, $this->ignoredClasses)) {
                return;
            }

            // Ignored exceptions messages
            foreach ($this->ignoredMessages as $ignoredMessage) {
                if (str_contains($exception->getMessage(), $ignoredMessage)) {
                    return;
                }
            }

            // Ignored IP addresses
            if ($request) {
                foreach ($request->getClientIps() as $ip) {
                    if (\in_array($ip, $this->ignoredIpAddresses)) {
                        return;
                    }
                }
            }

            // Already sent less than $throttle seconds ago
            $throttleItem = $this->getThrottleItem($exception);
            if ($throttleItem?->isHit()) {
                return;
            }

            $mail = new Email();
            $mail->from(Address::create($this->from));
            $mail->to(Address::create($this->to));
            $mail->subject(sprintf('%s %s', $this->subject, $exception->getMessage()));

            $exceptions = [];
            $exceptions[] = ['class' => $exception::class, 'instance' => $exception];

            while ($exception = $exception->getPrevious()) {
                $exceptions[] = ['class' => $exception::class, 'instance' => $exception];
            }

            $mail->html($this->twig->render('@EmailErrors/exception.html.twig', [
                'subject' => $this->subject,
                'exceptions' => $exceptions,
                'request' => $request,
                'request_panels' => $request ? $this->getRequestDataPanels($request) : [],
                'kernel' => $kernel,
            ]));

            $this->mailer->send($mail);

            if ($throttleItem) {
                $this->cache->save($throttleItem->set(true)->expiresAfter($this->throttle));
            }
        } catch (\Exception $e) {
            $this->logger?->error('Error sending exception email', ['exception' => $e->getMessage()]);
        } finally {
            $this->currentException = null;
        }
    }

    /**
     * Cache item of an error when throttling is on: the same error (class, message, file and line) is sent at most once
     * per $throttle seconds, so an error repeated in a loop (a page called again and again, a command run every minute)
     * doesn't flood the mailbox.
     */
    protected function getThrottleItem(\Throwable $exception): ?CacheItemInterface
    {
        if ($this->throttle <= 0 || null === $this->cache) {
            return null;
        }

        return $this->cache->getItem('email_errors_'.sha1($exception::class.'|'.$exception->getMessage().'|'.$exception->getFile().'|'.$exception->getLine()));
    }

    /**
     * @return DataPanel[]
     */
    protected function getRequestDataPanels(Request $request): array
    {
        $panels = [];

        if (null !== $probableCause = $this->getProbableCause()) {
            $panels[] = new DataPanel('Probable cause', $probableCause, DataPanel::TYPE_STRING);
        }

        if (null !== $this->tokenStorage) {
            $panels[] = new DataPanel('Security', $this->getSecurityData($request));
        }

        if (!empty($violations = $this->getViolationsData())) {
            $panels[] = new DataPanel('Validation errors', $violations);
        }

        if (!empty($request->query->all())) {
            $panels[] = new DataPanel('GET Parameters', $this->redactParameters($request->query->all()));
        }
        if (!empty($request->request->all())) {
            $panels[] = new DataPanel('POST Parameters', $this->redactParameters($request->request->all()));
        }

        if ($request->getContent()) {
            $content = $request->getContent();
            $type = DataPanel::TYPE_STRING;
            try {
                $content = json_decode($content, true);
                $type = DataPanel::TYPE_JSON;

                if (\is_array($content)) {
                    $content = $this->redactParameters($content);
                }
            } catch (\Exception $e) {
            }

            $panels[] = new DataPanel('Request content', $content, $type);
        }

        if (!empty($request->attributes->all())) {
            $panels[] = new DataPanel('Attributes', $request->attributes->all());
        }

        if (!empty($request->headers->all())) {
            $panels[] = new DataPanel('Headers', $this->redactHeaders($request->headers->all()));
        }

        if (!empty($request->files->all())) {
            $panels[] = new DataPanel('Files', $request->files->all());
        }

        if (!empty($request->cookies->all())) {
            $panels[] = new DataPanel('Cookies', $this->redactCookies($request->cookies->all(), $request));
        }

        if ($request->hasSession(true)) {
            $panels[] = new DataPanel('Session', $this->redactSessionData($request->getSession()->all()));
        }

        return $panels;
    }

    /**
     * Extracts constraint violations from the exception chain (duck-typed so the
     * bundle needs no dependency on symfony/validator): errors like a validation
     * failure otherwise only show a generic "Invalid payload" message, hiding
     * which constraint was violated (quota reached, blank field...).
     */
    protected function getViolationsData(): array
    {
        $data = [];

        for ($exception = $this->currentException; null !== $exception; $exception = $exception->getPrevious()) {
            if (!method_exists($exception, 'getViolations') || !is_iterable($violations = $exception->getViolations())) {
                continue;
            }

            foreach ($violations as $violation) {
                if (!\is_object($violation) || !method_exists($violation, 'getMessage')) {
                    continue;
                }

                $path = method_exists($violation, 'getPropertyPath') ? (string) $violation->getPropertyPath() : '';
                $code = method_exists($violation, 'getCode') ? $violation->getCode() : null;

                $data[] = sprintf(
                    '%s%s%s',
                    '' !== $path ? $path.': ' : '',
                    $violation->getMessage(),
                    null !== $code ? sprintf(' (code %s)', $code) : ''
                );
            }
        }

        return $data;
    }

    protected function getSecurityData(Request $request): array
    {
        $token = $this->tokenStorage?->getToken();

        return [
            'authenticated' => null !== $token ? 'yes' : 'no',
            'user' => $token?->getUserIdentifier() ?? '(anonymous)',
            'roles' => null !== $token ? implode(', ', $token->getRoleNames()) : '(none)',
            'session cookie sent' => $request->hasPreviousSession() ? 'yes' : 'no',
        ];
    }

    /**
     * Explains errors whose cause is not visible in the request itself, such as
     * GraphQL fields hidden from anonymous users by a `public: "@=hasRole(...)"`
     * expression: the "Cannot query field" error they produce looks like a schema
     * bug but is usually just an expired session.
     */
    protected function getProbableCause(): ?string
    {
        if (null === $this->currentException || null === $this->tokenStorage) {
            return null;
        }

        $isSchemaFieldError = str_contains($this->currentException->getMessage(), 'Cannot query field');
        $isAnonymous = null === $this->tokenStorage->getToken();

        if ($isSchemaFieldError && $isAnonymous) {
            return 'The user is not authenticated, so any GraphQL field restricted by a `public: "@=hasRole(...)"` '
                .'expression is absent from the schema they see. The page was likely rendered while the user was '
                .'still logged in, then the session expired (or was closed in another tab) before they submitted. '
                .'Not a bug in the schema itself.';
        }

        return null;
    }

    protected function redactHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (\in_array(strtolower((string) $name), static::SENSITIVE_HEADERS, true)) {
                $headers[$name] = array_map($this->redact(...), (array) $values);
            }
        }

        return $headers;
    }

    protected function redactCookies(array $cookies, Request $request): array
    {
        $sensitiveNames = static::SENSITIVE_COOKIES;
        if ($request->hasSession(true)) {
            $sensitiveNames[] = $request->getSession()->getName();
        }

        foreach ($cookies as $name => $value) {
            if ($this->isSensitiveCookie((string) $name, $sensitiveNames) && \is_string($value)) {
                $cookies[$name] = $this->redact($value);
            }
        }

        return $cookies;
    }

    /**
     * The serialized security token stored in `_security_*` keys embeds the user
     * object, which can carry its password hash.
     */
    protected function redactSessionData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, '_security') && \is_string($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    /**
     * Unlike redact(), no prefix is kept: the first characters of a password are
     * part of the secret.
     */
    protected function redactParameters(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveParameter((string) $key)) {
                $data[$key] = '[redacted]';
            } elseif (\is_array($value)) {
                $data[$key] = $this->redactParameters($value);
            }
        }

        return $data;
    }

    protected function isSensitiveParameter(string $key): bool
    {
        foreach (static::SENSITIVE_PARAMETER_PATTERNS as $pattern) {
            if (str_contains(strtolower($key), $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function isSensitiveCookie(string $name, array $sensitiveNames): bool
    {
        if (\in_array($name, $sensitiveNames, true)) {
            return true;
        }

        foreach (static::SENSITIVE_COOKIE_PATTERNS as $pattern) {
            if (str_contains(strtolower($name), $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps a short prefix so entries remain distinguishable in the email without
     * being replayable (session hijacking from the error mailbox).
     */
    protected function redact(string $value): string
    {
        if (\strlen($value) <= 8) {
            return '[redacted]';
        }

        return substr($value, 0, 8).'…[redacted]';
    }
}
