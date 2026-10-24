@php
    $totals = $this->getTotals();
    $categoryQueue = $this->getCategoryQueue();
    $productQueue = $this->getProductQueue();
    $flagged = $this->getFlagged();

    // Inline styles, not Tailwind utilities: Filament compiles its own CSS and
    // purges classes it does not find in its own files, so utilities written in
    // a custom page view (grid-cols, ring, rounded-xl) produce no styles and the
    // page renders unstyled. Filament's own components (section, link, badge) are
    // themed and dark-mode aware; everything else is inline so it always applies.
    $muted = 'color:currentColor;opacity:.6;';
    $row = 'display:flex;align-items:baseline;justify-content:space-between;gap:1rem;padding:.6rem 0;border-top:1px solid rgba(120,120,120,.18);';
@endphp

<x-filament-panels::page>
    {{-- The four numbers that decide whether launch is a scheduling problem. --}}
    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));">
        @foreach ([
            ['Categories without an intro', $totals['categories_short'], $totals['categories_total'], 'These block whole subtrees'],
            ['Drafts awaiting a person', $totals['products_awaiting_approval'], $totals['products_total'], 'Generated, never read'],
            ['Flagged as near-duplicate', $totals['products_flagged'], $totals['products_total'], 'Approve or rewrite'],
            ['Products indexable', $totals['products_indexable'], $totals['products_total'], 'Cleared every minimum'],
        ] as [$label, $value, $outOf, $hint])
            <x-filament::section>
                <div style="{{ $muted }}font-size:.8125rem;">{{ $label }}</div>
                <div style="margin-top:.25rem;font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums;">
                    {{ number_format($value) }}<span style="{{ $muted }}font-size:1rem;font-weight:400;"> / {{ number_format($outOf) }}</span>
                </div>
                <div style="{{ $muted }}margin-top:.25rem;font-size:.75rem;">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- Categories first: the intros are a week's work and unlock the taxonomy. --}}
    <x-filament::section>
        <x-slot name="heading">Write these category intros first</x-slot>
        <x-slot name="description">150–300 words of genuine buying context. A category cannot be indexed without one.</x-slot>

        @if ($categoryQueue === [])
            <x-filament::badge color="success">Every category has an intro.</x-filament::badge>
        @else
            <ul style="display:flex;flex-direction:column;">
                @foreach ($categoryQueue as $item)
                    <li style="{{ $row }}">
                        <x-filament::link :href="\App\Filament\Resources\Categories\CategoryResource::getUrl('edit', ['record' => $item['category']])">
                            {{ $item['category']->name }}
                        </x-filament::link>
                        <span style="{{ $muted }}flex:1;font-family:ui-monospace,monospace;font-size:.75rem;text-align:right;">{{ $item['category']->path }}</span>
                        <x-filament::badge color="warning">{{ $item['readiness'] }}</x-filament::badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    {{-- Near-duplicates: the similarity gate's catch. --}}
    @if ($flagged !== [])
        <x-filament::section>
            <x-slot name="heading">Reads like another product</x-slot>
            <x-slot name="description">Compared within the product's own family, with the name and codes removed first, so a high score means the prose really is the same. Genuine variants happen — approve them.</x-slot>

            <ul style="display:flex;flex-direction:column;">
                @foreach ($flagged as $product)
                    <li style="{{ $row }}">
                        <x-filament::link :href="\App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product])">
                            {{ $product->name }}
                        </x-filament::link>
                        <span style="{{ $muted }}flex:1;text-align:right;font-size:.8125rem;">like {{ $product->descriptionSimilarTo?->name ?? 'another product' }}</span>
                        <x-filament::badge color="danger">{{ number_format((float) $product->description_similarity * 100, 1) }}%</x-filament::badge>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- Products blocked from indexing, most valuable first. --}}
    <x-filament::section>
        <x-slot name="heading">Products that cannot be indexed yet</x-slot>
        <x-slot name="description">Active products only — a draft is withheld from Google by being a draft, not by its description.</x-slot>

        @if ($productQueue === [])
            <x-filament::badge color="success">Every active product clears the content floor.</x-filament::badge>
        @else
            <ul style="display:flex;flex-direction:column;">
                @foreach ($productQueue as $item)
                    <li style="{{ $row }}">
                        <x-filament::link :href="\App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $item['product']])">
                            {{ $item['product']->name }}
                        </x-filament::link>
                        <x-filament::badge color="warning">{{ $item['readiness'] }}</x-filament::badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
