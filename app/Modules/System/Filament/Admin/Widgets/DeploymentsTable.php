<?php

declare(strict_types=1);

namespace App\Modules\System\Filament\Admin\Widgets;

use App\Modules\System\Models\Deployment;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Deploy, database update, backup and rollback history on the System screen.
 */
final class DeploymentsTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Deployment history';

    public function table(Table $table): Table
    {
        return $table
            ->query(Deployment::query()->with('triggeredBy')->latest('id'))
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('commit_sha')
                    ->label('Commit')
                    ->formatStateUsing(fn (?string $state): string => $state ? substr($state, 0, 7) : '')
                    ->fontFamily('mono')
                    ->placeholder('—'),
                TextColumn::make('branch')->placeholder('—'),
                TextColumn::make('release')->placeholder('—')->toggleable(),
                TextColumn::make('migrations_run')
                    ->label('Migrations')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) ? (string) count($state) : '0')
                    ->alignEnd(),
                TextColumn::make('triggeredBy.name')->label('By')->placeholder('GitHub Actions'),
                TextColumn::make('created_at')->label('When')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('log')
                    ->label('Log')
                    ->icon(Heroicon::OutlinedInboxArrowDown)
                    ->color('gray')
                    ->modalHeading(fn (Deployment $record): string => $record->type->getLabel().' · '.$record->created_at?->format('d M Y H:i'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('log')
                            ->hiddenLabel()
                            ->default(fn (Deployment $record): string => $record->log ?? 'No log recorded.')
                            ->fontFamily('mono')
                            ->size('xs'),
                    ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedRocketLaunch)
            ->emptyStateHeading('No deployments yet')
            ->emptyStateDescription('The first GitHub Actions deploy and every database update will be listed here.');
    }
}
