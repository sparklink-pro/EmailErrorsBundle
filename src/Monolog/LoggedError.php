<?php

declare(strict_types=1);

namespace Sparklink\EmailErrorsBundle\Monolog;

/**
 * A log record without exception, sent by EmailErrorsHandler: its stack trace shows where it was logged.
 */
final class LoggedError extends \RuntimeException
{
}
