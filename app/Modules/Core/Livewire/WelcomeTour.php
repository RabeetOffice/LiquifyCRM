<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Sales\Support\CareTeams;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The short welcome tour for a new Manager or Agent on first sign-in: three
 * tips (the New queue, Reveal, follow-ups), skippable, shown once. Someone
 * on an Upsell team gets theirs about clients instead: where they arrive,
 * recording an upsell, and the client's files and saved text.
 */
final class WelcomeTour extends Component
{
    public bool $open = false;

    public function mount(): void
    {
        $user = auth()->user();
        $brand = app(CurrentBrand::class)->get();

        $this->open = $user instanceof User
            && $brand !== null
            && ! $user->isGlobalAdmin()
            && $user->getAttribute('tour_seen_at') === null;
    }

    /** Finished or skipped: either way it never shows again. */
    public function done(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $user->forceFill(['tour_seen_at' => now()])->save();
        }

        $this->open = false;
    }

    public function render(): View
    {
        $user = auth()->user();
        $brand = app(CurrentBrand::class)->get();
        $manager = $user instanceof User && $brand !== null && $user->isManagerIn($brand);

        if ($user instanceof User && $brand !== null && CareTeams::onlyHome($user, $brand)) {
            return view('core::livewire.welcome-tour', [
                'tips' => [
                    $manager
                        ? ['icon' => 'heroicon-o-user-group', 'title' => 'New clients arrive from front sales', 'body' => 'A client handed to your department shows under Clients, in "Waiting for a person". Tick the ones you want and give them to someone, or turn on round robin under Distribute leads.']
                        : ['icon' => 'heroicon-o-user-group', 'title' => 'Your clients are under My clients', 'body' => 'Front sales hands a client to you after the first sale, or your Manager gives you one. You get a notification; open the client to see what they bought and from whom.'],
                    ['icon' => 'heroicon-o-arrow-trending-up', 'title' => 'Record every upsell on the client', 'body' => 'When a client buys again, click "Record an upsell". It goes under the name of whoever sold it, counts towards their target and joins the client\'s story.'],
                    ['icon' => 'heroicon-o-folder-open', 'title' => 'Keep their files and logins with them', 'body' => 'Add the contract and the invoices under Files, and save a login or instructions as text. Text marked hidden stays covered until someone clicks Show.'],
                ],
            ]);
        }

        // Front sales sees the team's leads and takes the free ones (19 Oct 2026).
        $team = ! $manager && $user instanceof User && $brand !== null && $user->frontDepartmentIds($brand) !== [];

        return view('core::livewire.welcome-tour', [
            'tips' => [
                match (true) {
                    $manager => ['icon' => 'heroicon-o-inbox-arrow-down', 'title' => 'New leads wait in your queue', 'body' => 'Leads sent to your department appear at the top of your dashboard, oldest first. Assign each one with one click, or take it yourself.'],
                    $team => ['icon' => 'heroicon-o-hand-raised', 'title' => 'Take a free lead', 'body' => 'Every new lead of your team shows under Leads and on your dashboard the moment it arrives, with a sound. Click Take on a free one and it is yours; the others see your name on it.'],
                    default => ['icon' => 'heroicon-o-inbox-arrow-down', 'title' => 'Your leads are under My leads', 'body' => 'You see a lead as soon as your Manager assigns it to you, and you get a notification. Open it to read the message and start working.'],
                },
                ['icon' => 'heroicon-o-eye', 'title' => 'Reveal shows the contact details', 'body' => match (true) {
                    $manager => 'Agents see phone numbers and emails masked until they take a lead or click Reveal on theirs. Every reveal is logged, with a limit per hour.',
                    $team => 'Phone numbers and emails open when you take a lead; on a lead given to you, click Reveal. Each reveal is logged, and there is a limit per hour.',
                    default => 'Phone numbers and emails are masked until you click Reveal on a lead. Each reveal is logged, and there is a limit per hour.',
                }],
                ['icon' => 'heroicon-o-calendar-days', 'title' => 'Follow-ups keep leads moving', 'body' => 'Click "Follow up on" to set a date and a short note. Everything due is listed under Follow-ups, with overdue ones first.'],
            ],
        ]);
    }
}
