<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Put a file in a client's storage, or take one out. Files go to the
 * private disk under a random name, so nothing a person uploads can be
 * opened by address or run by the server; the name they gave it is kept
 * for the download. A file front sales adds from the won lead is attached
 * to that lead; any other to the client.
 */
final class StoreClientFile
{
    /**
     * The rules for one uploaded file, for the screens that take them.
     *
     * @return list<string|Closure>
     */
    public static function rules(): array
    {
        return [
            'file',
            'max:'.ClientFile::MAX_KB,
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value instanceof UploadedFile && in_array(mb_strtolower($value->getClientOriginalExtension()), ClientFile::BLOCKED, true)) {
                    $fail('Files of the type .'.mb_strtolower($value->getClientOriginalExtension()).' cannot be stored. Put it in a zip file first.');
                }
            },
        ];
    }

    public function handle(Client $client, UploadedFile $upload, string $category, User $by, ?Lead $lead = null): ClientFile
    {
        $extension = mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', $upload->getClientOriginalExtension()) ?? '');

        if (in_array($extension, ClientFile::BLOCKED, true)) {
            throw new RuntimeException('Files of this type cannot be stored.');
        }

        $path = $upload->storeAs(
            'clients/'.$client->brand_id.'/'.$client->getKey(),
            Str::lower((string) Str::ulid()).($extension !== '' ? '.'.$extension : ''),
            'local',
        );

        if ($path === false) {
            throw new RuntimeException('The file could not be saved. Try again.');
        }

        $file = ClientFile::query()->create([
            'brand_id' => $client->brand_id,
            'fileable_type' => ($lead ?? $client)->getMorphClass(),
            'fileable_id' => ($lead ?? $client)->getKey(),
            'category' => array_key_exists($category, ClientFile::CATEGORIES) ? $category : 'document',
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->cleanName($upload->getClientOriginalName()),
            'mime' => $upload->getMimeType(),
            'size' => (int) $upload->getSize(),
            'uploaded_by' => $by->getKey(),
        ]);

        Audit::log('clients.file_added', $client, ['file_id' => $file->getKey(), 'name' => $file->original_name, 'category' => $file->category, 'size' => $file->size, 'lead_id' => $lead?->getKey()], $file->categoryLabel().' added: '.$file->original_name, brand: (int) $client->brand_id, causer: $by, logName: 'sales');

        return $file;
    }

    public function delete(Client $client, ClientFile $file, User $by): void
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();

        Audit::log('clients.file_removed', $client, ['file_id' => $file->getKey(), 'name' => $file->original_name], 'File removed: '.$file->original_name, brand: $file->brand_id, causer: $by, logName: 'sales');
    }

    /** The name as the person had it, without anything a path or a header could trip over. */
    private function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', basename($name)));

        return mb_substr($name !== '' ? $name : 'file', 0, 190);
    }
}
