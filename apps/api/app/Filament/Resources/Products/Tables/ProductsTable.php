<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Models\Brand;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['media' => fn ($q) => $q->with('derivatives')]))
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->getStateUsing(fn (Product $record): ?string => $record->media->first()?->previewUrl())
                    ->height(40),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->description(fn (Product $record): ?string => $record->mpn),

                TextColumn::make('brand.name')->label('Brand')->searchable()->sortable()->toggleable(),

                TextColumn::make('primaryCategory.path')
                    ->label('Category')
                    ->toggleable()
                    ->limit(40),

                TextColumn::make('sku')->label('SKU')->searchable()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'draft' => 'gray',
                        'archived' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('availability')->badge()->toggleable()->sortable(),

                IconColumn::make('is_indexable')->label('Indexed')->boolean()->sortable(),

                TextColumn::make('source')->badge()->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived',
                ])->multiple(),

                SelectFilter::make('brand')->relationship('brand', 'name')->searchable()->preload(),

                SelectFilter::make('primary_category_id')
                    ->label('Category')
                    ->relationship('primaryCategory', 'path')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('availability')->options([
                    'in_stock' => 'In stock',
                    'limited' => 'Limited',
                    'made_to_order' => 'Made to order',
                    'lead_time' => 'Lead time',
                    'discontinued' => 'Discontinued',
                ])->multiple(),

                SelectFilter::make('pricing_mode')->label('Pricing')->options([
                    'quote_only' => 'Quote only',
                    'public' => 'Public price',
                ]),

                SelectFilter::make('source')->options([
                    'icecat' => 'Icecat',
                    'woocommerce' => 'WooCommerce',
                    'csv' => 'CSV import',
                    'manual' => 'Manual',
                ]),

                TernaryFilter::make('is_indexable')->label('Indexable'),

                TrashedFilter::make(),

                Filter::make('no_images')
                    ->label('No images')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('media')),

                /*
                 * The thin-content queue (MASTER BUILD PROMPT section 14.7): live
                 * but too thin to index, so the highest-value ones are found first.
                 */
                Filter::make('thin_content')
                    ->label('Thin content - needs a description')
                    ->query(fn (Builder $query): Builder => $query->whereRaw(
                        "array_length(regexp_split_to_array(trim(coalesce(regexp_replace(description_html, '<[^>]+>', ' ', 'g'), '')), '\\s+'), 1) < ?",
                        [Product::CONTENT_MINIMUM_WORDS]
                    )),

                Filter::make('live_but_hidden')
                    ->label('Live but not indexable')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'active')
                        ->where('is_indexable', false)),

                Filter::make('missing_mpn')
                    ->label('No manufacturer part number')
                    ->query(fn (Builder $query): Builder => $query->whereNull('mpn')),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Created from'),
                        DatePicker::make('until')->label('Created until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if ($data['from'] ?? null) {
                            $out[] = 'Created from '.$data['from'];
                        }
                        if ($data['until'] ?? null) {
                            $out[] = 'Created until '.$data['until'];
                        }

                        return $out;
                    }),
            ])
            ->filtersFormColumns(3)
            ->persistFiltersInSession()
            ->recordActions([
                Action::make('viewOnSite')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Product $record): string => $record->url())
                    ->openUrlInNewTab()
                    ->visible(fn (Product $record): bool => $record->status === 'active'),
                EditAction::make(),
                ReplicateAction::make()
                    ->excludeAttributes(['uuid', 'slug', 'icecat_id', 'sku', 'ean', 'published_at'])
                    ->beforeReplicaSaved(function (Product $replica): void {
                        $replica->status = 'draft';
                        $replica->is_indexable = false;
                        $replica->name = $replica->name.' (copy)';
                        $replica->slug = $replica->slug.'-copy-'.uniqid();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setStatus')
                        ->label('Set status')
                        ->icon('heroicon-o-flag')
                        ->schema([
                            Select::make('status')->options([
                                'draft' => 'Draft',
                                'active' => 'Active',
                                'archived' => 'Archived',
                            ])->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $ids = $records->pluck('id');
                            Product::query()->whereKey($ids)->update(['status' => $data['status']]);
                            if ($data['status'] === 'active') {
                                Product::query()->whereKey($ids)->whereNull('published_at')->update(['published_at' => now()]);
                            }
                            self::done(count($ids), 'status updated');
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setIndexable')
                        ->label('Indexing')
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            Select::make('is_indexable')->label('Search indexing')->options([
                                1 => 'Allow Google to index',
                                0 => 'Do not index',
                            ])->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            Product::query()->whereKey($records->pluck('id'))->update(['is_indexable' => (bool) $data['is_indexable']]);
                            self::done($records->count(), 'indexing updated');
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('assignBrand')
                        ->label('Assign brand')
                        ->icon('heroicon-o-tag')
                        ->schema([
                            Select::make('brand_id')->label('Brand')
                                ->relationship('brand', 'name')->searchable()->preload()->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $brand = Brand::find($data['brand_id']);
                            if ($brand === null) {
                                return;
                            }
                            Product::query()->whereKey($records->pluck('id'))
                                ->update(['brand_id' => $brand->id, 'brand_name_cached' => $brand->name]);
                            self::done($records->count(), 'brand assigned');
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('addToCategory')
                        ->label('Add to category')
                        ->icon('heroicon-o-rectangle-stack')
                        ->schema([
                            Select::make('category_id')->label('Category')
                                ->relationship('primaryCategory', 'path')->searchable()->preload()->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $rows = $records->pluck('id')
                                ->map(fn ($id): array => ['product_id' => $id, 'category_id' => (int) $data['category_id'], 'sort_order' => 0])
                                ->all();
                            DB::table('product_category')->insertOrIgnore($rows);
                            self::done($records->count(), 'added to the category');
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private static function done(int $count, string $what): void
    {
        Notification::make()->title("{$count} product(s) {$what}.")->success()->send();
    }
}
