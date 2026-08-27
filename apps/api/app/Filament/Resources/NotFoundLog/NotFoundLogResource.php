<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotFoundLog;

use App\Filament\Resources\NotFoundLog\Pages\ListNotFoundLog;
use App\Models\NotFoundLogEntry;
use App\Models\Redirect;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * The 404 log, with a one-click "create redirect" (section 11, "SEO tools").
 *
 * This is redirect discovery rather than error reporting. A path requested
 * repeatedly is one somebody still links to, and every one of those is link
 * equity arriving at a dead end. Sorting by hit count puts the most valuable
 * fixes at the top, and the action turns a finding into a redirect without
 * retyping the path - which is what makes the loop actually get used.
 */
final class NotFoundLogResource extends Resource
{
    protected static ?string $model = NotFoundLogEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = '404 log';

    protected static ?string $modelLabel = 'missing page';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->role->managesCatalogue() || $user->role->managesContent());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('path')->searchable()->limit(70),
                TextColumn::make('hits')->numeric()->sortable()
                    ->description('Requests'),
                TextColumn::make('referrer')->limit(50)->toggleable()
                    ->placeholder('- typed or unknown -'),
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                Filter::make('repeated')
                    ->label('Requested more than once')
                    ->query(fn (Builder $query): Builder => $query->where('hits', '>', 1)),

                // A path with a referrer is linked from somewhere real, which
                // makes it worth more than one someone mistyped.
                Filter::make('has_referrer')
                    ->label('Linked from somewhere')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('referrer')),

                Filter::make('unresolved')
                    ->label('No redirect yet')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereNotExists(
                        fn ($q) => $q->select(DB::raw(1))
                            ->from('redirects')
                            ->whereColumn('redirects.from_path', 'not_found_log.path')
                    )),
            ])
            ->recordActions([
                Action::make('createRedirect')
                    ->label('Create redirect')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->schema([
                        // Prefilled, so nobody retypes a path and mistypes it.
                        TextInput::make('from_path')->label('Old address')->disabled()
                            ->default(fn (NotFoundLogEntry $record): string => $record->path),

                        Select::make('status_code')
                            ->label('Type')
                            ->options([
                                301 => '301 - send visitors somewhere useful',
                                410 => '410 - gone for good',
                            ])
                            ->default(301)
                            ->live()
                            ->required(),

                        TextInput::make('to_path')
                            ->label('New address')
                            ->visible(fn (Get $get): bool => (int) $get('status_code') !== 410)
                            ->requiredIf('status_code', 301)
                            ->rules(['nullable', 'regex:/^\//']),
                    ])
                    ->action(function (NotFoundLogEntry $record, array $data): void {
                        $exists = Redirect::query()->where('from_path', $record->path)->exists();

                        if ($exists) {
                            Notification::make()
                                ->title('A redirect for this path already exists.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Redirect::create([
                            'from_path' => $record->path,
                            'to_path' => (int) $data['status_code'] === 410 ? null : $data['to_path'],
                            'status_code' => (int) $data['status_code'],
                            'source' => 'manual',
                            'notes' => sprintf('Created from the 404 log after %d request(s).', $record->hits),
                        ]);

                        Notification::make()->title('Redirect created.')->success()->send();
                    }),
            ])
            ->defaultSort('hits', 'desc');
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return ['index' => ListNotFoundLog::route('/')];
    }
}
