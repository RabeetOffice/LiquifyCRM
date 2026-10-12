<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Number;

/**
 * One file in a client's storage: a contract, an invoice, anything. It is a
 * row of the `files` table the architecture document designs (polymorphic,
 * private disk): a file added on the client's page belongs to the client,
 * one that front sales adds from the won lead belongs to that lead, and the
 * client's storage shows both. It is only ever served through the download
 * route, which checks who is asking.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $fileable_type
 * @property int $fileable_id
 * @property string $category
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size
 * @property string $visibility
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $uploader
 */
class ClientFile extends Model
{
    use BelongsToBrand;

    /** Largest file a person may add, in kilobytes (20 MB). */
    public const MAX_KB = 20480;

    /** What a file is, in the words of the people who file it. */
    public const CATEGORIES = [
        'contract' => 'Contract',
        'invoice' => 'Invoice',
        'document' => 'Document',
        'other' => 'Other',
    ];

    /** Never stored: files a web server or a computer could run. */
    public const BLOCKED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'pht', 'htaccess', 'cgi', 'pl', 'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'sh', 'vbs', 'ps1', 'jar'];

    /** Shown in the browser; everything else is downloaded. */
    private const INLINE = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    protected $table = 'files';

    protected $fillable = ['brand_id', 'fileable_type', 'fileable_id', 'category', 'disk', 'path', 'original_name', 'mime', 'size', 'visibility', 'uploaded_by', 'expires_at'];

    protected $attributes = ['category' => 'document', 'disk' => 'local', 'mime' => null, 'size' => 0, 'visibility' => 'private', 'uploaded_by' => null, 'expires_at' => null];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The client or the lead the file was added to.
     *
     * @return MorphTo<Model, $this>
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Everything in a client's storage: the files added to the client and
     * the ones front sales added to the leads that became this client.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfClient(Builder $query, Client $client): Builder
    {
        return $query->where(fn (Builder $inner): Builder => $inner
            ->where(fn (Builder $own): Builder => $own
                ->where('fileable_type', $client->getMorphClass())
                ->where('fileable_id', $client->getKey()))
            ->orWhere(fn (Builder $fromLead): Builder => $fromLead
                ->where('fileable_type', (new Lead)->getMorphClass())
                ->whereIn('fileable_id', Lead::query()->forBrand((int) $client->brand_id)->withTrashed()->where('client_id', $client->getKey())->select('id'))));
    }

    /**
     * The files front sales added from one lead.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfLead(Builder $query, Lead|int $lead): Builder
    {
        return $query->where('fileable_type', (new Lead)->getMorphClass())->where('fileable_id', $lead instanceof Lead ? $lead->getKey() : $lead);
    }

    /** The lead this file was added from, when it was. */
    public function leadId(): ?int
    {
        return $this->fileable_type === (new Lead)->getMorphClass() ? (int) $this->fileable_id : null;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Document';
    }

    public function extension(): string
    {
        return mb_strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/') && $this->opensInBrowser();
    }

    public function opensInBrowser(): bool
    {
        return in_array($this->mime, self::INLINE, true);
    }

    public function humanSize(): string
    {
        return Number::fileSize($this->size, precision: $this->size >= 1048576 ? 1 : 0);
    }

    /** The heroicon that says what kind of file this is. */
    public function icon(): string
    {
        return match (true) {
            $this->isImage() => 'heroicon-o-photo',
            $this->mime === 'application/pdf' => 'heroicon-o-document-text',
            in_array($this->extension(), ['xls', 'xlsx', 'csv', 'ods'], true) => 'heroicon-o-table-cells',
            in_array($this->extension(), ['zip', 'rar', '7z', 'gz'], true) => 'heroicon-o-archive-box',
            in_array($this->extension(), ['mp4', 'mov', 'avi', 'mkv', 'webm'], true) => 'heroicon-o-film',
            in_array($this->extension(), ['mp3', 'wav', 'm4a', 'ogg'], true) => 'heroicon-o-musical-note',
            default => 'heroicon-o-document',
        };
    }
}
