<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Tests\ExceptionMailer;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Sparklink\EmailErrorsBundle\ExceptionMailer\ExceptionMailer;
use Sparklink\EmailErrorsBundle\Twig\DataPanel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the real email template: panel data the template can't display (wrong type...) makes the rendering fail,
 * and the email is then not sent at all.
 */
final class TemplateRenderingTest extends TestCase
{
    /** @var Email[] */
    private array $sent = [];

    /** @var string[] */
    private array $errors = [];

    public function testEmailWithProbableCauseAndSecurityPanels(): void
    {
        $html = $this->send(new \Exception('Cannot query field "RequestOfferPrice" on type "Mutation".'), new Request());

        $this->assertStringContainsString('Probable cause', $html);
        $this->assertStringContainsString('The user is not authenticated', $html);
        $this->assertStringContainsString('Security', $html);
    }

    /**
     * @return iterable<string, array{?Request}>
     */
    public static function requests(): iterable
    {
        yield 'web request' => [new Request()];
        yield 'no request (command, worker)' => [null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requests')]
    public function testEmailWithValidationErrorsPanel(?Request $request): void
    {
        $exception = new class('Invalid payload') extends \Exception {
            public function getViolations(): array
            {
                return [new class {
                    public function getPropertyPath(): string
                    {
                        return 'content';
                    }

                    public function getMessage(): string
                    {
                        return 'Inquiry-quota has been reached today.';
                    }
                }];
            }
        };

        $html = $this->send($exception, $request);

        $this->assertStringContainsString('Validation errors', $html);
        $this->assertStringContainsString('Inquiry-quota has been reached today.', $html);
    }

    public function testEmailWithAdditionalPanelIsRedacted(): void
    {
        $panel = new DataPanel('Log record', [
            'message' => 'Sync failed for {contact}',
            'context' => ['contact' => 42, 'credentials' => ['password' => 'not-a-real-password']],
        ]);

        $html = $this->send(new \Exception('Sync failed'), null, [$panel]);

        $this->assertStringContainsString('Log record', $html);
        $this->assertStringContainsString('Sync failed for {contact}', $html);
        $this->assertStringNotContainsString('not-a-real-password', $html);
    }

    public function testErrorReportedWhileSendingIsNotSentAgain(): void
    {
        // The failure is logged to a channel whose records are sent by email (Monolog handler on the main channel)
        $attempts = 0;
        $exceptionMailer = null;
        $mailer = new class($attempts) implements MailerInterface {
            public function __construct(private int &$attempts)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                ++$this->attempts;

                throw new \RuntimeException('SMTP down');
            }
        };
        $logger = new class($exceptionMailer) extends AbstractLogger {
            public function __construct(private ?ExceptionMailer &$exceptionMailer)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->exceptionMailer->sendException(new \RuntimeException((string) $message));
            }
        };
        $exceptionMailer = new ExceptionMailer($mailer, $this->environment(), $logger, 'from@example.com', 'to@example.com', '[Test]');

        $exceptionMailer->sendException(new \Exception('Boom'));
        $this->assertSame(1, $attempts);

        // The mailer is usable again afterwards
        $exceptionMailer->sendException(new \Exception('Boom'));
        $this->assertSame(2, $attempts);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requestBodies(): iterable
    {
        yield 'JSON object' => ['{"variables": {"password": "not-a-real-password"}}'];
        yield 'JSON scalar' => ['"just a string"'];
        yield 'not JSON' => ['_username=john&_password=not-a-real-password'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('requestBodies')]
    public function testEmailWithRequestContent(string $body): void
    {
        // As in production (php.ini-production): otherwise the stack trace would show $body, the argument of this
        // test method, and the assertion below would be about the trace rather than the "Request content" panel
        $ignoreArgs = ini_set('zend.exception_ignore_args', '1');
        try {
            $exception = new \Exception('Boom');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }

        $html = $this->send($exception, new Request(content: $body));

        $this->assertStringContainsString('Request content', $html);
        $this->assertStringNotContainsString('not-a-real-password', $html);
    }

    private function environment(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../templates', 'EmailErrors');

        return new Environment($loader);
    }

    /**
     * @param DataPanel[] $panels
     */
    private function send(\Throwable $exception, ?Request $request, array $panels = []): string
    {
        $mailer = new class($this->sent) implements MailerInterface {
            public function __construct(private array &$sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        };

        $logger = new class($this->errors) extends AbstractLogger {
            public function __construct(private array &$errors)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->errors[] = $message.' '.json_encode($context);
            }
        };

        (new ExceptionMailer($mailer, $this->environment(), $logger, 'from@example.com', 'to@example.com', '[Test]', [], [], [], new TokenStorage()))
            ->sendExceptionWithPanels($exception, $panels, $request);

        $this->assertSame([], $this->errors);
        $this->assertCount(1, $this->sent);

        return $this->sent[0]->getHtmlBody();
    }
}
