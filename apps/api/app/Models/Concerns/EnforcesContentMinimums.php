<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Content\ContentMinimums;
use App\Content\Readiness;
use RuntimeException;

/**
 * Refuses to let a page become indexable while it is below the content floor.
 *
 * ── Why the model and not the form ───────────────────────────────────────
 * The Phase 12 gate is *no indexable page below the content minimums*. Three
 * routes write `is_indexable` and only one of them is a form: the Filament
 * editor, the CSV importer, and any future Icecat sync. A form validator sees
 * one of the three, and the two it does not see are the bulk ones - which are
 * exactly how 15,000 thin pages would enter the sitemap in a single afternoon.
 *
 * This is the same reasoning that puts alt-text enforcement on the Media model
 * (Phase 4f) rather than on the upload form.
 *
 * ── Why it throws rather than silently correcting ────────────────────────
 * Quietly setting the flag back to false would leave an editor believing a page
 * is indexed when it is not, and the discrepancy would only surface weeks later
 * as traffic that never arrived. An exception is unpleasant exactly once, at
 * the moment somebody can still do something about it.
 *
 * ── What it deliberately does NOT do ─────────────────────────────────────
 * It does not un-index a page whose content is later trimmed below the floor.
 * Removing a live page from the sitemap as a side effect of an unrelated edit
 * is a bigger surprise than the thin page it prevents, and the readiness report
 * exists to surface those. The rule enforced here is only about the transition
 * INTO an indexable state.
 */
trait EnforcesContentMinimums
{
    public static function bootEnforcesContentMinimums(): void
    {
        static::saving(function (self $model): void {
            // Only the transition matters. An already-indexable page being
            // saved for an unrelated reason is left alone.
            if (! $model->is_indexable || ! $model->isDirty('is_indexable')) {
                return;
            }

            $readiness = $model->contentReadiness();

            if ($readiness->isReady()) {
                return;
            }

            throw new RuntimeException(sprintf(
                '%s cannot be indexed yet - %s.',
                class_basename($model),
                $readiness->summary(),
            ));
        });
    }

    public function contentReadiness(): Readiness
    {
        return app(ContentMinimums::class)->for($this);
    }

    public function meetsContentMinimums(): bool
    {
        return $this->contentReadiness()->isReady();
    }
}
