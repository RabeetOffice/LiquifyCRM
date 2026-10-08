<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The short welcome tour for a new Manager or Agent on first sign-in: three
 * tips (the New queue, Reveal, follow-ups), skippable, shown once.
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

        return view('core::livewire.welcome-tour', [
            'tips' => [
                $manager
                    ? ['icon' => 'heroicon-o-inbox-arrow-down', 'title' => 'New leads wait in your queue', 'body' => 'Leads sent to your department appear at the top of your dashboard, oldest first. Assign each one to an Agent with one click.']
                    : ['icon' => 'heroicon-o-inbox-arrow-down', 'title' => 'Your leads are under My leads', 'body' => 'You see a lead as soon as your Manager assigns it to you, and you get a notification. Open it to read the message and start working.'],
                ['icon' => 'heroicon-o-eye', 'title' => 'Reveal shows the contact details', 'body' => $manager
                    ? 'Agents see phone numbers and emails masked until they click Reveal on a lead. Every reveal is logged, with a limit per hour.'
                    : 'Phone numbers and emails are masked until you click Reveal on a lead. Each reveal is logged, and there is a limit per hour.'],
                ['icon' => 'heroicon-o-calendar-days', 'title' => 'Follow-ups keep leads moving', 'body' => 'Click "Follow up on" to set a date and a short note. Everything due is listed under Follow-ups, with overdue ones first.'],
            ],
        ]);
    }
}
