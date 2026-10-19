<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Resources\Models\BrandResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /b/{brand}/resources/{resource}/file: the only way to a resource's
 * file, for anyone in the brand. PDFs and pictures open in the browser when
 * asked (?open=1); everything else is downloaded, so nothing uploaded can run
 * on this address.
 */
final class ResourceFileController
{
    public function __invoke(Request $request, Brand $brand, int $resource): StreamedResponse
    {
        $resource = BrandResource::query()->with('file')->findOrFail($resource);

        Gate::authorize('view', $resource);

        $file = $resource->file ?? abort(404, 'This resource has no file.');
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
