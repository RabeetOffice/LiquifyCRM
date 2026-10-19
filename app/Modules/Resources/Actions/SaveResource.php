<?php

declare(strict_types=1);

namespace App\Modules\Resources\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\Audit;
use App\Modules\Resources\Models\BrandResource;
use App\Modules\Sales\Models\ClientFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Add, change or remove one of a brand's resources. A file goes to the
 * private disk under a random name, as client files do, and is served only
 * by ResourceFileController; a new file replaces the old one on disk.
 */
final class SaveResource
{
    /** @param  array{title: string, category: string, description: ?string, url: ?string}  $data */
    public function create(Brand $brand, array $data, ?UploadedFile $upload, User $by): BrandResource
    {
        return DB::transaction(function () use ($brand, $data, $upload, $by): BrandResource {
            $resource = BrandResource::query()->create([
                'brand_id' => $brand->getKey(),
                ...$this->fields($data, $upload),
                'created_by' => $by->getKey(),
            ]);

            if ($upload !== null) {
                $this->store($resource, $upload, $by);
            }

            Audit::log('resources.added', $resource, ['title' => $resource->title, 'category' => $resource->category], 'Resource added: '.$resource->title, brand: $brand, causer: $by, logName: 'resources');

            return $resource;
        });
    }

    /** @param  array{title: string, category: string, description: ?string, url: ?string}  $data */
    public function update(BrandResource $resource, array $data, ?UploadedFile $upload, User $by): BrandResource
    {
        return DB::transaction(function () use ($resource, $data, $upload, $by): BrandResource {
            $resource->update($this->fields($data, $upload));

            // A new file, or a link instead of the file: the old file goes.
            if ($upload !== null || $resource->isLink()) {
                $this->forgetFile($resource);
            }

            if ($upload !== null) {
                $this->store($resource, $upload, $by);
            }

            Audit::log('resources.updated', $resource, ['title' => $resource->title, 'category' => $resource->category], 'Resource changed: '.$resource->title, brand: $resource->brand_id, causer: $by, logName: 'resources');

            return $resource;
        });
    }

    public function delete(BrandResource $resource, User $by): void
    {
        DB::transaction(function () use ($resource, $by): void {
            $this->forgetFile($resource);
            $resource->delete();

            Audit::log('resources.removed', null, ['resource_id' => $resource->getKey(), 'title' => $resource->title], 'Resource removed: '.$resource->title, brand: $resource->brand_id, causer: $by, logName: 'resources');
        });
    }

    /**
     * @param  array{title: string, category: string, description: ?string, url: ?string}  $data
     * @return array{title: string, category: string, description: ?string, url: ?string}
     */
    private function fields(array $data, ?UploadedFile $upload): array
    {
        return [
            'title' => trim($data['title']),
            'category' => array_key_exists($data['category'], BrandResource::CATEGORIES) ? $data['category'] : 'other',
            'description' => filled($data['description']) ? trim((string) $data['description']) : null,
            // A file wins over a link given with it.
            'url' => $upload === null && filled($data['url']) ? trim((string) $data['url']) : null,
        ];
    }

    private function store(BrandResource $resource, UploadedFile $upload, User $by): void
    {
        $extension = mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', $upload->getClientOriginalExtension()) ?? '');

        if (in_array($extension, ClientFile::BLOCKED, true)) {
            throw new RuntimeException('Files of this type cannot be stored.');
        }

        $path = $upload->storeAs('resources/'.$resource->brand_id, Str::lower((string) Str::ulid()).($extension !== '' ? '.'.$extension : ''), 'local');

        if ($path === false) {
            throw new RuntimeException('The file could not be saved. Try again.');
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', basename($upload->getClientOriginalName())));

        ClientFile::query()->create([
            'brand_id' => $resource->brand_id,
            'fileable_type' => $resource->getMorphClass(),
            'fileable_id' => $resource->getKey(),
            'category' => 'document',
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr($name !== '' ? $name : 'file', 0, 190),
            'mime' => $upload->getMimeType(),
            'size' => (int) $upload->getSize(),
            'uploaded_by' => $by->getKey(),
        ]);

        $resource->unsetRelation('file');
    }

    private function forgetFile(BrandResource $resource): void
    {
        $file = $resource->file()->first();

        if ($file !== null) {
            Storage::disk($file->disk)->delete($file->path);
            $file->delete();
        }

        $resource->unsetRelation('file');
    }
}
