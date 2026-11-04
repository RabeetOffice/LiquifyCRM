<?php

declare(strict_types=1);

namespace App\Modules\Chat\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\CannedReply;
use App\Modules\Chat\Policies\CannedReplyPolicy;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Saved replies (live chat, 3 Nov 2026): the answers the team types often, called up in the message box with their
 * shortcut (`/price`). Everybody in the brand uses the company's, the brand's and their own; Admins write the
 * company's, Managers the brand's, and anybody their own.
 */
#[Layout('components.layouts.app')]
#[Title('Saved replies')]
final class CannedRepliesIndex extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithToasts;

    public Brand $brand;

    public bool $showForm = false;

    #[Locked]
    public ?int $editing = null;

    public string $scope = CannedReply::PERSONAL;

    public string $shortcut = '';

    public string $title = '';

    public string $body = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', CannedReply::class);
    }

    public function create(): void
    {
        $this->authorize('create', CannedReply::class);

        $this->resetErrorBag();
        $this->reset('editing', 'shortcut', 'title', 'body');
        $this->scope = CannedReply::PERSONAL;
        $this->showForm = true;
    }

    public function edit(int $replyId): void
    {
        $reply = $this->find($replyId);
        $this->authorize('update', $reply);

        $this->resetErrorBag();
        $this->editing = $replyId;
        $this->scope = $reply->scope;
        $this->shortcut = $reply->shortcut;
        $this->title = $reply->title;
        $this->body = $reply->body;
        $this->showForm = true;
    }

    public function save(): void
    {
        $reply = $this->editing !== null ? $this->find($this->editing) : null;

        if ($reply !== null) {
            $this->authorize('update', $reply);
        } else {
            $this->authorize('create', CannedReply::class);
        }

        // A reply keeps the scope it was made in; a new one may only be made where this person may write.
        $scopes = $this->policyScopes();
        $scope = $reply !== null ? $reply->scope : $this->scope;

        if ($reply === null && ! in_array($scope, $scopes, true)) {
            abort(403);
        }

        $this->shortcut = '/'.ltrim(strtolower(trim($this->shortcut)), '/');

        $this->validate([
            'shortcut' => ['required', 'regex:/^\/[a-z0-9_-]{1,29}$/', Rule::unique('canned_replies', 'shortcut')
                ->where(fn ($query) => $query->where('scope', $scope)->where('brand_id', $scope === CannedReply::BRAND ? $this->brand->getKey() : null)->where('user_id', $scope === CannedReply::PERSONAL ? $this->user()->getKey() : null))
                ->ignore($reply?->getKey())],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
        ], [
            'shortcut.regex' => 'Use a short word after the slash: letters, numbers, - or _, like /price.',
            'shortcut.unique' => 'You already have a reply with this shortcut.',
        ], ['shortcut' => 'shortcut', 'body' => 'reply']);

        $values = ['shortcut' => $this->shortcut, 'title' => trim($this->title), 'body' => trim($this->body)];

        if ($reply !== null) {
            $reply->update($values);
        } else {
            $reply = CannedReply::query()->create([
                ...$values,
                'scope' => $scope,
                'brand_id' => $scope === CannedReply::BRAND ? $this->brand->getKey() : null,
                'user_id' => $scope === CannedReply::PERSONAL ? $this->user()->getKey() : null,
            ]);
        }

        Audit::log('chat.reply_saved', $reply, ['scope' => $scope, 'shortcut' => $reply->shortcut], 'Saved reply '.$reply->shortcut.' ('.$scope.')', brand: $this->brand, causer: $this->user(), logName: 'chat');

        $this->showForm = false;
        $this->reset('editing');
        $this->toastSuccess('Saved: '.$reply->shortcut);
    }

    public function confirmRemove(int $replyId): void
    {
        $reply = $this->find($replyId);
        $this->authorize('delete', $reply);

        $this->askConfirmation('remove', [$replyId], 'Remove '.$reply->shortcut.'?', $reply->scope === CannedReply::GLOBAL ? 'Nobody in any brand can use it any more.' : ($reply->scope === CannedReply::BRAND ? 'Nobody in '.$this->brand->name.' can use it any more.' : 'You will not be able to use it any more.'), 'Remove', 'danger');
    }

    public function remove(int $replyId): void
    {
        $reply = $this->find($replyId);
        $this->authorize('delete', $reply);

        $reply->delete();
        Audit::log('chat.reply_removed', null, ['shortcut' => $reply->shortcut, 'scope' => $reply->scope], 'Saved reply removed: '.$reply->shortcut, brand: $this->brand, causer: $this->user(), logName: 'chat');
        $this->toastSuccess('Removed');
    }

    public function render(): View
    {
        $user = $this->user();
        $all = CannedReply::query()->usableBy($user, (int) $this->brand->getKey())->orderBy('shortcut')->get();

        return view('chat::livewire.app.canned-replies-index', [
            'groups' => collect([CannedReply::PERSONAL, CannedReply::BRAND, CannedReply::GLOBAL])
                ->mapWithKeys(fn (string $scope): array => [$scope => $all->where('scope', $scope)->values()])
                ->filter(fn ($replies): bool => $replies->isNotEmpty()),
            'labels' => [CannedReply::PERSONAL => 'Only mine', CannedReply::BRAND => 'Everybody in '.$this->brand->name, CannedReply::GLOBAL => 'Every brand (written by the Admins)'],
            'scopes' => $this->policyScopes(),
            'scopeLabels' => CannedReply::SCOPES,
            'variables' => CannedReply::VARIABLES,
            'canCreate' => $this->policyScopes() !== [],
            'mayChange' => fn (CannedReply $reply): bool => $user->can('update', $reply),
        ]);
    }

    /** @return list<string> */
    private function policyScopes(): array
    {
        return array_values((new CannedReplyPolicy)->scopesFor($this->user()));
    }

    private function find(int $id): CannedReply
    {
        return CannedReply::query()->usableBy($this->user(), (int) $this->brand->getKey())->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
