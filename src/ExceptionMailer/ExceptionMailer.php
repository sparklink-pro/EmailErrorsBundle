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

    /**
     * True while an email is being prepared or sent: see sendExceptionWithPanels().
     */
    private bool $sending = false;

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
        $this->sendExceptionWithPanels($exception, [], $request, $kernel);
    }

    /**
     * Same as sendException(), with additional panels shown in the "Details" block of the email, next to the error's
     * own panels (e.g. the context of a log record, sent by the Monolog handler). Their array data is redacted like
     * request parameters.
     *
     * @param DataPanel[] $panels
     */
    public function sendExceptionWithPanels(\Throwable $exception, array $panels, ?Request $request = null, ?HttpKernelInterface $kernel = null): void
    {
        // An error raised while this email is prepared or sent and reported again to this mailer (failure logged to a
        // channel the Monolog handler listens to, application listener of a mailer event...) is not sent: it would
        // fail the same way, again and again, each attempt waiting for the mail server.
        if ($this->sending) {
            return;
        }

        $this->sending = true;

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
            for ($error = $exception; null !== $error; $error = $error->getPrevious()) {
                $exceptions[] = ['class' => $error::class, 'instance' => $error];
            }

            $mail->html($this->twig->render('@EmailErrors/exception.html.twig', [
                'subject' => $this->subject,
                'exceptions' => $exceptions,
                'exception_panels' => [...$this->getExceptionDataPanels($exception, $request), ...$this->redactPanels($panels)],
                'request' => $request,
                'request_panels' => $request ? $this->getRequestDataPanels($request) : [],
                'kernel' => $kernel,
            ]));

            $this->mailer->send($mail);

            if ($throttleItem) {
                $this->cache->save($throttleItem->set(true)->expiresAfter($this->throttle));
            }
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: an \Error raised here (TypeError...) would otherwise escape to the caller and
            // replace the original error page, or crash the command that logged the record
            $this->logger?->error('Error sending exception email', ['exception' => $e->getMessage()]);
        } finally {
            $this->sending = false;
        }
    }

    /**
     * Cache item of an error when throttling is on: the same error is sent at most once per $throttle seconds, so an
     * error repeated in a loop (a page called again and again, a command run every minute) doesn't flood the mailbox.
     *
     * The whole chain of previous exceptions is part of the key (class, message, file and line of each), as well as the
     * constraint violations: GraphQL errors, for instance, are all GraphQL\Error\Error instances built at the same place,
     * whatever the exception they wrap, and a generic "Invalid payload" error only differs from another by its violations.
     */
    protected function getThrottleItem(\Throwable $exception): ?CacheItemInterface
    {
        if ($this->throttle <= 0 || null === $this->cache) {
            return null;
        }

        $parts = [];
        for ($error = $exception; null !== $error; $error = $error->getPrevious()) {
            $parts[] = $error::class.'|'.$error->getMessage().'|'.$error->getFile().'|'.$error->getLine();
        }
        array_push($parts, ...$this->getViolationsData($exception));

        return $this->cache->getItem('email_errors_'.sha1(implode("\n", $parts)));
    }

    /**
     * Panels about the error itself rather than the request, shown in the "Details" block of the email: they are there
     * even without a request (command, Messenger worker, record logged by a command).
     *
     * @return DataPanel[]
     */
    protected function getExceptionDataPanels(\Throwable $exception, ?Request $request): array
    {
        $panels = [];

        if (null !== $request && null !== $probableCause = $this->getProbableCause($exception)) {
            $panels[] = new DataPanel('Probable cause', $probableCause, DataPanel::TYPE_STRING);
        }

        if (!empty($violations = $this->getViolationsData($exception))) {
            $panels[] = new DataPanel('Validation errors', $violations);
        }

        return $panels;
    }

    /**
     * @param DataPanel[] $panels
     *
     * @return DataPanel[]
     */
    protected function redactPanels(array $panels): array
    {
        return array_map(
            fn (DataPanel $panel) => \is_array($panel->getData())
                ? new DataPanel($panel->getTitle(), $this->redactParameters($panel->getData()), $panel->getType())
                : $panel,
            $panels
        );
    }

    /**
     * @return DataPanel[]
     */
    protected function getRequestDataPanels(Request $request): array
    {
        $panels = [];

        // Read before anything else: the Security panel and the cookie redaction get the session name, which turns a
        // lazy session factory into a session object (without starting it). The Session panel, which starts the
        // session, must still only appear when the application had already used it.
        $sessionWasInitialized = $request->hasSession(true);

        if (null !== $this->tokenStorage) {
            $panels[] = new DataPanel('Security', $this->getSecurityData($request));
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

        if ($sessionWasInitialized) {
            $panels[] = new DataPanel('Session', $this->redactSessionData($request->getSession()->all()));
        }

        return $panels;
    }

    protected function getViolationsData(?\Throwable $exception): array
    {
        $data = [];

        for (; null !== $exception; $exception = $exception->getPrevious()) {
            if (!method_exists($exception, 'getViolations')) {
                continue;
            }

            try {
                $violations = $exception->getViolations();
            } catch (\Throwable) {
                continue;
            }

            if (!is_iterable($violations)) {
                continue;
            }

            foreach ($violations as $violation) {
                if (!\is_object($violation) || !method_exists($violation, 'getMessage')) {
                    continue;
                }

                try {
                    $path = method_exists($violation, 'getPropertyPath') ? (string) $violation->getPropertyPath() : '';
                    $code = method_exists($violation, 'getCode') ? $violation->getCode() : null;

                    $data[] = sprintf(
                        '%s%s%s',
                        '' !== $path ? $path.': ' : '',
                        $violation->getMessage(),
                        null !== $code ? sprintf(' (code %s)', $code) : ''
                    );
                } catch (\Throwable) {
                    continue;
                }
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
    protected function getProbableCause(\Throwable $exception): ?string
    {
        if (null === $this->tokenStorage) {
            return null;
        }

        $isSchemaFieldError = str_contains($exception->getMessage(), 'Cannot query field');
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
        // hasSession() without skipIfUninitialized: the session cookie must be redacted even when the application
        // never touched the session during this request (stateless route, exception thrown early...). getName()
        // neither starts the session nor marks it as used.
        if ($request->hasSession()) {
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
     *
     * String values holding a JSON object or array are redacted too, then re-encoded: a GraphQL multipart request
     * (file upload) sends its query and variables as a JSON string in the "operations" field, and some clients send
     * "variables" as a JSON string.
     */
    protected function redactParameters(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveParameter((string) $key)) {
                $data[$key] = '[redacted]';
            } elseif (\is_array($value)) {
                $data[$key] = $this->redactParameters($value);
            } elseif (\is_string($value) && null !== $decoded = $this->decodeJsonContainer($value)) {
                $redacted = $this->redactParameters($decoded);
                if ($redacted !== $decoded) {
                    $data[$key] = json_encode($redacted, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '[redacted]';
                }
            }
        }

        return $data;
    }

    /**
     * The decoded value of a string holding a JSON object or array, null for any other string.
     */
    protected function decodeJsonContainer(string $value): ?array
    {
        $trimmed = ltrim($value);
        if ('' === $trimmed || ('{' !== $trimmed[0] && '[' !== $trimmed[0])) {
            return null;
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : null;
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
