<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Which leads a person took for themselves (user request, 27 Nov 2026: Admins and Managers see how
 * many leads one person took). A take is the timeline line AssignLead writes when somebody gives a
 * lead to themselves ("Taken by …": `leads.assigned`, caused by the person it went to), so older
 * takes count too. A take the same person gave back within UNDO_MINUTES (Undo, "taken by mistake")
 * does not count. The lines stay as long as the lead does (LogArchiver keeps a lead's timeline).
 */
final class LeadTakes
{
    public const UNDO_MINUTES = 10;

    /** The periods offered beside "Taken by": key => words. Empty is any time. */
    public const PERIODS = [
        'today' => 'Today',
        'week' => 'Last 7 days',
        'month' => 'This month',
        'last-month' => 'Last month',
    ];

    /** The ids of the leads of a brand this person took, in the period, as a subquery for whereIn. */
    public static function leadIds(User|int $person, int $brandId, string $period = ''): QueryBuilder
    {
        $id = $person instanceof User ? (int) $person->getKey() : $person;
        $table = (new Activity)->getTable();
        [$since, $until] = self::window($period);

        return DB::table($table)
            ->select("{$table}.subject_id")
            ->where("{$table}.brand_id", $brandId)
            ->where("{$table}.event", 'leads.assigned')
            ->where("{$table}.subject_type", Lead::class)
            ->where("{$table}.causer_type", User::class)
            ->where("{$table}.causer_id", $id)
            ->where("{$table}.properties->to", $id)
            ->when($since !== null, fn (QueryBuilder $q): QueryBuilder => $q->where("{$table}.created_at", '>=', $since))
            ->when($until !== null, fn (QueryBuilder $q): QueryBuilder => $q->where("{$table}.created_at", '<=', $until))
            // Given back by the same person straight after (Undo): a mistake, not a take.
            ->whereNotExists(fn (QueryBuilder $undo): QueryBuilder => $undo->selectRaw('1')
                ->from("{$table} as undo")
                ->whereColumn('undo.subject_id', "{$table}.subject_id")
                ->where('undo.subject_type', Lead::class)
                ->where('undo.event', 'leads.unassigned')
                ->whereColumn('undo.causer_id', "{$table}.causer_id")
                ->whereColumn('undo.id', '>', "{$table}.id")
                ->whereRaw("undo.created_at <= {$table}.created_at + INTERVAL ".self::UNDO_MINUTES.' MINUTE')
                // ...and nobody had it in between: an Undo cancels only the take right before it.
                ->whereNotExists(fn (QueryBuilder $between): QueryBuilder => $between->selectRaw('1')
                    ->from("{$table} as between_take")
                    ->whereColumn('between_take.subject_id', "{$table}.subject_id")
                    ->where('between_take.subject_type', Lead::class)
                    ->where('between_take.event', 'leads.assigned')
                    ->whereColumn('between_take.id', '>', "{$table}.id")
                    ->whereColumn('between_take.id', '<', 'undo.id')));
    }

    /**
     * The period as stored (UTC) moments, from the viewer's own calendar.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function window(string $period): array
    {
        $now = ViewerTime::now();
        $utc = (string) config('app.timezone');

        return match ($period) {
            'today' => [ViewerTime::startOfToday(), ViewerTime::endOfToday()],
            'week' => [$now->subDays(6)->startOfDay()->setTimezone($utc), ViewerTime::endOfToday()],
            'month' => [$now->startOfMonth()->setTimezone($utc), ViewerTime::endOfToday()],
            'last-month' => [$now->subMonthNoOverflow()->startOfMonth()->setTimezone($utc), $now->startOfMonth()->subSecond()->setTimezone($utc)],
            default => [null, null],
        };
    }
}
