<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Sparklink\EmailErrorsBundle\ExceptionMailer\ExceptionMailer;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Monolog handler sending the records it receives (from its level, critical by default) by email, through the same
 * ExceptionMailer as the exceptions: same template, filters, redaction and throttling. The exception of the record
 * ("exception" in its context) is sent if it has one, else its message.
 * Without mailer (bundle disabled), it does nothing, so a Monolog configuration using it keeps working.
 */
class EmailErrorsHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected ?ExceptionMailer $exceptionMailer,
        protected ?RequestStack $requestStack = null,
        int|string|Level $level = Level::Critical,
    ) {
        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        if (null === $this->exceptionMailer) {
            return;
        }

        $exception = $record->context['exception'] ?? null;
        if (!$exception instanceof \Throwable) {
            $exception = new LoggedError($record->message);
        }

        $this->exceptionMailer->sendException($exception, $this->requestStack?->getCurrentRequest());
    }
}
