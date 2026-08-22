<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\QuoteConfirmationMail;
use App\Mail\QuoteReceivedMail;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteBasket;
use App\Quotes\QuoteSubmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The synthetic quote monitor (§13e).
 *
 * The monitor exists because the lead pipeline fails **silently** — a broken
 * storefront is reported in minutes, a pipeline that stopped writing rows looks
 * exactly like a quiet week. These tests cover the three ways the monitor could
 * betray that purpose: by reporting success when the pipeline is broken, by
 * leaving synthetic leads in the queue, or by emailing the sales team every
 * fifteen minutes.
 */
final class QuotePipelineMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function catalogue(): Product
    {
        return Product::factory()->create([
            'status' => 'active',
            'min_order_qty' => 1,
            'qty_step' => 1,
        ]);
    }

    public function test_it_reports_a_healthy_pipeline(): void
    {
        $this->catalogue();

        $this->artisan('monitor:quote')
            ->assertSuccessful()
            ->expectsOutputToContain('Quote pipeline healthy');
    }

    /**
     * The property that keeps the lead queue trustworthy. A synthetic quote
     * arriving every 15 minutes would train the sales team to ignore the
     * queue, destroying what Phase 11 was built to protect.
     */
    public function test_it_leaves_nothing_behind(): void
    {
        $this->catalogue();

        $this->artisan('monitor:quote')->assertSuccessful();

        $this->assertSame(0, Quote::query()->count());
        $this->assertSame(0, QuoteBasket::query()->count());
    }

    /**
     * The reference sequence is not burned either. D-038 makes references
     * gapless per year, and a monitor running every 15 minutes would consume
     * 35,000 of them annually if the counter survived the rollback.
     */
    public function test_it_does_not_consume_reference_numbers(): void
    {
        $this->catalogue();

        $this->artisan('monitor:quote')->assertSuccessful();

        $this->assertSame(0, DB::table('quote_reference_counters')->count());
    }

    /**
     * The seam this depends on: the submitter writes, the CONTROLLER mails.
     * If somebody moves the mailer inside `submit()`, the monitor silently
     * starts notifying sales four times an hour — so this asserts the absence
     * rather than trusting the current shape of the code.
     */
    public function test_it_sends_no_mail(): void
    {
        Mail::fake();
        Queue::fake();

        $this->catalogue();

        $this->artisan('monitor:quote')->assertSuccessful();

        Mail::assertNothingSent();
        Mail::assertNotQueued(QuoteReceivedMail::class);
        Mail::assertNotQueued(QuoteConfirmationMail::class);
    }

    /**
     * A monitor that cannot fail is decoration. When the pipeline throws, the
     * command must exit non-zero — that is the signal the scheduler turns into
     * an alert.
     */
    public function test_it_fails_loudly_when_the_pipeline_is_broken(): void
    {
        $this->catalogue();
        $this->breakThePipeline();

        $this->artisan('monitor:quote')
            ->assertFailed()
            ->expectsOutputToContain('QUOTE PIPELINE BROKEN');
    }

    /**
     * `critical` and not `error`: this is the level that should page somebody.
     * Every minute the pipeline stays broken is leads arriving nowhere.
     */
    public function test_a_failure_is_logged_at_critical(): void
    {
        $this->catalogue();

        $levels = [];
        Log::listen(function ($message) use (&$levels): void {
            $levels[$message->message] = $message->level;
        });

        $this->breakThePipeline();

        $this->artisan('monitor:quote')->assertFailed();

        $this->assertSame('critical', $levels['monitor.quote.failed'] ?? null);
    }

    /**
     * Breaks the pipeline for real rather than with a mock.
     *
     * `QuoteSubmitter` is final and cannot be mocked, which turned out to be
     * the better outcome: removing the reference counter is an actual failure
     * mode of this system (D-038 allocates from that table), so this exercises
     * the monitor against a breakage it might genuinely meet instead of
     * against a stub that throws on cue.
     *
     * Postgres DDL is transactional, so RefreshDatabase rolls the drop back.
     */
    private function breakThePipeline(): void
    {
        DB::statement('drop table quote_reference_counters cascade');
    }

    /**
     * An empty catalogue means the monitor cannot exercise anything. Reporting
     * success in that state would be a monitor that goes green precisely when
     * it has stopped checking.
     */
    public function test_an_empty_catalogue_is_a_failure_not_a_pass(): void
    {
        $this->artisan('monitor:quote')
            ->assertFailed()
            ->expectsOutputToContain('the monitor cannot run');
    }

    /**
     * --keep exists for debugging a failure by hand. It must actually commit,
     * or the debugging session has nothing to look at.
     */
    public function test_keep_commits_the_synthetic_quote(): void
    {
        $this->catalogue();

        $this->artisan('monitor:quote', ['--keep' => true])->assertSuccessful();

        $this->assertSame(1, Quote::query()->count());
        $this->assertSame('pipeline-monitor@example.invalid', Quote::query()->first()?->email);
    }
}
