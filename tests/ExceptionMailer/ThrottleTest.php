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
    private function error(string $message = 'Boom'): \RuntimeException
    {
        return new \RuntimeException($message);
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
