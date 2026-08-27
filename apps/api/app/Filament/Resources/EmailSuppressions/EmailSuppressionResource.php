<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailSuppressions;

use App\Filament\Resources\EmailSuppressions\Pages\ListEmailSuppressions;
use App\Models\EmailSuppression;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The suppression list, visible and releasable (§10d).
 *
 * §10d requires the list to be "enforced and visible in admin". Visible is the
 * operative word: a suppression is invisible from the customer's side and from
 * the salesperson's, so without this screen the only symptom of a suppressed
 * address is a customer insisting they never received anything.
 *
 * ── Releasing is deliberate, and attributed ──────────────────────────────
 * A customer who fixes their mailbox and asks us to try again is a real case.
 * It is a manual act with an actor recorded, rather than an automatic expiry:
 * an expiry would quietly resume sending to a dead address and re-earn the
 * bounce, and repeated bounces to the same address are exactly what damages a
 * sending domain's reputation.
 */
final class EmailSuppressionResource extends Resource
{
    protected static ?string $model = EmailSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Quotes';

    protected static ?string $navigationLabel = 'Email suppressions';

    protected static ?string $modelLabel = 'suppressed address';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Deliverability is an operational concern rather than a catalogue
        // one, but it is the same people who chase an unanswered lead.
        return $user instanceof User && $user->role->managesCatalogue();
    }

    public static function canCreate(): bool
    {
        // Adding by hand is legitimate - a customer asking not to be written
        // to - but it is rare enough to belong behind the create form rather
        // than as a bulk surface.
        return true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Address')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('reason')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EmailSuppression::REASON_COMPLAINT => 'danger',
                        EmailSuppression::REASON_HARD_BOUNCE => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        EmailSuppression::REASON_HARD_BOUNCE => 'Hard bounce',
                        EmailSuppression::REASON_COMPLAINT => 'Marked as spam',
                        EmailSuppression::REASON_MANUAL => 'Added by hand',
                        default => $state,
                    }),

                IconColumn::make('released_at')
                    ->label('Active')
                    // Inverted on purpose: "active" means still suppressed,
                    // which is the state an operator is looking for.
                    ->boolean()
                    ->getStateUsing(fn (EmailSuppression $record): bool => $record->isActive())
                    ->trueIcon(Heroicon::OutlinedNoSymbol)
                    ->falseIcon(Heroicon::OutlinedCheckCircle)
                    ->trueColor('danger')
                    ->falseColor('success'),

                TextColumn::make('suppressed_at')
                    ->label('Since')
                    ->dateTime('j M Y H:i')
                    ->sortable(),

                TextColumn::make('released_by')
                    ->label('Released by')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->defaultSort('suppressed_at', 'desc')
            ->filters([
                SelectFilter::make('reason')->options([
                    EmailSuppression::REASON_HARD_BOUNCE => 'Hard bounce',
                    EmailSuppression::REASON_COMPLAINT => 'Marked as spam',
                    EmailSuppression::REASON_MANUAL => 'Added by hand',
                ]),

                TernaryFilter::make('active')
                    ->label('Still suppressed')
                    ->queries(
                        true: fn ($query) => $query->whereNull('released_at'),
                        false: fn ($query) => $query->whereNotNull('released_at'),
                        blank: fn ($query) => $query,
                    )
                    ->default(true),
            ])
            ->recordActions([
                Action::make('release')
                    ->label('Release')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('success')
                    ->visible(fn (EmailSuppression $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Send to this address again?')
                    ->modalDescription(
                        'Only do this if you know the mailbox works - the customer confirmed it, '.
                        'or you have reached them another way. Sending to a dead address again '.
                        'earns another bounce, and repeated bounces damage delivery for everyone.'
                    )
                    ->action(function (EmailSuppression $record): void {
                        $user = auth()->user();

                        $record->forceFill([
                            'released_at' => now(),
                            // Attributed: releasing a suppression is a
                            // judgement call somebody has to own.
                            'released_by' => $user instanceof User ? $user->email : 'unknown',
                        ])->save();

                        Notification::make()
                            ->title("{$record->email} will receive email again")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label('Address')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                // Normalised on the way in, so a manual entry matches what the
                // send path looks up.
                ->dehydrateStateUsing(fn (string $state): string => EmailSuppression::normalise($state)),

            TextInput::make('reason')
                ->default(EmailSuppression::REASON_MANUAL)
                ->disabled()
                ->dehydrated(),

            Textarea::make('detail')
                ->label('Why')
                ->helperText('Who asked, and when. This is the record if the decision is questioned later.')
                ->rows(3),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailSuppressions::route('/'),
        ];
    }
}
