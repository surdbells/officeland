<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentMinimums;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drafts per brand, split by the FIRST reason finalise-products holds each one
 * back - the same checks in the same order (category, usable image, specs,
 * description length). Read-only, and one SQL pass, so it is quick even over
 * tens of thousands of drafts.
 *
 *   php artisan catalogue:draft-report
 */
final class DraftReport extends Command
{
    protected $signature = 'catalogue:draft-report';

    protected $description = 'Count drafts per brand by the reason they are not published';

    public function handle(): int
    {
        $minSpecs = ContentMinimums::PRODUCT_MIN_ATTRIBUTES;
        $minWords = ContentMinimums::PRODUCT_WORDS;

        $rows = DB::select(<<<SQL
            with d as (
                select
                    p.id,
                    coalesce(b.name, '(no brand)') as brand,
                    p.primary_category_id is null as no_category,
                    not exists (
                        select 1 from product_media pm
                        join media_derivatives md on md.media_id = pm.media_id
                        where pm.product_id = p.id
                    ) as no_image,
                    (select count(*) from product_attribute_values v where v.product_id = p.id) < {$minSpecs} as few_specs,
                    coalesce(array_length(regexp_split_to_array(
                        btrim(regexp_replace(coalesce(p.description_html, ''), '<[^>]+>', ' ', 'g')), '\\s+'
                    ), 1), 0) < {$minWords} as short_copy
                from products p
                left join brands b on b.id = p.brand_id
                where p.status = 'draft'
            )
            select
                brand,
                count(*) as drafts,
                count(*) filter (where no_category) as no_category,
                count(*) filter (where not no_category and no_image) as no_image,
                count(*) filter (where not no_category and not no_image and few_specs) as few_specs,
                count(*) filter (where not no_category and not no_image and not few_specs and short_copy) as short_copy,
                count(*) filter (where not (no_category or no_image or few_specs or short_copy)) as ready
            from d
            group by brand
            order by drafts desc
        SQL);

        $table = [];
        $sum = ['drafts' => 0, 'no_category' => 0, 'no_image' => 0, 'few_specs' => 0, 'short_copy' => 0, 'ready' => 0];

        foreach ($rows as $row) {
            $line = [$row->brand];
            foreach (array_keys($sum) as $key) {
                $sum[$key] += (int) $row->{$key};
                $line[] = number_format((int) $row->{$key});
            }
            $table[] = $line;
        }

        $table[] = ['TOTAL', ...array_map(static fn (int $n): string => number_format($n), array_values($sum))];

        $this->table(
            ['Brand', 'Drafts', 'No category', 'No usable image', "< {$minSpecs} specs", "< {$minWords} words", 'Ready (re-run finalise)'],
            $table,
        );

        $this->line('Each draft is counted once, under its first blocker. "Ready" ones pass the bar now and publish on the next finalise run.');

        return self::SUCCESS;
    }
}
