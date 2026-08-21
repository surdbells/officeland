@php
    $totals = $this->getTotals();
    $categoryQueue = $this->getCategoryQueue();
    $productQueue = $this->getProductQueue();
    $flagged = $this->getFlagged();
@endphp

<x-filament-panels::page>
    {{-- The four numbers that decide whether launch is a scheduling problem. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Categories without an intro', $totals['categories_short'], $totals['categories_total'], 'These block whole subtrees'],
            ['Drafts awaiting a person', $totals['products_awaiting_approval'], $totals['products_total'], 'Generated, never read'],
            ['Flagged as near-duplicate', $totals['products_flagged'], $totals['products_total'], 'Approve or rewrite'],
            ['Products indexable', $totals['products_indexable'], $totals['products_total'], 'Cleared every minimum'],
        ] as [$label, $value, $outOf, $hint])
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ number_format($value) }}<span class="text-base font-normal text-gray-400"> / {{ number_format($outOf) }}</span>
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    {{-- Categories first: 124 intros are a week, and they unlock the taxonomy. --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6">
            <h3 class="text-base font-semibold">Write these category intros first</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                150–300 words of genuine buying context. A category cannot be indexed without one.
            </p>

            @if ($categoryQueue === [])
                <p class="mt-4 text-sm text-success-600">Every category has an intro.</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100 text-sm dark:divide-white/10">
                    @foreach ($categoryQueue as $row)
                        <li class="flex items-baseline justify-between gap-4 py-2">
                            <a
                                href="{{ \App\Filament\Resources\Categories\CategoryResource::getUrl('edit', ['record' => $row['category']]) }}"
                                class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >{{ $row['category']->name }}</a>
                            <span class="shrink-0 font-mono text-xs text-gray-400">{{ $row['category']->path }}</span>
                            <span class="shrink-0 text-warning-600">{{ $row['readiness'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- Near-duplicates: the §14.3 defect, caught. --}}
    @if ($flagged !== [])
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="p-6">
                <h3 class="text-base font-semibold text-danger-600">Reads like another product</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Compared within the product's own family, with the name and codes removed first —
                    so a high score here means the prose really is the same, not that the products are similar.
                    Genuine variants do happen: approve them.
                </p>

                <ul class="mt-4 divide-y divide-gray-100 text-sm dark:divide-white/10">
                    @foreach ($flagged as $product)
                        <li class="flex items-baseline justify-between gap-4 py-2">
                            <a
                                href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product]) }}"
                                class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >{{ $product->name }}</a>
                            <span class="text-gray-500">
                                like {{ $product->descriptionSimilarTo?->name ?? 'another product' }}
                            </span>
                            <span class="shrink-0 font-mono tabular-nums text-danger-600">
                                {{ number_format((float) $product->description_similarity * 100, 1) }}%
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="p-6">
            <h3 class="text-base font-semibold">Products that cannot be indexed yet</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Active products only — a draft product is withheld from Google by being a draft, not by its description.
            </p>

            @if ($productQueue === [])
                <p class="mt-4 text-sm text-success-600">Every active product clears the content floor.</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100 text-sm dark:divide-white/10">
                    @foreach ($productQueue as $row)
                        <li class="flex items-baseline justify-between gap-4 py-2">
                            <a
                                href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $row['product']]) }}"
                                class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >{{ $row['product']->name }}</a>
                            <span class="shrink-0 text-warning-600">{{ $row['readiness'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-filament-panels::page>
