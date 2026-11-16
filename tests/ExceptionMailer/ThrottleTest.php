<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Tests\ExceptionMailer;

use PHPUnit\Framework\TestCase;
use Sparklink\EmailErrorsBundle\ExceptionMailer\ExceptionMailer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

final class ThrottleTest extends TestCase
{
    private function mailer(MailerInterface $mailer, int $throttle): ExceptionMailer
    {
        return new ExceptionMailer($mailer, $this->createStub(Environment::class), null, 'from@example.com', 'to@example.com', '[Test]', [], [], [], null, new ArrayAdapter(), $throttle);
    }

    /** Same class, message, file and line each time */
    private function error(string $message = 'Boom', ?\Throwable $previous = null): \RuntimeException
    {
        return new \RuntimeException($message, 0, $previous);
    }

    /** A generic error carrying a single constraint violation, thrown from the same place each time */
    private function invalidPayload(string $violationMessage): \Exception
    {
        $violation = new class($violationMessage) {
            public function __construct(private string $message)
            {
            }

            public function getMessage(): string
            {
                return $this->message;
            }
        };

        return new class('Invalid payload', [$violation]) extends \Exception {
            public function __construct(string $message, private array $violations)
            {
                parent::__construct($message);
            }

            public function getViolations(): array
            {
                return $this->violations;
            }
        };
    }

    public function testSameErrorIsSentOnceWithinTheThrottle(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $exceptionMailer = $this->mailer($mailer, 3600);
        $exceptionMailer->sendException($this->error());
        $exceptionMailer->sendException($this->error());
    }

    public function testDifferentErrorsAreAllSent(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))->method('send');

        $exceptionMailer = $this->mailer($mailer, 3600);
        $exceptionMailer->sendException($this->error('Boom'));
        $exceptionMailer->sendException($this->error('Other'));
    }

    public function testErrorsWrappingDifferentExceptionsAreAllSent(): void
    {
        // Like GraphQL errors: same wrapper class, message and place, different underlying exception
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))->method('send');

        $exceptionMailer = $this->mailer($mailer, 3600);
        $exceptionMailer->sendException($this->error('Boom', new \LogicException('Cause A')));
        $exceptionMailer->sendException($this->error('Boom', new \LogicException('Cause B')));
    }

    public function testErrorsWithDifferentViolationsAreAllSent(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(3))->method('send');

        $exceptionMailer = $this->mailer($mailer, 3600);
        $exceptionMailer->sendException($this->invalidPayload('Inquiry-quota has been reached today.'));
        $exceptionMailer->sendException($this->invalidPayload('This email is already used.'));
        $exceptionMailer->sendException($this->invalidPayload('This email is already used.'));
        $exceptionMailer->sendException($this->error('Boom', $this->invalidPayload('Inquiry-quota has been reached today.')));
    }

    public function testEverythingIsSentWithoutThrottle(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))->method('send');

        $exceptionMailer = $this->mailer($mailer, 0);
        $exceptionMailer->sendException($this->error());
        $exceptionMailer->sendException($this->error());
    }

    public function testErrorWhoseEmailFailedIsSentAgain(): void
    {
        $calls  = 0;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->exactly(2))->method('send')->willReturnCallback(function () use (&$calls) {
            if (1 === ++$calls) {
                throw new \RuntimeException('SMTP down');
            }
        });

        $exceptionMailer = $this->mailer($mailer, 3600);
        $exceptionMailer->sendException($this->error());
        $exceptionMailer->sendException($this->error());
    }
}
