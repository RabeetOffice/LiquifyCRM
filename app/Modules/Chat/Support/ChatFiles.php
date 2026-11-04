<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Chat\Models\ChatAttachment;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files in a website chat. Only a short list of kinds is taken (pictures, PDF, Word, text), judged by the file's
 * own content and not by what the sender calls it; every file gets a random name and lies on the private disk;
 * nothing is ever run. A file is shown or downloaded only through ChatFileController.
 */
final class ChatFiles
{
    /** Extension => what the content of such a file may be recognised as. */
    private const KINDS = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];

    /** What the file picker offers. */
    public static function accept(): string
    {
        return implode(',', array_map(fn (string $extension): string => '.'.$extension, array_keys(self::KINDS)));
    }

    /** The largest file a widget takes, in bytes. */
    public static function maxBytes(?ChatWidget $widget): int
    {
        $appearance = $widget !== null ? ($widget->appearance ?? []) : [];
        $mb = (int) ($appearance['max_upload_mb'] ?? config('liquify.chat.max_upload_mb', 10));

        return max(1, min(25, $mb)) * 1048576;
    }

    /** Why this file is not taken, in words for the person who chose it; null when it is fine. */
    public static function refusal(UploadedFile $file, int $maxBytes): ?string
    {
        if (! $file->isValid()) {
            return 'The file could not be uploaded. Please try again.';
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! isset(self::KINDS[$extension])) {
            return 'This kind of file is not accepted. You can send pictures, PDF, Word documents and text files.';
        }

        if ($file->getSize() === 0) {
            return 'That file is empty.';
        }

        if ($file->getSize() > $maxBytes) {
            return 'That file is too big. The most we take is '.(int) ($maxBytes / 1048576).' MB.';
        }

        $path = (string) $file->getRealPath();
        $found = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! in_array($found, self::KINDS[$extension], true)) {
            return 'That file is not what its name says. Please send a picture, a PDF, a Word document or a text file.';
        }

        // A picture must really be one, and a text file must not be a web page in disguise.
        if (str_starts_with($found, 'image/') && @getimagesize($path) === false) {
            return 'That picture could not be read.';
        }

        if ($extension === 'txt' && preg_match('/<\s*(script|html|iframe|svg)\b/i', (string) file_get_contents($path, false, null, 0, 4096)) === 1) {
            return 'That file is not plain text.';
        }

        return null;
    }

    /** Keep the file and tie it to the message. The caller has already asked refusal(). */
    public static function keep(UploadedFile $file, Message $message, Conversation $conversation): ChatAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $directory = 'chat/'.$conversation->brand_id.'/'.now()->format('Y/m');
        $name = Str::random(40).'.'.$extension;
        $mime = self::KINDS[$extension][0];

        Storage::disk('local')->putFileAs($directory, $file, $name);

        return ChatAttachment::query()->create([
            'message_id' => $message->getKey(),
            'disk' => 'local',
            'path' => $directory.'/'.$name,
            'original_name' => self::cleanName($file->getClientOriginalName()),
            'mime' => str_starts_with((string) $mime, 'application/octet') ? 'application/octet-stream' : $mime,
            'size' => (int) $file->getSize(),
        ]);
    }

    /** The name as shown: no path, no control characters, not longer than a line. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name);

        return mb_substr($name !== '' ? $name : 'file', 0, 150);
    }

    /** Delete the files from the disk and their rows. */
    public static function forget(iterable $attachments): int
    {
        $count = 0;

        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
            $attachment->delete();
            $count++;
        }

        return $count;
    }
}
