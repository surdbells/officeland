<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Forms\SeoFields;
use App\Models\Category;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Category form.
 *
 * `path` and `depth` are derived, never typed. They are what the whole tree
 * depends on - a hand-edited path that disagrees with its parent silently
 * breaks subtree queries and every listing beneath it - so they are computed
 * from the parent and the slug and shown read-only.
 */
final class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->tabs([

                Tab::make('Category')->schema([
                    Section::make()->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Get $get, Set $set): void {
                                if ($operation !== 'create') {
                                    return;
                                }
                                $slug = Str::slug((string) $state);
                                $set('slug', $slug);
                                self::recompute($get, $set, $slug);
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->rules(['regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::recompute($get, $set, (string) $state)),

                        Select::make('parent_id')
                            ->label('Sits under')
                            ->relationship(
                                'parent',
                                'path',
                                // Depth 3 is the maximum, so a depth-3 category
                                // cannot be a parent (enforced by a CHECK
                                // constraint too - this just avoids the error).
                                fn ($query) => $query->where('depth', '<', Category::MAX_DEPTH)->orderBy('path'),
                            )
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('Leave empty for a top-level department. Maximum three levels.')
                            ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::recompute($get, $set, (string) $get('slug'))),

                        TextInput::make('path')
                            ->label('Web address')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Built from the parent and the slug. Not editable - the whole tree depends on it.'),

                        TextInput::make('depth')
                            ->numeric()
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Derived. Maximum 3.'),

                        TextInput::make('sort_order')
                            ->label('Position')
                            ->numeric()
                            ->default(0),
                    ])->columns(2),

                    Section::make('Buying guidance')
                        ->description('Genuinely useful context for someone choosing between the products below. Required before this page can be indexed.')
                        ->schema([
                            Textarea::make('intro_copy')
                                ->label('Intro copy')
                                ->rows(6)
                                ->live(onBlur: true)
                                ->helperText(fn (?string $state): HtmlString => self::introCounter($state))
                                ->columnSpanFull(),

                            RichEditor::make('description_html')
                                ->label('Longer copy (shown below the product grid)')
                                ->columnSpanFull(),
                        ]),

                    Section::make('Hero image')
                        ->description('Use the "Set hero image" button at the top of the page to upload or replace it.')
                        ->schema([
                            Placeholder::make('hero_preview')
                                ->hiddenLabel()
                                ->content(fn (?Category $record): HtmlString => self::heroPreview($record)),
                        ]),
                ]),

                Tab::make('Search appearance')->schema(SeoFields::make('/c')),
            ])->columnSpanFull(),
        ]);
    }

    /** Keeps path and depth consistent with the parent. */
    private static function recompute(Get $get, Set $set, string $slug): void
    {
        if ($slug === '') {
            return;
        }

        $parentId = $get('parent_id');

        if ($parentId === null || $parentId === '') {
            $set('path', '/'.$slug.'/');
            $set('depth', 1);

            return;
        }

        $parent = Category::query()->whereKey($parentId)->first();

        if (! $parent instanceof Category) {
            return;
        }

        $set('path', $parent->path.$slug.'/');
        $set('depth', $parent->depth + 1);
    }

    /** Renders the current hero image, or a hint when none is set. */
    private static function heroPreview(?Category $record): HtmlString
    {
        $media = $record?->heroMedia;

        if ($media === null) {
            return new HtmlString('<span style="color:#6b7280">No hero image set yet.</span>');
        }

        return new HtmlString(
            '<img src="'.e($media->previewUrl()).'" alt="" '
            .'style="max-height:140px;max-width:320px;object-fit:cover;border:1px solid #e5e7eb;border-radius:6px">'
        );
    }

    private static function introCounter(?string $state): HtmlString
    {
        $words = str_word_count(strip_tags((string) $state));

        if ($words >= 150) {
            return new HtmlString('<span style="color:#15803d;font-weight:600">'.$words.' words - long enough to index.</span>');
        }

        return new HtmlString(
            '<span style="color:#b45309;font-weight:600">'.$words.' / 150 words.</span> '
            .'<span style="color:#6b7280">150–300 words of real buying context. An empty category page is thin content.</span>'
        );
    }
}
