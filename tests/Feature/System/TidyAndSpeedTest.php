<?php

declare(strict_types=1);

use App\Modules\Auth\Livewire\Profile;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\KnownSchema;
use App\Modules\Core\Support\SmallPhoto;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

/*
 * The server answers faster and its database stays small (12 Nov 2026): "is this table there yet?" is remembered
 * instead of asked on every request, the tables nothing emptied are tidied at night, a profile photo is kept small,
 * and the logs table lost the two indexes it had twice.
 */

function schemaQuestions(callable $work): int
{
    $asked = 0;
    DB::listen(function ($query) use (&$asked): void {
        $asked += (int) str_contains(strtolower($query->sql), 'information_schema');
    });

    $work();

    return $asked;
}

it('remembers that a table or column exists and does not ask the database again', function () {
    KnownSchema::forget();

    expect(KnownSchema::table('leads'))->toBeTrue()
        ->and(KnownSchema::column('leads', 'name'))->toBeTrue();

    // A new request: nothing in memory, only the cache entry.
    (new ReflectionProperty(KnownSchema::class, 'known'))->setValue(null, null);

    $asked = schemaQuestions(function (): void {
        expect(KnownSchema::table('leads'))->toBeTrue()
            ->and(KnownSchema::column('leads', 'name'))->toBeTrue();
    });

    expect($asked)->toBe(0)
        ->and(Cache::get('schema:known'))->toHaveKeys(['leads', 'leads.name']);
});

it('asks again about a table that is not there yet, so it is seen the moment the update adds it', function () {
    KnownSchema::forget();

    expect(KnownSchema::table('not_made_yet'))->toBeFalse()
        ->and(Cache::get('schema:known'))->toBeNull();

    Schema::create('not_made_yet', fn ($table) => $table->id());

    expect(KnownSchema::table('not_made_yet'))->toBeTrue();

    Schema::drop('not_made_yet');
    KnownSchema::forget();
});

it('removes bell entries read more than 90 days ago and keeps the rest', function () {
    $user = User::factory()->create();
    $row = fn (?string $readAt): array => [
        'id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->getKey(),
        'data' => '{}', 'read_at' => $readAt, 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200),
    ];

    DB::table('notifications')->insert([
        $old = $row(now()->subDays(91)->toDateTimeString()),
        $recent = $row(now()->subDays(10)->toDateTimeString()),
        $unread = $row(null),
    ]);

    $this->artisan('liquify:tidy')->assertSuccessful();

    expect(DB::table('notifications')->pluck('id')->all())
        ->not->toContain($old['id'])
        ->toContain($recent['id'], $unread['id']);
});

it('prunes the health checks’ results every night', function () {
    $events = collect(app(Schedule::class)->events());
    $prune = $events->first(fn ($event): bool => str_contains((string) $event->command, 'model:prune'));

    expect($prune)->not->toBeNull()
        ->and((string) $prune->command)->toContain(HealthCheckResultHistoryItem::class)
        ->and($events->contains(fn ($event): bool => str_contains((string) $event->command, 'liquify:tidy')))->toBeTrue();
});

it('keeps an uploaded profile photo small', function () {
    Storage::fake('public');
    $user = User::factory()->create()->fresh();

    Livewire::actingAs($user)->test(Profile::class)
        ->set('avatar', UploadedFile::fake()->image('me.png', 2000, 1500))
        ->assertHasNoErrors();

    $path = (string) $user->refresh()->avatar_path;
    [$width, $height] = getimagesizefromstring((string) Storage::disk('public')->get($path));

    expect($path)->toStartWith('avatars/')
        ->and(max($width, $height))->toBe(SmallPhoto::SIDE)
        ->and($width / $height)->toEqualWithDelta(2000 / 1500, 0.01);
});

it('makes a heavy photo kept before small, and leaves a light one alone', function () {
    Storage::fake('public');

    // Noise does not compress: a real heavy file.
    $image = imagecreatetruecolor(900, 900);
    for ($i = 0; $i < 60000; $i++) {
        imagesetpixel($image, random_int(0, 899), random_int(0, 899), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagepng($image, null, 0);
    $heavy = (string) ob_get_clean();
    expect(strlen($heavy))->toBeGreaterThan(SmallPhoto::HEAVY);

    Storage::disk('public')->put('avatars/heavy.png', $heavy);
    Storage::disk('public')->put('avatars/light.png', UploadedFile::fake()->image('l.png', 40, 40)->get());

    $big = User::factory()->create(['avatar_path' => 'avatars/heavy.png']);
    $light = User::factory()->create(['avatar_path' => 'avatars/light.png']);

    expect(SmallPhoto::shrinkStored())->toBe(1);

    $path = (string) $big->refresh()->avatar_path;

    expect($path)->not->toBe('avatars/heavy.png')
        ->and(Storage::disk('public')->exists('avatars/heavy.png'))->toBeFalse()
        ->and(Storage::disk('public')->size($path))->toBeLessThan(strlen($heavy))
        ->and($light->refresh()->avatar_path)->toBe('avatars/light.png');
});

it('keeps one index of each kind on the logs table', function () {
    $names = collect(Schema::getIndexes('activity_log'))->pluck('name');

    expect($names)->toContain('activity_log_brand_id_created_at_index', 'activity_log_created_at_index')
        ->not->toContain('activity_log_brand_created_index', 'activity_log_created_index');
});
