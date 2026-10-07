<?php

declare(strict_types=1);

namespace App\Modules\Brands\Filament\App\Widgets;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Brands\Notifications\InvitationNotification;
use App\Modules\Core\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;

/**
 * Invitations that have not been accepted yet, with resend and revoke.
 */
final class PendingInvitationsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Pending invitations';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', Invitation::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->invitationQuery())
            ->paginated(false)
            ->columns([
                TextColumn::make('email')->weight('semibold')->description(fn (Invitation $record): ?string => $record->name),
                TextColumn::make('role')->badge(),
                TextColumn::make('department.name')->label('Department')->placeholder('—'),
                TextColumn::make('inviter.name')->label('Invited by')->placeholder('—'),
                TextColumn::make('expires_at')->label('Expires')->since(),
            ])
            ->recordActions([
                Action::make('resend')
                    ->label('Resend')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('gray')
                    ->action(function (Invitation $record): void {
                        $record->forceFill(['expires_at' => now()->addDays((int) config('liquify.invitation_expiry_days'))])->save();

                        Notification::route('mail', $record->email)->notify(new InvitationNotification($record));

                        Audit::log('members.invite_resent', $record, ['email' => $record->email], 'Invitation resent to '.$record->email, logName: 'members');

                        FilamentNotification::make()->title('Invitation sent again')->success()->send();
                    }),
                DeleteAction::make()
                    ->label('Revoke')
                    ->modalHeading('Revoke invitation')
                    ->after(function (Invitation $record): void {
                        Audit::log('members.invite_revoked', $record, ['email' => $record->email], 'Invitation revoked for '.$record->email, logName: 'members');
                    })
                    ->successNotificationTitle('Invitation revoked'),
            ])
            ->emptyStateHeading('No pending invitations')
            ->emptyStateDescription('Invitations you send appear here until they are accepted.')
            ->emptyStateIcon(Heroicon::OutlinedEnvelope);
    }

    /** @return Builder<Invitation> */
    private function invitationQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        $query = Invitation::query()->pending()->with(['department', 'inviter'])->latest('id');

        if (! $user->isGlobalAdmin()) {
            $query->where('invited_by', $user->getKey());
        }

        return $query;
    }
}
