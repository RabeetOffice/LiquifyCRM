<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The numbers of the Performance tab (user request, 27 Nov 2026), per person and for one month of the
 * viewer's calendar. Everything is read from what the CRM keeps anyway (leads, notes, follow-ups, chat
 * replies, the timeline): no table of its own. Each method takes the people to show (the viewer's own,
 * AssignableAgents) and returns a row for every one of them, zeros included.
 */
final class TeamPerformance
{
    /** A first contact this quick counts as "within 15 minutes". */
    public const QUICK_MINUTES = 15;

    /**
     * The month as stored (UTC) moments: [start, end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, ViewerTime::zone()) ?: ViewerTime::now()->startOfMonth();
        $utc = (string) config('app.timezone');

        return [$start->startOfMonth()->setTimezone($utc), $start->startOfMonth()->addMonthNoOverflow()->setTimezone($utc)];
    }

    /** "12 min", "3.5 h", "2 days", or a dash when there is nothing to measure. */
    public static function minutes(?int $minutes): string
    {
        return match (true) {
            $minutes === null => '—',
            $minutes < 60 => $minutes.' min',
            $minutes < 1440 => round($minutes / 60, 1).' h',
            default => round($minutes / 1440, 1).' days',
        };
    }

    /**
     * How fast each person's new leads of the month were picked up and first contacted (moved past New).
     *
     * @param  list<int>  $people
     * @return array<int, array{leads: int, to_owner: ?int, to_contact: ?int, quick: int, waiting: int}>
     */
    public static function speed(int $brandId, array $people, string $month): array
    {
        $rows = array_fill_keys($people, ['leads' => 0, 'to_owner' => null, 'to_contact' => null, 'quick' => 0, 'waiting' => 0]);

        if ($people === []) {
            return $rows;
        }

        $result = self::leads($brandId, $people, $month)
            ->leftJoin('lead_statuses', 'lead_statuses.id', '=', 'leads.status_id')
            ->groupBy('leads.assigned_to')
            ->selectRaw('leads.assigned_to as person, COUNT(*) as leads')
            ->selectRaw('AVG(CASE WHEN leads.assigned_at >= leads.created_at THEN TIMESTAMPDIFF(MINUTE, leads.created_at, leads.assigned_at) END) as to_owner')
            ->selectRaw('AVG(CASE WHEN leads.first_contacted_at >= leads.created_at THEN TIMESTAMPDIFF(MINUTE, leads.created_at, leads.first_contacted_at) END) as to_contact')
            ->selectRaw('SUM(CASE WHEN leads.first_contacted_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, leads.created_at, leads.first_contacted_at) <= ? THEN 1 ELSE 0 END) as quick', [self::QUICK_MINUTES])
            ->selectRaw('SUM(CASE WHEN leads.first_contacted_at IS NULL AND lead_statuses.is_default = 1 THEN 1 ELSE 0 END) as waiting')
            ->get();

        foreach ($result as $row) {
            $rows[(int) $row->person] = [
                'leads' => (int) $row->leads,
                'to_owner' => $row->to_owner !== null ? max(0, (int) round((float) $row->to_owner)) : null,
                'to_contact' => $row->to_contact !== null ? max(0, (int) round((float) $row->to_contact)) : null,
                'quick' => (int) $row->quick,
                'waiting' => (int) $row->waiting,
            ];
        }

        return $rows;
    }

    /**
     * What each person did in the month: notes, follow-ups done and left overdue, chat replies, stage moves and takes.
     *
     * @param  list<int>  $people
     * @return array<int, array{notes: int, done: int, overdue: int, replies: int, moves: int, taken: int}>
     */
    public static function activity(int $brandId, array $people, string $month): array
    {
        $rows = array_fill_keys($people, ['notes' => 0, 'done' => 0, 'overdue' => 0, 'replies' => 0, 'moves' => 0, 'taken' => 0]);

        if ($people === []) {
            return $rows;
        }

        [$start, $end] = self::window($month);
        $put = function (string $key, iterable $counts) use (&$rows): void {
            foreach ($counts as $row) {
                $rows[(int) $row->person][$key] = (int) $row->n;
            }
        };

        $put('notes', DB::table('lead_notes')->where('brand_id', $brandId)->whereIn('user_id', $people)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->groupBy('user_id')->selectRaw('user_id as person, COUNT(*) as n')->get());

        $put('done', DB::table('tasks')->where('brand_id', $brandId)->whereIn('user_id', $people)
            ->where('done_at', '>=', $start)->where('done_at', '<', $end)
            ->groupBy('user_id')->selectRaw('user_id as person, COUNT(*) as n')->get());

        // Due in the month, the moment gone, still not done.
        $put('overdue', DB::table('tasks')->where('brand_id', $brandId)->whereIn('user_id', $people)
            ->whereNull('done_at')->where('due_at', '>=', $start)->where('due_at', '<', min($end, now()))
            ->groupBy('user_id')->selectRaw('user_id as person, COUNT(*) as n')->get());

        // Replies typed by the person in a Messenger, Instagram or website chat (not team notes or events).
        $put('replies', DB::table('messages')->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.brand_id', $brandId)->where('messages.direction', Message::OUT)
            ->whereIn('messages.sent_by', $people)
            ->where(fn (QueryBuilder $kind): QueryBuilder => $kind->whereNull('messages.kind')->orWhereNotIn('messages.kind', ['note', 'event']))
            ->where('messages.created_at', '>=', $start)->where('messages.created_at', '<', $end)
            ->groupBy('messages.sent_by')->selectRaw('messages.sent_by as person, COUNT(*) as n')->get());

        $table = (new Activity)->getTable();
        $put('moves', DB::table($table)->where('brand_id', $brandId)->where('event', 'leads.status_changed')
            ->where('causer_type', User::class)->whereIn('causer_id', $people)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->groupBy('causer_id')->selectRaw('causer_id as person, COUNT(*) as n')->get());

        foreach (LeadTakes::countsBetween($brandId, $people, $start, $end) as $person => $count) {
            $rows[$person]['taken'] = $count;
        }

        return $rows;
    }

    /**
     * Each person's leads that arrived in the month, and how many are won, lost and still open.
     *
     * @param  list<int>  $people
     * @return array<int, array{leads: int, won: int, lost: int, open: int, rate: ?int}>
     */
    public static function conversion(int $brandId, array $people, string $month): array
    {
        $rows = array_fill_keys($people, ['leads' => 0, 'won' => 0, 'lost' => 0, 'open' => 0, 'rate' => null]);

        if ($people === []) {
            return $rows;
        }

        $result = self::leads($brandId, $people, $month)
            ->leftJoin('lead_statuses', 'lead_statuses.id', '=', 'leads.status_id')
            ->groupBy('leads.assigned_to')
            ->selectRaw('leads.assigned_to as person, COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN lead_statuses.is_won = 1 THEN 1 ELSE 0 END) as won')
            ->selectRaw('SUM(CASE WHEN lead_statuses.is_lost = 1 THEN 1 ELSE 0 END) as lost')
            ->get();

        foreach ($result as $row) {
            $leads = (int) $row->leads;
            $won = (int) $row->won;
            $lost = (int) $row->lost;

            $rows[(int) $row->person] = [
                'leads' => $leads,
                'won' => $won,
                'lost' => $lost,
                'open' => max(0, $leads - $won - $lost),
                'rate' => $leads > 0 ? (int) round($won / $leads * 100) : null,
            ];
        }

        return $rows;
    }

    /**
     * Why each person's leads were lost: lost leads last worked in the month (there is no lost_at, as on the
     * Dashboard), by reason. A lead with no reason yet is "not_given".
     *
     * @param  list<int>  $people
     * @return array<int, array<string, int>>
     */
    public static function lostReasons(int $brandId, array $people, string $month): array
    {
        $empty = array_fill_keys([...array_keys(LeadInsights::LOST), 'not_given'], 0);
        $rows = array_fill_keys($people, $empty);

        if ($people === []) {
            return $rows;
        }

        [$start, $end] = self::window($month);
        $reason = LeadInsights::ready() ? 'leads.lost_reason' : 'NULL';

        $result = DB::table('leads')
            ->join('lead_statuses', 'lead_statuses.id', '=', 'leads.status_id')
            ->where('leads.brand_id', $brandId)->whereNull('leads.deleted_at')
            ->whereIn('leads.assigned_to', $people)
            ->where('lead_statuses.is_lost', true)
            ->where('leads.last_activity_at', '>=', $start)->where('leads.last_activity_at', '<', $end)
            ->groupBy('leads.assigned_to', DB::raw($reason))
            ->selectRaw("leads.assigned_to as person, {$reason} as reason, COUNT(*) as n")
            ->get();

        foreach ($result as $row) {
            $key = is_string($row->reason) && isset($empty[$row->reason]) ? $row->reason : 'not_given';
            $rows[(int) $row->person][$key] += (int) $row->n;
        }

        return $rows;
    }

    /**
     * The leads of the brand now with these people that arrived in the month.
     *
     * @param  list<int>  $people
     */
    private static function leads(int $brandId, array $people, string $month): QueryBuilder
    {
        [$start, $end] = self::window($month);

        return DB::table((new Lead)->getTable())
            ->where('leads.brand_id', $brandId)
            ->whereNull('leads.deleted_at')
            ->whereIn('leads.assigned_to', $people)
            ->where('leads.created_at', '>=', $start)
            ->where('leads.created_at', '<', $end);
    }
}
