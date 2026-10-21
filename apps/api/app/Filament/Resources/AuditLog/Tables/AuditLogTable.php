<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog\Tables;

use App\Models\AuditLogEntry;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

final class AuditLogTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('user_label')->label('Who')->placeholder('— system —')->searchable(),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'restored' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('auditable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : class_basename($state))
                    ->sortable(),
                TextColumn::make('auditable_label')->label('Record')->limit(50)->placeholder('—')->searchable(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')->options([
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'deleted' => 'Deleted',
                    'restored' => 'Restored',
                ]),
                SelectFilter::make('auditable_type')
                    ->label('Type')
                    ->options(fn (): array => AuditLogEntry::query()
                        ->distinct()
                        ->orderBy('auditable_type')
                        ->pluck('auditable_type', 'auditable_type')
                        ->map(fn (string $type): string => class_basename($type))
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()->schema(self::detail()),
            ]);
    }

    /** The per-entry detail, including the before/after snapshot. */
    private static function detail(): array
    {
        return [
            Section::make()->columns(2)->schema([
                TextEntry::make('created_at')->label('When')->dateTime('d M Y H:i:s'),
                TextEntry::make('user_label')->label('Who')->placeholder('— system —'),
                TextEntry::make('event')->badge(),
                TextEntry::make('ip')->label('IP address')->placeholder('—'),
                TextEntry::make('auditable_type')
                    ->label('Type')
                    ->state(fn (AuditLogEntry $record): string => $record->auditable_type === null ? '—' : class_basename($record->auditable_type)),
                TextEntry::make('auditable_label')->label('Record')->placeholder('—'),
                TextEntry::make('trace_id')->label('Trace ID')->placeholder('—')->copyable(),
            ]),
            Section::make('What changed')->schema([
                TextEntry::make('changed')
                    ->hiddenLabel()
                    ->state(fn (AuditLogEntry $record): string => self::diff($record))
                    ->prose()
                    ->html(),
            ]),
        ];
    }

    /** A compact before → after rendering of the fields that moved. */
    private static function diff(AuditLogEntry $record): string
    {
        $keys = $record->changedKeys();

        if ($keys === []) {
            return '<em>No field-level changes were recorded.</em>';
        }

        $rows = [];

        foreach ($keys as $key) {
            $before = self::scalar($record->before[$key] ?? null);
            $after = self::scalar($record->after[$key] ?? null);
            $rows[] = '<tr>'
                .'<td style="padding:4px 12px 4px 0;font-weight:600;vertical-align:top">'.e($key).'</td>'
                .'<td style="padding:4px 12px 4px 0;color:#b91c1c;vertical-align:top">'.e($before).'</td>'
                .'<td style="padding:4px;color:#15803d;vertical-align:top">'.e($after).'</td>'
                .'</tr>';
        }

        return '<table style="font-size:0.85rem;border-collapse:collapse">'
            .'<thead><tr>'
            .'<th style="text-align:left;padding-right:12px">Field</th>'
            .'<th style="text-align:left;padding-right:12px">Before</th>'
            .'<th style="text-align:left">After</th>'
            .'</tr></thead><tbody>'.implode('', $rows).'</tbody></table>';
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return Str::limit((string) json_encode($value), 120);
        }

        return Str::limit((string) $value, 120);
    }
}
