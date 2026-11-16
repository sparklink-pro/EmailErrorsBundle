<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Monolog;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Sparklink\EmailErrorsBundle\ExceptionMailer\ExceptionMailer;
use Sparklink\EmailErrorsBundle\Twig\DataPanel;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Monolog handler sending the records it receives (from its level, critical by default) by email, through the same
 * ExceptionMailer as the exceptions: same template, filters, redaction and throttling. The exception of the record
 * ("exception" in its context) is sent if it has one, else its message; a "Log record" panel shows the message,
 * channel, level, context and extra data of the record.
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

        $context = $record->context;
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            // Already in the email, with its stack trace
            unset($context['exception']);
        } else {
            $exception = $this->createLoggedError($record);
        }

        $this->exceptionMailer->sendExceptionWithPanels(
            $exception,
            [$this->getRecordPanel($record, $context)],
            $this->requestStack?->getCurrentRequest()
        );
    }

    /**
     * Created without the call arguments in its stack trace, whatever zend.exception_ignore_args says: the trace shows
     * where the record was logged, and its arguments would be the raw record (the Logger and handler calls), context
     * included, undoing the redaction of the "Log record" panel.
     */
    protected function createLoggedError(LogRecord $record): LoggedError
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '1');

        try {
            return new LoggedError($record->message);
        } finally {
            if (false !== $ignoreArgs) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }

    /**
     * The context and extra data are normalized as by Monolog's own formatters (objects, dates, resources...), so the
     * email template can display them. The context is not part of the throttling key: it often holds values that
     * change at each occurrence (ids, dates, durations).
     */
    protected function getRecordPanel(LogRecord $record, array $context): DataPanel
    {
        $normalizer = new NormalizerFormatter();

        $data = [
            'message' => $record->message,
            'channel' => $record->channel,
            'level' => $record->level->getName(),
        ];
        if ([] !== $context) {
            $data['context'] = $normalizer->normalizeValue($context);
        }
        if ([] !== $record->extra) {
            $data['extra'] = $normalizer->normalizeValue($record->extra);
        }

        return new DataPanel('Log record', $data);
    }
}
