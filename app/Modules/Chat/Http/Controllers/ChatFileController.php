<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to a file of a website chat. A visitor's browser gets a link signed for six hours and good for
 * that one file (`visitor`); the team opens it signed in, and only if they may see the chat (`team`). A
 * picture is shown in place; everything else downloads. The browser is told not to guess the kind of file and
 * to run nothing from it.
 */
final class ChatFileController
{
    /** Not in the web group (no session, no cookie), so the file is looked up here, not by route binding. */
    public function visitor(int $attachment): StreamedResponse
    {
        return $this->send(ChatAttachment::query()->findOrFail($attachment));
    }

    public function team(Brand $brand, ChatAttachment $attachment): StreamedResponse
    {
        $conversation = $attachment->message->conversation;

        abort_unless($conversation->brand_id === $brand->getKey() && (bool) auth()->user()?->can('view', $conversation), 404);

        return $this->send($attachment);
    }

    private function send(ChatAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=600',
        ], $attachment->isImage() ? 'inline' : 'attachment');
    }
}
