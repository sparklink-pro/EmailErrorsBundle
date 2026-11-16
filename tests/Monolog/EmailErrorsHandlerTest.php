<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Tests\Monolog;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Sparklink\EmailErrorsBundle\ExceptionMailer\ExceptionMailer;
use Sparklink\EmailErrorsBundle\Monolog\EmailErrorsHandler;
use Sparklink\EmailErrorsBundle\Monolog\LoggedError;
use Sparklink\EmailErrorsBundle\Twig\DataPanel;

final class EmailErrorsHandlerTest extends TestCase
{
    public function testRecordWithoutExceptionIsSentWithItsMessage(): void
    {
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->once())->method('sendExceptionWithPanels')
            ->with($this->callback(fn ($exception) => $exception instanceof LoggedError && 'Sync down' === $exception->getMessage()));

        (new Logger('crm', [new EmailErrorsHandler($exceptionMailer)]))->critical('Sync down');
    }

    public function testExceptionOfTheRecordIsSent(): void
    {
        $error           = new \LogicException('Boom');
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->once())->method('sendExceptionWithPanels')->with($error);

        (new Logger('crm', [new EmailErrorsHandler($exceptionMailer)]))->critical('Something failed', ['exception' => $error]);
    }

    public function testRecordPanelShowsMessageChannelLevelContextAndExtra(): void
    {
        $panel  = null;
        $logger = new Logger('crm', [new EmailErrorsHandler($this->capturingMailer($panel))]);
        $logger->pushProcessor(function ($record) {
            $record->extra['hostname'] = 'web-1';

            return $record;
        });

        $logger->critical('Sync failed for {contact}', [
            'contact' => 42,
            'since'   => new \DateTimeImmutable('2026-11-13 08:00:00', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame('Log record', $panel->getTitle());
        $this->assertSame([
            'message' => 'Sync failed for {contact}',
            'channel' => 'crm',
            'level'   => 'CRITICAL',
            'context' => ['contact' => 42, 'since' => '2026-11-13T08:00:00+00:00'],
            'extra'   => ['hostname' => 'web-1'],
        ], $panel->getData());
    }

    public function testStackTraceOfLoggedErrorHasNoArguments(): void
    {
        $previous  = ini_set('zend.exception_ignore_args', '0');
        $exception = null;

        try {
            $exceptionMailer = $this->createMock(ExceptionMailer::class);
            $exceptionMailer->expects($this->once())->method('sendExceptionWithPanels')->willReturnCallback(function (\Throwable $error) use (&$exception) {
                $exception = $error;
            });

            (new Logger('crm', [new EmailErrorsHandler($exceptionMailer)]))->critical('Sync down', ['api_secret' => 'not-a-real-secret']);
            $settingAfter = ini_get('zend.exception_ignore_args');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        $this->assertInstanceOf(LoggedError::class, $exception);
        $this->assertStringNotContainsString('not-a-real-secret', serialize(array_column($exception->getTrace(), 'args')));
        $this->assertContains(__FUNCTION__, array_column($exception->getTrace(), 'function'), 'The trace still shows where the record was logged');
        $this->assertSame('0', $settingAfter, 'The setting is restored');
    }

    public function testExceptionOfTheRecordIsNotRepeatedInItsContext(): void
    {
        $panel = null;

        (new Logger('crm', [new EmailErrorsHandler($this->capturingMailer($panel))]))
            ->critical('Something failed', ['exception' => new \LogicException('Boom'), 'contact' => 42]);

        $this->assertSame(['contact' => 42], $panel->getData()['context']);
        $this->assertSame('Something failed', $panel->getData()['message'], 'The log message, which the email would not show otherwise');
    }

    public function testExceptionKeyThatIsNotAThrowableStaysInTheContext(): void
    {
        $panel = null;

        (new Logger('crm', [new EmailErrorsHandler($this->capturingMailer($panel))]))->critical('Sending failed', ['exception' => 'SMTP down']);

        $this->assertSame(['exception' => 'SMTP down'], $panel->getData()['context']);
    }

    public function testRecordBelowItsLevelIsNotSent(): void
    {
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->never())->method('sendExceptionWithPanels');

        $logger = new Logger('crm', [new EmailErrorsHandler($exceptionMailer)]);
        $logger->error('Only logged');
        $logger->warning('Only logged');
    }

    public function testLevelIsConfigurable(): void
    {
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->once())->method('sendExceptionWithPanels');

        (new Logger('crm', [new EmailErrorsHandler($exceptionMailer, null, 'error')]))->error('Sent');
    }

    public function testNothingIsSentWhenTheBundleIsDisabled(): void
    {
        (new Logger('crm', [new EmailErrorsHandler(null)]))->critical('Sync down');

        $this->addToAssertionCount(1);
    }

    /**
     * A mocked mailer storing in $panel the single panel it receives.
     */
    private function capturingMailer(?DataPanel &$panel): ExceptionMailer
    {
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->once())->method('sendExceptionWithPanels')
            ->willReturnCallback(function (\Throwable $exception, array $panels) use (&$panel) {
                $this->assertCount(1, $panels);
                $panel = $panels[0];
            });

        return $exceptionMailer;
    }
}
