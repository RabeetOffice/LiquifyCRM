<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\LeadDistribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Bulk distribution in two levels: the Admin splits leads across
 * departments, a Manager splits their department's leads across Agents.
 * Leads move in bulk (one notification per department or person, not one per
 * lead) and every run is written to the distribution history.
 */
final class DistributeLeads
{
    public const TO_DEPARTMENT = 'department';

    public const TO_USER = 'user';

    /**
     * Work out how many leads each target gets.
     *
     * @param  array<int, float|int|null>  $inputs  target id => percent or count (ignored for "equal")
     * @return array<int, int> target id => number of leads
     */
    public static function allocate(int $total, array $inputs, string $method): array
    {
        $ids = array_keys($inputs);

        if ($ids === [] || $total <= 0) {
            return [];
        }

        if ($method === 'equal') {
            $base = intdiv($total, count($ids));
            $extra = $total % count($ids);

            return array_filter(array_combine($ids, array_map(fn (int $index): int => $base + ($index < $extra ? 1 : 0), array_keys($ids))));
        }

        if ($method === 'percent') {
            $sum = array_sum(array_map('floatval', $inputs));

            if ($sum > 100.0001) {
                throw new InvalidArgumentException('The percentages add up to more than 100.');
            }

            $allocation = array_map(fn (float|int|null $percent): int => (int) floor($total * (float) $percent / 100), $inputs);

            // At exactly 100% nothing is left behind by rounding: the biggest share takes the rest.
            if (abs($sum - 100) < 0.0001 && $allocation !== []) {
                arsort($inputs);
                $allocation[array_key_first($inputs)] += $total - array_sum($allocation);
            }

            return array_filter($allocation);
        }

        $allocation = array_map(fn (float|int|null $count): int => max(0, (int) $count), $inputs);

        if (array_sum($allocation) > $total) {
            throw new InvalidArgumentException('You asked for '.array_sum($allocation).' leads but only '.$total.' match.');
        }

        return array_filter($allocation);
    }

    /**
     * @param  list<int>  $leadIds  in the order they are handed out (oldest first)
     * @param  array<int, int>  $allocation  target id => number of leads
     * @param  array<string, mixed>  $filters
     * @return int leads moved
     */
    public function handle(Brand $brand, User $by, array $leadIds, string $toType, array $allocation, string $method, array $filters = []): int
    {
        $moved = 0;
        $offset = 0;
        $summary = [];

        DB::transaction(function () use ($brand, $by, $leadIds, $toType, $allocation, $method, $filters, &$moved, &$offset, &$summary): void {
            foreach ($allocation as $targetId => $count) {
                $slice = array_slice($leadIds, $offset, $count);
                $offset += $count;

                if ($slice === []) {
                    continue;
                }

                $query = Lead::query()->forBrand($brand)->whereIn('id', $slice);

                if ($toType === self::TO_DEPARTMENT) {
                    $department = Department::query()->forBrand($brand)->findOrFail($targetId);
                    $name = $department->name;
                    $query->update(['department_id' => $department->getKey(), 'assigned_to' => null, 'assigned_at' => null, 'last_activity_at' => now()]);
                    $recipients = $department->managers()->get();
                    $text = count($slice).' '.str('lead')->plural(count($slice)).' sent to '.$name;
                    $body = $by->name.' sent them to your department. Share them among your Agents.';
                    $url = $brand->url('leads/distribute');
                } else {
                    $person = User::query()->findOrFail($targetId);
                    abort_unless($person->isMemberOf($brand), 403);
                    $name = $person->name;
                    $query->update(['assigned_to' => $person->getKey(), 'assigned_at' => now(), 'last_activity_at' => now()]);
                    $recipients = collect([$person]);
                    $text = count($slice).' '.str('lead')->plural(count($slice)).' assigned to you';
                    $body = $by->name.' assigned them to you.';
                    $url = $brand->url('leads');
                }

                LeadDistribution::query()->create([
                    'brand_id' => $brand->getKey(),
                    'from_user_id' => $by->getKey(),
                    'to_type' => $toType,
                    'to_id' => $targetId,
                    'lead_count' => count($slice),
                    'method' => $method,
                    'filters' => $filters,
                ]);

                Notification::send($recipients->reject(fn (User $user): bool => $user->is($by)), new AppNotification($text, $body, 'info', $url, mail: true, icon: 'heroicon-o-arrows-right-left', actionLabel: 'Open leads'));

                $summary[$name] = count($slice);
                $moved += count($slice);
            }
        });

        if ($moved > 0) {
            Audit::log('leads.distributed', $brand, ['to' => $toType, 'method' => $method, 'split' => $summary, 'filters' => $filters], $moved.' '.str('lead')->plural($moved).' distributed to '.implode(', ', array_keys($summary)), brand: $brand, causer: $by, logName: 'leads');
        }

        return $moved;
    }
}
