<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suppression list (§10d).
 *
 * An address that hard-bounced or complained must not be written to again.
 * Continuing to send damages the sending domain's reputation, and reputation
 * is shared across every message the business sends — so one ignored bounce
 * eventually costs delivery of a quote to somebody else entirely.
 *
 * Kept in our own database rather than relying on the provider's list, because
 * this is the copy the admin panel shows and the copy that survives changing
 * provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();

            // Stored lower-cased and unique: suppression is a property of the
            // mailbox, and Buyer@example.com is the same mailbox as
            // buyer@example.com for every provider that matters.
            $table->string('email')->unique();

            $table->string('reason', 32);
            $table->text('detail')->nullable();

            // The provider's own identifiers, so a disputed suppression can be
            // traced back to the exact event that caused it.
            $table->string('provider_event_id')->nullable();
            $table->string('provider_request_id')->nullable();

            $table->timestamp('suppressed_at')->useCurrent();

            /*
             * Releasable, but only deliberately. A customer who fixes their
             * mailbox and asks us to try again is a real case, and it must be
             * an explicit act with an actor attached rather than an automatic
             * expiry that quietly resumes sending to a dead address.
             */
            $table->timestamp('released_at')->nullable();
            $table->string('released_by')->nullable();

            $table->timestamps();

            $table->index(['reason', 'suppressed_at']);
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
