<?php

declare(strict_types=1);

use App\Modules\Resources\Livewire\App\ResourcesIndex;
use App\Modules\Resources\Models\BrandResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Resources (user request, 19 Oct 2026): a brand's templates and material,
 * such as the NDA template and the letterhead, for everyone in the brand to
 * download. Admins and Managers add, change and remove them.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Farah Front']);
    $this->agent = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->upseller = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));
    $this->admin = admin();

    inBrand($this->brand);
});

it('lets a Manager add a template that everyone in the brand downloads', function (): void {
    Livewire::actingAs($this->manager)
        ->test(ResourcesIndex::class, ['brand' => $this->brand])
        ->call('create')
        ->set('title', 'NDA template')
        ->set('kind', 'contracts')
        ->set('description', 'Send it before the first call')
        ->call('save')
        ->assertHasErrors('upload')
        ->set('upload', UploadedFile::fake()->create('NDA.docx', 40, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('NDA template')
        ->assertSee('Contracts and agreements');

    $resource = BrandResource::query()->with('file')->sole();

    expect($resource->file)->not->toBeNull()
        ->and($resource->file->original_name)->toBe('NDA.docx');
    Storage::disk('local')->assertExists($resource->file->path);

    // Everyone in the brand, an Upsell agent too, downloads it; nobody outside does.
    foreach ([$this->agent, $this->upseller, $this->admin] as $person) {
        $this->actingAs($person)->get('/b/ukph/resources/'.$resource->getKey().'/file')->assertOk()->assertDownload('NDA.docx');
        $this->flushSession();
    }

    $stranger = agentOf(department(brand(['slug' => 'anz']), ['name' => 'ANZ Sales']));
    $this->actingAs($stranger)->get('/b/ukph/resources/'.$resource->getKey().'/file')->assertNotFound();
});

it('shows Agents the resources but no way to change them', function (): void {
    BrandResource::query()->create(['brand_id' => $this->brand->getKey(), 'title' => 'Price list', 'category' => 'sales', 'url' => 'https://drive.google.com/file/d/abc']);

    $this->actingAs($this->agent)->get('/b/ukph/resources')->assertOk()
        ->assertSee('Price list')
        ->assertSee('drive.google.com')
        ->assertDontSee('Add resource');

    Livewire::actingAs($this->agent)->test(ResourcesIndex::class, ['brand' => $this->brand])->call('create')->assertForbidden();
    Livewire::actingAs($this->agent)->test(ResourcesIndex::class, ['brand' => $this->brand])->call('confirmRemove', BrandResource::query()->sole()->getKey())->assertForbidden();

    // The menu has Resources for everyone in the brand.
    $this->actingAs($this->upseller)->get('/b/ukph/clients')->assertOk()->assertSee('Resources');
});

it('replaces a file, turns a file into a link, and removes a resource with its file', function (): void {
    $component = Livewire::actingAs($this->admin)
        ->test(ResourcesIndex::class, ['brand' => $this->brand])
        ->call('create')
        ->set('title', 'Letterhead')
        ->set('kind', 'branding')
        ->set('upload', UploadedFile::fake()->create('letterhead-v1.docx', 30))
        ->call('save');

    $resource = BrandResource::query()->with('file')->sole();
    $first = $resource->file->path;

    $component->call('edit', $resource->getKey())
        ->set('upload', UploadedFile::fake()->create('letterhead-v2.pdf', 30, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $resource->refresh()->load('file');
    Storage::disk('local')->assertMissing($first);
    expect($resource->file->original_name)->toBe('letterhead-v2.pdf');

    // A link instead of the file: the file goes.
    $second = $resource->file->path;
    $component->call('edit', $resource->getKey())->set('url', 'https://www.canva.com/design/xyz')->call('save')->assertHasNoErrors();
    Storage::disk('local')->assertMissing($second);
    expect($resource->fresh()->url)->toBe('https://www.canva.com/design/xyz')->and($resource->fresh()->file)->toBeNull();

    $component->call('confirmRemove', $resource->getKey())->call('runConfirmed');
    expect(BrandResource::query()->count())->toBe(0);

    // A file of a type that could run on the server is refused.
    $component->call('create')->set('title', 'Script')->set('upload', UploadedFile::fake()->create('run.php', 1))->call('save')->assertHasErrors('upload');
});
