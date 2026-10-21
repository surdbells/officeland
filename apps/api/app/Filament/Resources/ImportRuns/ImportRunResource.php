<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns;

use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\Schemas\ImportRunInfolist;
use App\Filament\Resources\ImportRuns\Tables\ImportRunsTable;
use App\Models\ImportRun;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * The product import console.
 *
 * Launch a bulk Icecat import, then watch it run in the background: the list
 * polls live progress, and because every run is a durable record driven by a job
 * batch on the worker, you can close the tab and come back to it finishing. No
 * screen needs to stay open.
 */
class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'Product imports';

    protected static ?string $modelLabel = 'import';

    protected static ?string $recordTitleAttribute = 'label';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesCatalogue();
    }

    public static function table(Table $table): Table
    {
        return ImportRunsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ImportRunInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'view' => ViewImportRun::route('/{record}'),
        ];
    }

    /** A compact progress bar, shared by the table and the detail view. */
    public static function progressBar(ImportRun $run): HtmlString
    {
        $pct = $run->progressPercent();
        $colour = match ($run->status) {
            'completed' => '#15803d',
            'failed' => '#b91c1c',
            'cancelled' => '#b45309',
            default => '#0046BF',
        };

        return new HtmlString(
            '<div style="min-width:160px">'
            .'<div style="height:8px;background:#e5e7eb;border-radius:9999px;overflow:hidden">'
            .'<div style="height:100%;width:'.$pct.'%;background:'.$colour.';transition:width .4s"></div>'
            .'</div>'
            .'<div style="margin-top:4px;font-size:0.75rem;color:#6b7280">'
            .$pct.'% · '.number_format($run->processed()).' / '.number_format($run->total)
            .'</div></div>'
        );
    }
}
