<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Content\SimilarityGate;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

final class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * §12b. The act that turns a generated draft into copy the site
             * will index. It is a person's name against a page, which is the
             * only thing standing between D-010's 15,000 indexable products
             * and 15,000 pages of boilerplate.
             *
             * Deliberately not a checkbox on the form: approving is a decision
             * about the text, and burying it among forty other fields makes it
             * something people tick while saving something unrelated.
             */
            Action::make('approveDescription')
                ->label('Approve this description')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (Product $record): bool => ! $record->descriptionIsApproved()
                    || $record->description_flagged_at !== null)
                ->requiresConfirmation()
                ->modalHeading('Approve this description?')
                ->modalDescription(
                    'Confirm you have read it and it describes THIS product - its own specifications, '.
                    'not a sibling\'s with the name changed. Your name is recorded against it.'
                )
                ->action(function (Product $record): void {
                    $user = auth()->user();

                    $record->forceFill([
                        'description_approved_at' => now(),
                        'description_approved_by' => $user instanceof User ? $user->id : null,
                    ])->save();

                    Notification::make()
                        ->title('Description approved')
                        ->body('This product can now be indexed once it meets the other minimums.')
                        ->success()
                        ->send();
                }),

            /*
             * Re-runs the gate on demand, so an editor who has just rewritten
             * a flagged description gets an answer now rather than at the next
             * batch run - otherwise the flag sits there contradicting the work
             * they just did.
             */
            Action::make('recheckSimilarity')
                ->label('Re-check for duplication')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->visible(fn (Product $record): bool => $record->description_flagged_at !== null)
                ->action(function (Product $record, SimilarityGate $gate): void {
                    $match = $gate->check($record);

                    if ($match === null) {
                        Notification::make()
                            ->title('No longer reads like another product')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Still too close to '.$match->name)
                        ->body(sprintf(
                            '%.1f%% the same once the names and codes are removed. If these genuinely are '.
                            'variants of one product, approve it instead.',
                            (float) $record->description_similarity * 100,
                        ))
                        ->warning()
                        ->send();
                }),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
