<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /b/{brand}/clients/{client}/files/{file}: the only way to a client's
 * file. Pictures and PDFs open in the browser when asked (?open=1); every
 * other kind is always downloaded, so nothing a person uploaded can run on
 * this address.
 */
final class ClientFileController
{
    public function __invoke(Request $request, Brand $brand, Client $client, int $file): StreamedResponse
    {
        $file = ClientFile::query()->ofClient($client)->findOrFail($file);

        Gate::authorize('viewFile', [$client, $file]);

        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists($file->path), 404, 'This file is no longer in storage.');

        $headers = [
            'Content-Type' => $file->opensInBrowser() ? (string) $file->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        return $request->boolean('open') && $file->opensInBrowser()
            ? $disk->response($file->path, $file->original_name, $headers)
            : $disk->download($file->path, $file->original_name, $headers);
    }
}
