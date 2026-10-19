<?php

declare(strict_types=1);

namespace App\Modules\Resources\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Resources\Actions\SaveResource;
use App\Modules\Resources\Models\BrandResource;
use App\Modules\Sales\Actions\StoreClientFile;
use App\Modules\Sales\Models\ClientFile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Resources (user request, 19 Oct 2026): the brand's templates and material
 * in one place, by category, for everyone in the brand to open or download:
 * the NDA template, the letterhead, the price list, the call script. Admins
 * and Managers add, change and remove them.
 */
#[Layout('components.layouts.app')]
#[Title('Resources')]
final class ResourcesIndex extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithFileUploads;
    use WithToasts;

    public Brand $brand;

    #[Url(except: 'all')]
    public string $category = 'all';

    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editing = null;

    public string $title = '';

    public string $kind = 'other';

    public string $description = '';

    public string $url = '';

    public mixed $upload = null;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', BrandResource::class);
    }

    public function create(): void
    {
        $this->authorize('create', BrandResource::class);

        $this->reset('editing', 'title', 'description', 'url', 'upload');
        $this->kind = $this->category !== 'all' && array_key_exists($this->category, BrandResource::CATEGORIES) ? $this->category : 'other';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $resourceId): void
    {
        $resource = BrandResource::query()->findOrFail($resourceId);
        $this->authorize('update', $resource);

        $this->editing = $resourceId;
        $this->title = $resource->title;
        $this->kind = $resource->category;
        $this->description = (string) $resource->description;
        $this->url = (string) $resource->url;
        $this->upload = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(SaveResource $action): void
    {
        $resource = $this->editing !== null ? BrandResource::query()->with('file')->findOrFail($this->editing) : null;
        if ($resource !== null) {
            $this->authorize('update', $resource);
        } else {
            $this->authorize('create', BrandResource::class);
        }

        $data = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'in:'.implode(',', array_keys(BrandResource::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'upload' => ['nullable', ...StoreClientFile::rules()],
        ], ['upload.max' => 'The file may be up to '.(ClientFile::MAX_KB / 1024).' MB.'], ['kind' => 'category', 'url' => 'link', 'upload' => 'file']);

        // A resource is a file or a link: a new one needs either, a changed one keeps its file unless a link replaces it.
        if ($this->upload === null && blank($data['url']) && $resource?->file === null) {
            $this->addError('upload', 'Choose a file, or give a link.');

            return;
        }

        $fields = ['title' => $data['title'], 'category' => $data['kind'], 'description' => $data['description'], 'url' => $data['url']];

        try {
            $saved = $resource !== null
                ? $action->update($resource, $fields, $this->upload, $this->user())
                : $action->create($this->brand, $fields, $this->upload, $this->user());
        } catch (RuntimeException $exception) {
            $this->addError('upload', $exception->getMessage());

            return;
        }

        $this->showForm = false;
        $this->reset('editing', 'upload');
        $this->toastSuccess($resource !== null ? 'Resource saved' : 'Resource added', $resource !== null ? null : $saved->title.' is in '.$saved->categoryLabel().' for everyone in '.$this->brand->name.'.');
    }

    public function confirmRemove(int $resourceId): void
    {
        $resource = BrandResource::query()->findOrFail($resourceId);
        $this->authorize('delete', $resource);

        $this->askConfirmation('remove', [$resourceId], 'Remove '.$resource->title.'?', 'Nobody in '.$this->brand->name.' can download it any more. This cannot be undone.', 'Remove', 'danger');
    }

    public function remove(int $resourceId): void
    {
        $resource = BrandResource::query()->findOrFail($resourceId);
        $this->authorize('delete', $resource);

        app(SaveResource::class)->delete($resource, $this->user());
        $this->toastSuccess('Resource removed');
    }

    public function render(): View
    {
        $all = BrandResource::query()
            ->with(['file', 'creator'])
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('title', 'like', '%'.$this->search.'%')
                ->orWhere('description', 'like', '%'.$this->search.'%')))
            ->orderBy('title')
            ->get();

        $counts = $all->countBy('category');
        $shown = $this->category === 'all' ? $all : $all->where('category', $this->category);

        return view('resources::livewire.app.resources-index', [
            'groups' => collect(BrandResource::CATEGORIES)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'items' => $shown->where('category', $key)->values()])
                ->filter(fn (array $group): bool => $group['items']->isNotEmpty())
                ->values(),
            'views' => collect(BrandResource::CATEGORIES)->filter(fn (string $label, string $key): bool => ($counts[$key] ?? 0) > 0)->map(fn (string $label, string $key): array => ['label' => BrandResource::SHORT[$key] ?? $label, 'count' => $counts[$key]])->all(),
            'total' => $all->count(),
            'categories' => BrandResource::CATEGORIES,
            'canManage' => $this->user()->can('create', BrandResource::class),
            'maxMb' => ClientFile::MAX_KB / 1024,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
