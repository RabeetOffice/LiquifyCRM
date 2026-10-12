<?php

declare(strict_types=1);

/*
 * The privacy policy and "delete your data" pages are public: the Meta app
 * links to both, so they must open without signing in.
 */

it('shows the privacy policy to anyone', function (): void {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Privacy policy')
        ->assertSee('What we collect')
        ->assertSee(config('liquify.legal.email'))
        ->assertSee(route('legal.data-deletion'));
});

it('shows how to have data deleted to anyone', function (): void {
    $this->get('/data-deletion')
        ->assertOk()
        ->assertSee('Delete your data')
        ->assertSee('How to ask')
        ->assertSee(config('liquify.legal.facebook_app'))
        ->assertSee('mailto:'.config('liquify.legal.email').'?subject=Delete%20my%20data', escape: false)
        ->assertSee(route('legal.privacy'));
});

it('names whoever runs the installation and their contact address', function (): void {
    config()->set('liquify.legal.name', 'Northwind Sales Desk');
    config()->set('liquify.legal.email', 'privacy@northwind.test');

    $this->get('/privacy')->assertSee('Northwind Sales Desk')->assertSee('privacy@northwind.test');
    $this->get('/data-deletion')->assertSee('Northwind Sales Desk')->assertSee('privacy@northwind.test');
});

it('links both pages from the sign-in screen', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee(route('legal.privacy'))
        ->assertSee(route('legal.data-deletion'));
});

it('opens for a signed-in person too', function (): void {
    $this->actingAs(admin())->get('/privacy')->assertOk();
    $this->actingAs(admin())->get('/data-deletion')->assertOk();
});
