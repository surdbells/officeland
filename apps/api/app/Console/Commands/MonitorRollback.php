<?php

declare(strict_types=1);

namespace App\Console\Commands;

use RuntimeException;

/**
 * Carries the deliberate rollback out of the monitor's transaction closure.
 *
 * A distinct type rather than a generic exception, so the catch cannot
 * accidentally swallow a real failure from inside the pipeline — which would
 * turn the monitor into something that reports success no matter what.
 */
final class MonitorRollback extends RuntimeException {}
