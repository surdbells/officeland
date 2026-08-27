<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Context;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;

/**
 * Emits structured JSON logs with the request trace_id attached.
 *
 * Applied as a Monolog "tap" from config/logging.php. Structured logging is a
 * requirement of MASTER BUILD PROMPT section 19.1 - line-oriented text logs are
 * not queryable, and correlating a failed quote email back to the request that
 * produced it is exactly the case this exists for.
 */
final class StructuredLogging
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            // Not every Monolog handler is formattable or processable -
            // Monolog\Handler\HandlerInterface guarantees neither. Calling
            // these unguarded throws at runtime the first time a channel is
            // configured with, for example, a NullHandler or a WhatFailureGroup
            // wrapper. PHPStan level 8 caught this before it shipped.
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(
                    batchMode: JsonFormatter::BATCH_MODE_JSON,
                    appendNewline: true,
                ));
            }

            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(static function (LogRecord $record): LogRecord {
                    $traceId = Context::get('trace_id');

                    return $record->with(extra: [
                        ...$record->extra,
                        'trace_id' => is_string($traceId) ? $traceId : null,
                        'service' => 'api',
                        'env' => config('app.env'),
                    ]);
                });
            }
        }
    }
}
