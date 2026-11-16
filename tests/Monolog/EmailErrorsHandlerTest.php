<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Tests\Monolog;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Sparklink\EmailErrorsBundle\ExceptionMailer\ExceptionMailer;
use Sparklink\EmailErrorsBundle\Monolog\EmailErrorsHandler;
use Sparklink\EmailErrorsBundle\Monolog\LoggedError;

final class EmailErrorsHandlerTest extends TestCase
{
    public function testRecordWithoutExceptionIsSentWithItsMessage(): void
    {
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->once())->method('sendException')
            ->with($this->callback(fn ($exception) => $exception instanceof LoggedError && 'Sync down' === $exception->getMessage()));

        (new Logger('crm', [new EmailErrorsHandler($exceptionMailer)]))->critical('Sync down');
    }

    public function testExceptionOfTheRecordIsSent(): void
    {
        $error           = new \LogicException('Boom');
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->once())->method('sendException')->with($error);

        (new Logger('crm', [new EmailErrorsHandler($exceptionMailer)]))->critical('Something failed', ['exception' => $error]);
    }

    public function testRecordBelowItsLevelIsNotSent(): void
    {
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->never())->method('sendException');

        $logger = new Logger('crm', [new EmailErrorsHandler($exceptionMailer)]);
        $logger->error('Only logged');
        $logger->warning('Only logged');
    }

    public function testLevelIsConfigurable(): void
    {
        $exceptionMailer = $this->createMock(ExceptionMailer::class);
        $exceptionMailer->expects($this->once())->method('sendException');

        (new Logger('crm', [new EmailErrorsHandler($exceptionMailer, null, 'error')]))->error('Sent');
    }

    public function testNothingIsSentWhenTheBundleIsDisabled(): void
    {
        (new Logger('crm', [new EmailErrorsHandler(null)]))->critical('Sync down');

        $this->addToAssertionCount(1);
    }
}
