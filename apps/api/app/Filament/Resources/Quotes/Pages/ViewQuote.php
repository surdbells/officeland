<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\Role;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteEvent;
use App\Models\User;
use App\Quotes\ResponseClock;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * One lead, and everything needed to act on it (§11a, §11b).
 *
 * ── Ordered for the phone call, not for the database ─────────────────────
 * Contact details first, then what they asked for, then the trail. A
 * salesperson opening this is usually about to call somebody, and the number
 * should not be below a table of twenty line items.
 *
 * ── Why "mark as answered" is its own action ─────────────────────────────
 * The reply happens outside this system (D-005), so nothing can detect it. The
 * SLA is measured from `first_response_at`, and if setting it is a side effect
 * of some other action it will be wrong - either never set, making every
 * answered lead look overdue forever, or set on open, making the metric a lie.
 * It is one deliberate button.
 */
final class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    public function infolist(Schema $schema): Schema
    {
        $clock = app(ResponseClock::class);

        return $schema->components([
            Section::make('Customer')
                ->description('Reply to the email or call the number - the reply happens outside this system.')
                ->columns(3)
                ->schema([
                    TextEntry::make('email')
                        ->label('Email')
                        ->copyable()
                        // A mailto so replying is one click, with the
                        // reference already in the subject.
                        ->url(fn (Quote $record): string => 'mailto:'.$record->email.'?subject='.rawurlencode("Your quote request {$record->reference}")),

                    TextEntry::make('phone')
                        ->label('Phone')
                        ->copyable()
                        ->url(fn (Quote $record): string => 'tel:'.preg_replace('/\s+/', '', (string) $record->phone)),

                    TextEntry::make('reference')->label('Reference')->copyable()->weight('bold'),

                    TextEntry::make('submitted_at')->label('Submitted')->dateTime('D j M Y, H:i'),

                    TextEntry::make('waiting')
                        ->label('Response time')
                        ->state(fn (Quote $record): string => $clock->describe($record))
                        ->badge()
                        ->color(fn (Quote $record): string => match (true) {
                            $record->first_response_at !== null => 'success',
                            $clock->isOverdue($record) => 'danger',
                            default => 'warning',
                        }),

                    TextEntry::make('status')->badge(),
                ]),

            Section::make('What they asked for')
                ->schema([
                    /*
                     * Rendered from the SNAPSHOT, not the live product. This
                     * is the record of what the customer requested at the
                     * moment they requested it; showing current catalogue data
                     * would mean a renamed product silently changes what they
                     * are on record as having asked for.
                     */
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('qty')->label('Qty'),

                            TextEntry::make('product_name_snapshot')
                                ->label('Item')
                                ->columnSpan(2)
                                ->url(fn ($record): ?string => $record->product_url_snapshot === null
                                    ? null
                                    : rtrim((string) config('app.public_origin'), '/').$record->product_url_snapshot)
                                ->openUrlInNewTab()
                                ->helperText(fn ($record): ?string => $record->free_text_description === null
                                    ? null
                                    : 'Not in the catalogue - priced by hand'),

                            TextEntry::make('sku_snapshot')->label('SKU')->placeholder('-'),

                            TextEntry::make('customer_note')
                                ->label('Customer note')
                                ->placeholder('-')
                                ->columnSpan(4),
                        ]),
                ]),

            Section::make('Their message')
                ->visible(fn (Quote $record): bool => filled($record->message))
                ->schema([
                    TextEntry::make('message')->hiddenLabel()->prose(),
                ]),

            Section::make('Where it came from')
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextEntry::make('utm_source')->label('Source')->placeholder('-'),
                    TextEntry::make('utm_medium')->label('Medium')->placeholder('-'),
                    TextEntry::make('utm_campaign')->label('Campaign')->placeholder('-'),
                    TextEntry::make('landing_page')->label('Landing page')->placeholder('-')->columnSpan(3),
                    TextEntry::make('referrer')->label('Referrer')->placeholder('-')->columnSpan(3),
                ]),

            /*
             * §11b. The full trail, including the events nobody performed:
             * the confirmation that was suppressed, the email that failed to
             * queue. Those are exactly the ones worth seeing, because they
             * explain a customer who says they never heard from us.
             */
            Section::make('History')
                ->schema([
                    RepeatableEntry::make('events')
                        ->hiddenLabel()
                        ->columns(6)
                        ->schema([
                            TextEntry::make('created_at')->label('When')->dateTime('j M, H:i'),

                            TextEntry::make('type')
                                ->label('What')
                                ->badge()
                                // Only the failures are coloured. If everything
                                // is coloured, nothing is - and the two rows
                                // that matter here are the two that mean the
                                // customer heard nothing.
                                ->color(fn (string $state): string => match ($state) {
                                    'email_queue_failed', 'confirmation_suppressed' => 'danger',
                                    'first_response', 'submitted' => 'success',
                                    default => 'gray',
                                }),

                            TextEntry::make('actor_label')->label('Who')->placeholder('system'),

                            TextEntry::make('detail')
                                ->label('Detail')
                                ->columnSpan(3)
                                ->state(fn (QuoteEvent $record): string => $record->describe())
                                ->placeholder('-'),
                        ]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * The SLA stop button. The reply happens outside this system, so
             * nothing can detect it; making this explicit is what keeps
             * first_response_at honest.
             */
            Action::make('markAnswered')
                ->label('I have replied')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (Quote $record): bool => $record->first_response_at === null)
                ->action(function (Quote $record): void {
                    $user = auth()->user();

                    $record->forceFill([
                        'first_response_at' => now(),
                        // Replying is taking ownership. Assigning it to
                        // somebody else afterwards is possible, but the
                        // default should never be "answered by nobody".
                        'assigned_to_user_id' => $record->assigned_to_user_id
                            ?? ($user instanceof User ? $user->id : null),
                        'status' => $record->status === 'new' ? 'contacted' : $record->status,
                    ])->save();

                    $record->events()->create([
                        'type' => 'first_response',
                        'actor_user_id' => $user instanceof User ? $user->id : null,
                        'actor_label' => $user instanceof User ? $user->name : 'unknown',
                        'payload' => [],
                    ]);

                    Notification::make()->title('Marked as answered')->success()->send();
                }),

            Action::make('assign')
                ->label('Assign')
                ->icon(Heroicon::OutlinedUser)
                ->schema([
                    Select::make('assigned_to_user_id')
                        ->label('Owner')
                        /*
                         * Only people who can actually open the queue. Offering
                         * every user makes it possible to assign a lead to
                         * somebody who cannot see it - which looks exactly like
                         * an owned lead and behaves exactly like an abandoned
                         * one. Inactive accounts are excluded for the same
                         * reason.
                         */
                        ->options(fn (): array => User::query()
                            ->where('is_active', true)
                            ->whereIn('role', [Role::SuperAdmin->value, Role::Sales->value])
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->fillForm(fn (Quote $record): array => [
                    'assigned_to_user_id' => $record->assigned_to_user_id,
                ])
                ->action(function (Quote $record, array $data): void {
                    $user = auth()->user();
                    $owner = User::query()->whereKey((int) $data['assigned_to_user_id'])->first();

                    $record->forceFill(['assigned_to_user_id' => $owner?->id])->save();

                    $record->events()->create([
                        'type' => 'assigned',
                        'actor_user_id' => $user instanceof User ? $user->id : null,
                        'actor_label' => $user instanceof User ? $user->name : 'unknown',
                        'payload' => ['to' => $owner?->name],
                    ]);

                    Notification::make()->title("Assigned to {$owner?->name}")->success()->send();
                }),

            Action::make('note')
                ->label('Add a note')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->schema([
                    Textarea::make('note')
                        ->label('Note')
                        ->required()
                        ->rows(4)
                        ->helperText('Internal only. The customer never sees this.'),
                ])
                ->action(function (Quote $record, array $data): void {
                    $user = auth()->user();

                    $record->events()->create([
                        'type' => 'note',
                        'actor_user_id' => $user instanceof User ? $user->id : null,
                        'actor_label' => $user instanceof User ? $user->name : 'unknown',
                        'payload' => ['note' => $data['note']],
                    ]);

                    Notification::make()->title('Note added')->success()->send();
                }),

            Action::make('status')
                ->label('Change status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->schema([
                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'new' => 'New',
                            'acknowledged' => 'Acknowledged',
                            'contacted' => 'Contacted',
                            'won' => 'Won',
                            'lost' => 'Lost',
                            'spam' => 'Spam',
                        ])
                        ->required(),

                    Textarea::make('note')
                        ->label('Why')
                        ->rows(3)
                        ->helperText('Especially useful on Lost - it is the only record of why.'),
                ])
                ->fillForm(fn (Quote $record): array => ['status' => $record->status])
                ->action(function (Quote $record, array $data): void {
                    $user = auth()->user();
                    $from = $record->status;

                    $record->forceFill([
                        'status' => $data['status'],
                        // A closed lead gets a closing timestamp, so "how long
                        // do these take" is answerable later.
                        'closed_at' => in_array($data['status'], ['won', 'lost', 'spam'], true)
                            ? ($record->closed_at ?? now())
                            : null,
                    ])->save();

                    $record->events()->create([
                        'type' => 'status_changed',
                        'actor_user_id' => $user instanceof User ? $user->id : null,
                        'actor_label' => $user instanceof User ? $user->name : 'unknown',
                        'payload' => array_filter([
                            'from' => $from,
                            'to' => $data['status'],
                            'note' => $data['note'] ?? null,
                        ]),
                    ]);

                    Notification::make()->title("Status is now {$data['status']}")->success()->send();
                }),
        ];
    }
}
