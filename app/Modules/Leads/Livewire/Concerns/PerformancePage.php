<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\Concerns;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Leads\Support\LeadInsights;
use Livewire\Attributes\Url;

/**
 * What every screen of the Performance tab shares: who may open it (Admins and the brand's Managers),
 * the people shown (the viewer's own: everybody for an Admin, a Manager's teams), the month picker and
 * the row of links between the screens.
 */
trait PerformancePage
{
    public Brand $brand;

    /** Y-m in the viewer's calendar; empty is this month. */
    #[Url(except: '')]
    public string $month = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;

        abort_unless(self::allowed($this->user(), $brand), 403);
    }

    /** Admins and the brand's Managers; never an Agent. */
    public static function allowed(User $user, Brand $brand): bool
    {
        return $user->isGlobalAdmin() || $user->isManagerIn($brand);
    }

    /**
     * The screens of the tab, in the menu's order: route => label.
     *
     * @return array<string, string>
     */
    public static function screens(): array
    {
        return array_filter([
            'brand.performance.leaderboard' => 'Leaderboard',
            'brand.leads.taken' => 'Leads taken',
            'brand.performance.speed' => 'Response speed',
            'brand.performance.activity' => 'Activity',
            'brand.performance.conversion' => 'Conversion',
            'brand.performance.lost' => LeadInsights::ready() ? 'Lost reasons' : null,
        ]);
    }

    /** The month shown, Y-m: the one picked when it is a real month not in the future, else this month. */
    protected function shownMonth(): string
    {
        $now = ViewerTime::now()->format('Y-m');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 && $this->month <= $now ? $this->month : $now;
    }

    /**
     * The last twelve months for the picker: Y-m => "November 2026".
     *
     * @return array<string, string>
     */
    protected function monthOptions(): array
    {
        $first = ViewerTime::now()->startOfMonth();
        $options = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $first->subMonthsNoOverflow($i);
            $options[$month->format('Y-m')] = $month->format('F Y');
        }

        return $options;
    }

    /**
     * The people the viewer looks after, with what the assign menu knows of each.
     *
     * @return list<array{id: int, name: string, picture: ?string, team: string, departments: list<int>, me: bool}>
     */
    protected function people(): array
    {
        return app(AssignableAgents::class)->people($this->user(), $this->brand);
    }

    /** @return list<int> */
    protected function peopleIds(): array
    {
        return array_map(fn (array $person): int => $person['id'], $this->people());
    }

    /**
     * The viewer's people, each with their numbers under `n`, busiest first by one of them.
     *
     * @template T of array
     *
     * @param  array<int, T>  $numbers
     * @return list<array{id: int, name: string, picture: ?string, team: string, departments: list<int>, me: bool, n: T}>
     */
    protected function rowsWith(array $numbers, string $sortBy): array
    {
        $rows = array_map(fn (array $person): array => $person + ['n' => $numbers[$person['id']]], $this->people());

        usort($rows, fn (array $a, array $b): int => ($b['n'][$sortBy] ?? 0) <=> ($a['n'][$sortBy] ?? 0));

        return $rows;
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
