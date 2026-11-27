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
        $table = self::table();
        [$since, $until] = self::window($period);

        return self::takes($brandId)
            ->select("{$table}.subject_id")
            ->where("{$table}.causer_id", $id)
            ->where("{$table}.properties->to", $id)
            ->when($since !== null, fn (QueryBuilder $q): QueryBuilder => $q->where("{$table}.created_at", '>=', $since))
            ->when($until !== null, fn (QueryBuilder $q): QueryBuilder => $q->where("{$table}.created_at", '<=', $until));
    }

    /**
     * Per person: how many leads they took today, this month, last month and ever, how many of those are still
     * theirs and how many were won. A lead taken twice by the same person counts once in each column.
     *
     * @param  list<int>  $people
     * @return array<int, array{today: int, month: int, last_month: int, all: int, theirs: int, won: int}>
     */
    public static function perPerson(int $brandId, array $people): array
    {
        $table = self::table();
        $counts = array_fill_keys($people, ['today' => 0, 'month' => 0, 'last_month' => 0, 'all' => 0, 'theirs' => 0, 'won' => 0]);

        if ($people === []) {
            return $counts;
        }

        $rows = self::takes($brandId)
            ->join('leads', 'leads.id', '=', "{$table}.subject_id")
            ->leftJoin('lead_statuses', 'lead_statuses.id', '=', 'leads.status_id')
            ->whereNull('leads.deleted_at')
            ->whereIn("{$table}.causer_id", $people)
            ->whereColumn("{$table}.properties->to", "{$table}.causer_id")
            ->select(["{$table}.causer_id", "{$table}.subject_id", "{$table}.created_at", 'leads.assigned_to', 'lead_statuses.is_won'])
            ->get();

        $periods = ['today' => self::window('today'), 'month' => self::window('month'), 'last_month' => self::window('last-month')];
        $seen = [];

        foreach ($rows as $row) {
            $person = (int) $row->causer_id;
            $lead = (int) $row->subject_id;
            $at = CarbonImmutable::parse((string) $row->created_at, (string) config('app.timezone'));

            foreach ($periods as $key => [$since, $until]) {
                if ($since !== null && $until !== null && $at->betweenIncluded($since, $until) && ! isset($seen[$key][$person][$lead])) {
                    $seen[$key][$person][$lead] = true;
                    $counts[$person][$key]++;
                }
            }

            if (isset($seen['all'][$person][$lead])) {
                continue;
            }

            $seen['all'][$person][$lead] = true;
            $counts[$person]['all']++;
            $counts[$person]['theirs'] += (int) $row->assigned_to === $person ? 1 : 0;
            $counts[$person]['won'] += (bool) $row->is_won ? 1 : 0;
        }

        return $counts;
    }

    /**
     * How many leads each person took between two stored moments, [since, until).
     *
     * @param  list<int>  $people
     * @return array<int, int>
     */
    public static function countsBetween(int $brandId, array $people, CarbonImmutable $since, CarbonImmutable $until): array
    {
        if ($people === []) {
            return [];
        }

        $table = self::table();

        return self::takes($brandId)
            ->whereIn("{$table}.causer_id", $people)
            ->whereColumn("{$table}.properties->to", "{$table}.causer_id")
            ->where("{$table}.created_at", '>=', $since)
            ->where("{$table}.created_at", '<', $until)
            ->groupBy("{$table}.causer_id")
            ->selectRaw("{$table}.causer_id as person, COUNT(DISTINCT {$table}.subject_id) as n")
            ->pluck('n', 'person')
            ->map(fn (mixed $n): int => (int) $n)
            ->all();
    }

    /** Every take of a brand: a lead given to themselves by the person who clicked, not undone straight after. */
    private static function takes(int $brandId): QueryBuilder
    {
        $table = self::table();

        return DB::table($table)
            ->where("{$table}.brand_id", $brandId)
            ->where("{$table}.event", 'leads.assigned')
            ->where("{$table}.subject_type", Lead::class)
            ->where("{$table}.causer_type", User::class)
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

    private static function table(): string
    {
        return (new Activity)->getTable();
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
