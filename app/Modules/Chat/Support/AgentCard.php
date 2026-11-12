<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The team member a visitor is chatting with, as the chat box shows them (user request, 12 Nov 2026): their live chat
 * name (else first name), title (else designation) and photo (their live chat photo, else a small copy of their profile
 * photo), the photo as a data address so it needs no request to LiquifyCRM's address.
 */
final class AgentCard
{
    /** Who looks after this chat: the person it was given to, its lead's owner, else whoever answered last. */
    public static function agentOf(Conversation $conversation): ?User
    {
        $id = (LiveChat::workReady() ? $conversation->chat_assigned_to : null)
            ?? $conversation->lead->assigned_to
            ?? Message::query()->where('conversation_id', $conversation->getKey())->where('direction', Message::OUT)
                ->where('kind', 'text')->whereNotNull('sent_by')->latest('id')->value('sent_by');

        return $id !== null ? User::query()->find($id) : null;
    }

    /** @return array{id: int, name: string, title: ?string, photo: ?string} */
    public static function of(User $user, bool $withPhoto = true): array
    {
        $ready = self::ready();
        $name = $ready && filled($user->getAttribute('chat_name')) ? (string) $user->getAttribute('chat_name') : self::firstName($user->name);
        $title = $ready && filled($user->getAttribute('chat_title')) ? (string) $user->getAttribute('chat_title') : ($user->designation ?: null);

        return [
            'id' => (int) $user->getKey(),
            'name' => $name,
            'title' => $title,
            'photo' => $withPhoto ? self::photo($user) : null,
        ];
    }

    /** The photo as a small data address, or null. A profile photo is made small once and kept a day. */
    public static function photo(User $user): ?string
    {
        if (self::ready() && is_string($user->getAttribute('chat_photo')) && str_starts_with((string) $user->getAttribute('chat_photo'), 'data:image/')) {
            return (string) $user->getAttribute('chat_photo');
        }

        $path = $user->avatar_path;

        if (! is_string($path) || $path === '') {
            return null;
        }

        return Cache::remember('chat-agent-photo:'.$user->getKey().':'.md5($path), 86400, function () use ($path): ?string {
            try {
                $disk = Storage::disk('public');

                return $disk->exists($path) ? self::small((string) $disk->get($path)) : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    /** A picture made square, 160 pixels, as a WebP (or JPEG) data address; null when it is not a picture. */
    public static function small(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);
        $square = imagecreatetruecolor(160, 160);
        $white = (int) imagecolorallocate($square, 255, 255, 255);
        imagefill($square, 0, 0, $white);
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), 160, 160, $side, $side);

        ob_start();
        $webp = function_exists('imagewebp') && imagewebp($square, null, 82);

        if (! $webp) {
            imagejpeg($square, null, 85);
        }

        $data = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($square);

        return $data !== '' ? 'data:image/'.($webp ? 'webp' : 'jpeg').';base64,'.base64_encode($data) : null;
    }

    public static function ready(): bool
    {
        return LiveChat::agentCardReady();
    }

    private static function firstName(string $name): string
    {
        return explode(' ', trim($name))[0];
    }
}
