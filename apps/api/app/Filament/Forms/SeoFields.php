<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;

/**
 * The shared SEO tab: metadata fields with live character counts and a SERP
 * preview (MASTER BUILD PROMPT section 14.3).
 *
 * The budgets are the point. The legacy homepage title is 389 characters, so
 * every SERP snippet it produces is truncated — an editor who cannot see the
 * limit has no way to avoid it. Section 14.3 is explicit that truncation must
 * be a validation warning, never a silent trim, so the field warns and still
 * saves rather than quietly cutting an editor's words off.
 */
final class SeoFields
{
    public const TITLE_BUDGET = 60;

    public const DESCRIPTION_BUDGET = 155;

    /** @return array<int, mixed> */
    public static function make(string $urlPrefix = ''): array
    {
        return [
            Section::make('Search appearance')
                ->description('How this page appears in Google. Character limits are what Google actually displays — over them, your text is cut off mid-sentence.')
                ->schema([
                    TextInput::make('meta_title')
                        ->label('Meta title')
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->helperText(fn (?string $state): HtmlString => self::counter(
                            $state, self::TITLE_BUDGET, 'Falls back to the page name if left empty.'
                        )),

                    Textarea::make('meta_description')
                        ->label('Meta description')
                        ->rows(3)
                        ->maxLength(320)
                        ->live(onBlur: true)
                        ->helperText(fn (?string $state): HtmlString => self::counter(
                            $state, self::DESCRIPTION_BUDGET, 'Benefit, key spec, availability, call to action. Unique per page.'
                        )),

                    Placeholder::make('serp_preview')
                        ->label('Google preview')
                        ->content(fn ($record, $get): HtmlString => self::preview(
                            $get('meta_title') ?: ($get('name') ?? ''),
                            $get('meta_description') ?? '',
                            $urlPrefix.($get('slug') ?? ''),
                        )),

                    TextInput::make('canonical_override')
                        ->label('Canonical URL override')
                        ->url()
                        ->helperText('Leave empty. Only set this when this page genuinely duplicates another.'),

                    Toggle::make('is_indexable')
                        ->label('Allow search engines to index this page')
                        ->helperText('Off until the page carries enough original content to be worth indexing.'),
                ])
                ->columns(1),
        ];
    }

    private static function counter(?string $state, int $budget, string $hint): HtmlString
    {
        $length = mb_strlen((string) $state);

        if ($length === 0) {
            return new HtmlString('<span class="text-gray-500">'.e($hint).'</span>');
        }

        $over = $length > $budget;
        $colour = $over ? '#b91c1c' : ($length > $budget * 0.9 ? '#b45309' : '#15803d');

        $message = $over
            ? sprintf('%d / %d — Google will truncate this.', $length, $budget)
            : sprintf('%d / %d characters.', $length, $budget);

        return new HtmlString(
            '<span style="color:'.$colour.';font-weight:600">'.e($message).'</span>'
        );
    }

    private static function preview(string $title, string $description, string $path): HtmlString
    {
        $title = mb_strlen($title) > self::TITLE_BUDGET
            ? mb_substr($title, 0, self::TITLE_BUDGET - 1).'…'
            : $title;

        $description = mb_strlen($description) > self::DESCRIPTION_BUDGET
            ? mb_substr($description, 0, self::DESCRIPTION_BUDGET - 1).'…'
            : $description;

        return new HtmlString(
            '<div style="font-family:arial,sans-serif;max-width:600px;padding:12px;border:1px solid #dadce0;border-radius:8px;background:#fff">'
            .'<div style="color:#202124;font-size:12px">officelandng.com'.e($path).'</div>'
            .'<div style="color:#1a0dab;font-size:20px;line-height:1.3;margin:2px 0">'.e($title ?: 'Untitled').'</div>'
            .'<div style="color:#4d5156;font-size:14px;line-height:1.58">'.e($description ?: 'No meta description set — Google will invent one from the page content.').'</div>'
            .'</div>'
        );
    }
}
