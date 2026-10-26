<?php

declare(strict_types=1);

use App\Modules\Core\Support\Live;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * Tabs left open over a deploy (26 October). A screen's request failed while
 * the site was deploying, its error showed "Try again", and that link opened
 * Livewire's own address (/livewire-…/update) as a page: a bare 405. People
 * also had to refresh every open tab by hand after an update.
 */

it('tries a failed screen request again on the page the person was on, never on Livewire\'s address', function (): void {
    Route::post('/__deploying', fn () => abort(503))->middleware('web');
    $page = url('/b/ukph/leads');

    $this->withHeaders(['X-Livewire' => '1', 'Referer' => $page])
        ->post('/__deploying')
        ->assertServiceUnavailable()
        ->assertSee('Try again')
        ->assertSee('href="'.$page.'"', escape: false)
        ->assertDontSee('__deploying');

    // A page of another site is never where "Try again" leads.
    $this->withHeaders(['Referer' => 'https://elsewhere.example/b/ukph/leads'])
        ->post('/__deploying')
        ->assertSee('href="'.url('/').'"', escape: false)
        ->assertDontSee('elsewhere.example');
});

it('tries a page that failed again with its own address, filters included', function (): void {
    Route::get('/__busy', fn () => abort(503))->middleware('web');

    $this->get('/__busy?view=mine')
        ->assertServiceUnavailable()
        ->assertSee('href="'.url('/__busy?view=mine').'"', escape: false);
});

it('answers Livewire\'s address opened as a page in its own words', function (): void {
    $this->get(Livewire::getUpdateUri())
        ->assertMethodNotAllowed()
        ->assertSee('This address is not a page')
        ->assertSee('Go to the start')
        ->assertDontSee('Oops! An Error Occurred');
});

it('names the code that drew a page and the code on the server, so a tab left open over a deploy loads again', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $admin = admin();
    $build = Live::build();

    expect($build)->toMatch('/^[0-9a-f]{12}$/');

    $this->actingAs($admin)->get('/b/ukph/leads')
        ->assertOk()
        ->assertSee('data-live-build="'.$build.'"', escape: false)
        ->assertSee('data-new-version hidden', escape: false);

    $this->getJson('/api/live?t='.urlencode(Live::token($admin->getKey(), $brand->getKey())))
        ->assertOk()
        ->assertJsonPath('v', $build);
});
