<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\DescriptionDrafter;
use App\Content\SimilarityGate;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Drafts product descriptions from attributes, then checks them (§12b).
 *
 * ── This does not produce publishable copy, by design ────────────────────
 * Everything it writes is stamped as generated, and a generated description
 * cannot be indexed until a person approves it. The point is to get 15,000
 * products to a first draft so the editorial work is editing rather than
 * writing - not to skip the editing.
 *
 * ── Dry run by default ───────────────────────────────────────────────────
 * Same reasoning as `media:reap`: this writes to every product in the
 * catalogue, and the report on its own answers the question that actually
 * matters before launch - *how many products have enough structured data to
 * support a description at all?* That number decides whether Q-B is a
 * scheduling problem or a data problem, and it is worth knowing before
 * anything is written.
 *
 * ── Resumable and chunked ────────────────────────────────────────────────
 * 15,000 products with a family lookup each is not a single-transaction job.
 * It chunks by id and skips anything already drafted, so an interrupted run is
 * resumed by running it again.
 */
final class DraftDescriptions extends Command
{
    protected $signature = 'content:draft
                            {--apply : Actually write drafts. Without this the command only reports.}
                            {--limit=0 : Stop after this many products. 0 means all.}
                            {--chunk=200 : Products per pass.}
                            {--category= : Restrict to one primary category id.}';

    protected $description = 'Draft product descriptions from structured attributes, and flag near-duplicates';

    public function handle(DescriptionDrafter $drafter, SimilarityGate $gate): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $category = $this->option('category');

        $drafted = 0;
        $skipped = 0;
        $insufficient = 0;
        $flagged = 0;
        $seen = 0;

        $query = Product::query()
            ->with(['attributeValues.attribute', 'brand', 'primaryCategory'])
            ->when($category !== null, fn ($q) => $q->where('primary_category_id', (int) $category))
            ->orderBy('id');

        $query->chunkById((int) $this->option('chunk'), function ($products) use (
            $drafter, $gate, $apply, $limit,
            &$drafted, &$skipped, &$insufficient, &$flagged, &$seen
        ): bool {
            foreach ($products as $product) {
                if ($limit > 0 && $seen >= $limit) {
                    return false;
                }

                $seen++;

                $body = $apply ? $drafter->draft($product) : $drafter->compose($product);

                if ($body === null) {
                    /*
                     * Two different outcomes share this branch, and the
                     * difference matters to whoever reads the report: a
                     * product with human copy is DONE, one without enough
                     * attributes is BLOCKED on data rather than on writing.
                     */
                    if (trim(strip_tags((string) $product->description_html)) !== '') {
                        $skipped++;
                    } else {
                        $insufficient++;
                    }

                    continue;
                }

                $drafted++;

                if ($apply && $gate->check($product) !== null) {
                    $flagged++;
                }
            }

            return true;
        });

        $this->table(
            ['Outcome', 'Products'],
            [
                [$apply ? 'Drafted' : 'Would draft', $drafted],
                ['Already written - left alone', $skipped],
                ['Not enough structured data to describe', $insufficient],
                ['Flagged as near-duplicate', $apply ? $flagged : '-'],
                ['Seen', $seen],
            ],
        );

        if (! $apply) {
            $this->comment('Dry run. Nothing was written. Add --apply to write drafts.');
        }

        if ($insufficient > 0) {
            $this->warn(sprintf(
                '%d product(s) lack the attributes needed for a description. Those need DATA, not copy - '.
                'a description written without specifications is the boilerplate §14.3 forbids.',
                $insufficient,
            ));
        }

        if ($drafted > 0 && $apply) {
            $this->warn(sprintf(
                '%d draft(s) written. None of them can be indexed until a person approves each one '.
                '(Catalogue → Content readiness).',
                $drafted,
            ));
        }

        return self::SUCCESS;
    }
}
