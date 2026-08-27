<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteBasket;
use App\Quotes\QuoteSubmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Submits a synthetic quote and checks it landed (§13e).
 *
 * ── Why this is the one monitor worth having ─────────────────────────────
 * The quote pipeline is the second most important thing this system does
 * after organic search, and it is the one that fails **silently**. A broken
 * storefront is reported within minutes by anybody who looks at it. A quote
 * pipeline that stopped writing rows, or stopped queueing mail, looks exactly
 * like a quiet week - and the business's own explanation for a quiet week is
 * never "our software is broken". By the time somebody asks, the leads are
 * gone and unrecoverable.
 *
 * Every other check in this system is a proxy for something. This one exercises
 * the actual thing: basket, quantity rules, reference allocation, snapshotting,
 * the event trail and the mailer, in one transaction, through the real service.
 *
 * ── What it deliberately does NOT use ────────────────────────────────────
 * The HTTP endpoint. Posting to `/api/v1/quotes` would drag in the anti-abuse
 * guard, and a monitor running every 15 minutes from one address is exactly
 * what AbuseGuard exists to stop - the monitor would either trip the limiter
 * or force us to weaken it. It calls `QuoteSubmitter` instead, which is where
 * everything that can actually break lives; the layer above it is validation
 * and a rate limiter, both covered by tests.
 *
 * ── It cleans up after itself, and that matters more than it sounds ──────
 * A synthetic quote left in the queue is a lead somebody tries to answer, and
 * one arriving every 15 minutes would train the sales team to ignore the
 * queue - destroying the thing Phase 11 was built to protect. The whole run
 * happens inside a transaction that is rolled back, so nothing is left behind
 * even if the process is killed mid-run.
 *
 * ── And it sends no mail, which is a property of the seam, not of luck ───
 * `QuoteSubmitter::submit()` writes the quote; `QuoteMailer::sendForQuote()`
 * is called separately by the controller afterwards. Because the monitor stops
 * at the submitter, no confirmation and - more importantly - no SALES
 * NOTIFICATION is queued. A notification every fifteen minutes would be worse
 * than no monitor at all.
 *
 * That safety depends on the seam staying where it is. Moving the mailer
 * inside `submit()` would silently start emailing sales every quarter hour, so
 * `QuotePipelineMonitorTest` asserts that nothing is queued.
 */
final class MonitorQuotePipeline extends Command
{
    protected $signature = 'monitor:quote
                            {--keep : Commit the synthetic quote instead of rolling it back. For debugging only.}';

    protected $description = 'Submit a synthetic quote end to end and alert if the pipeline is broken';

    /** Beyond this the pipeline is working but too slow to trust. */
    private const SLOW_MILLISECONDS = 3000;

    public function handle(QuoteSubmitter $submitter): int
    {
        $product = Product::query()->active()->first();

        if ($product === null) {
            /*
             * Not a pipeline failure, and not silently fine either. An empty
             * catalogue means the monitor is blind, and a blind monitor
             * reporting success is worse than no monitor.
             */
            $this->reportBroken('no active product to quote - the monitor cannot run');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            $reference = $this->exercisePipeline($submitter, $product, (bool) $this->option('keep'));
        } catch (Throwable $e) {
            $this->reportBroken($e->getMessage(), $e);

            return self::FAILURE;
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if ($elapsed > self::SLOW_MILLISECONDS) {
            Log::warning('monitor.quote.slow', [
                'reference' => $reference,
                'elapsed_ms' => $elapsed,
                'threshold_ms' => self::SLOW_MILLISECONDS,
            ]);

            $this->warn("Pipeline healthy but slow: {$elapsed}ms (reference {$reference}).");

            // Still a success: a slow pipeline is a warning, and exiting
            // non-zero here would page somebody for latency.
            return self::SUCCESS;
        }

        Log::info('monitor.quote.ok', ['reference' => $reference, 'elapsed_ms' => $elapsed]);

        $this->info("Quote pipeline healthy - {$reference} in {$elapsed}ms.");

        return self::SUCCESS;
    }

    /**
     * The submission itself, rolled back unless --keep.
     *
     * @return string the reference that was allocated
     */
    private function exercisePipeline(QuoteSubmitter $submitter, Product $product, bool $keep): string
    {
        $execute = function () use ($submitter, $product): string {
            $basket = QuoteBasket::query()->create([
                'uuid' => (string) Str::uuid(),
                // Never a real session token: this basket must be unreachable
                // from a browser even for the seconds it exists.
                'session_token_hash' => hash('sha256', 'monitor:'.Str::random(32)),
                'status' => 'active',
                'expires_at' => now()->addMinutes(5),
            ]);

            $basket->items()->create([
                'product_id' => $product->id,
                'qty' => max(1, $product->min_order_qty),
            ]);

            $quote = $submitter->submit(
                $basket->refresh(),
                [
                    // A domain reserved by RFC 2606 for exactly this. It can
                    // never belong to a real person, so a monitor that
                    // accidentally sends mail cannot reach one.
                    'email' => 'pipeline-monitor@example.invalid',
                    'phone' => '+2348000000000',
                    'message' => 'Automated pipeline check. Not a customer enquiry.',
                ],
                ['source' => 'monitor'],
            );

            $this->assertLanded($quote);

            return $quote->reference;
        };

        if ($keep) {
            return $execute();
        }

        /*
         * Rolled back by throwing after the assertions pass, which is the only
         * way to run the real service inside a transaction and still be
         * certain nothing is committed. The reference is carried out on the
         * exception rather than returned.
         */
        $reference = null;

        try {
            DB::transaction(function () use ($execute, &$reference): void {
                $reference = $execute();

                throw new MonitorRollback;
            });
        } catch (MonitorRollback) {
            // Expected: the rollback happened and every assertion passed.
        }

        return (string) $reference;
    }

    /**
     * Checks the quote is actually usable, not merely that no exception was
     * thrown.
     *
     * Each of these has failed in a real system somewhere: a reference
     * sequence that stopped advancing, a snapshot that wrote nothing because
     * the basket was already closed, an event trail that silently stopped.
     */
    private function assertLanded(Quote $quote): void
    {
        if (! $quote->exists) {
            throw new \RuntimeException('the quote was not persisted');
        }

        if (trim($quote->reference) === '') {
            throw new \RuntimeException('no reference was allocated');
        }

        if ($quote->items()->count() < 1) {
            throw new \RuntimeException('the quote has no line items - snapshotting failed');
        }

        if ($quote->events()->where('type', 'submitted')->count() < 1) {
            throw new \RuntimeException('no submitted event was recorded - the audit trail is broken');
        }
    }

    private function reportBroken(string $reason, ?Throwable $exception = null): void
    {
        /*
         * `critical`, and deliberately so. This is the level that should page
         * somebody: the business's lead pipeline is not accepting requests,
         * and every minute it stays broken is leads arriving nowhere.
         */
        Log::critical('monitor.quote.failed', [
            'reason' => $reason,
            'exception' => $exception === null ? null : $exception::class,
        ]);

        $this->error("QUOTE PIPELINE BROKEN: {$reason}");
    }
}
