<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuoteRoutingRules\Pages;

use App\Filament\Resources\QuoteRoutingRules\QuoteRoutingRuleResource;
use App\Mail\QuoteReceivedMail;
use App\Quotes\QuoteRouting;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class ListQuoteRoutingRules extends ListRecords
{
    protected static string $resource = QuoteRoutingRuleResource::class;

    public function getSubheading(): string
    {
        $routing = app(QuoteRouting::class);

        return 'Anything without a matching rule goes to '.$routing->default().
            '. Rules match the primary category of the first line, longest path first.';
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * §11d. A routing change is invisible until a real lead arrives,
             * and "the address had a typo" is the kind of fault that only
             * surfaces as leads that were never answered. This sends the real
             * notification template, through the real transport, so a wrong
             * address bounces here instead of silently swallowing an order.
             */
            Action::make('testSend')
                ->label('Send a test')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->schema([
                    TextInput::make('to')
                        ->label('Send to')
                        ->email()
                        ->required()
                        ->default(fn (): string => app(QuoteRouting::class)->default())
                        ->helperText('Usually the address on the rule you just added.'),
                ])
                ->action(function (array $data): void {
                    $to = (string) $data['to'];

                    try {
                        /*
                         * A MADE-UP quote, never a real one. The address is
                         * typed by hand into a form, so sending a real lead
                         * would let anyone with admin access forward a
                         * customer's email, phone number and buying intent to
                         * an arbitrary inbox with one click. A sample proves
                         * the routing works just as well and discloses nothing.
                         */
                        /*
                         * `sendNow`, not `send`. QuoteReceivedMail implements
                         * ShouldQueue, so `send` would enqueue it and return
                         * immediately - the notification below would report
                         * success before the transport had been touched, and
                         * a wrong token or a dead address would surface only
                         * in a failed job nobody is watching. That is exactly
                         * the failure this button exists to catch.
                         */
                        Mail::to($to)->sendNow(new QuoteReceivedMail(QuoteRouting::sampleQuote()));

                        Notification::make()
                            ->title("Test sent to {$to}")
                            ->body('If it does not arrive within a few minutes, check the suppression list and the mail log.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        /*
                         * Sent synchronously and reported, rather than queued.
                         * A queued test that fails reports success here and
                         * the failure lands in a log nobody is watching -
                         * which defeats the entire point of a test send.
                         */
                        Notification::make()
                            ->title('The test could not be sent')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),

            CreateAction::make()->label('Add a rule'),
        ];
    }
}
