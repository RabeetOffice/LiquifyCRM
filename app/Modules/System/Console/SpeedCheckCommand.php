<?php

declare(strict_types=1);

namespace App\Modules\System\Console;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The pre-launch speed check from the architecture document: the dashboard
 * and the lead list must answer in under 300 ms with 100,000 leads in one
 * brand. Creates a throwaway brand, fills it, times the queries those screens
 * run, then removes the brand again.
 */
final class SpeedCheckCommand extends Command
{
    protected $signature = 'liquify:speed-check {--leads=100000 : How many leads to create} {--budget=300 : Milliseconds allowed per screen}';

    protected $description = 'Time the dashboard and lead list queries against a brand with many leads';

    public function handle(): int
    {
        $count = max(1000, (int) $this->option('leads'));
        $budget = (int) $this->option('budget');

        $brand = Brand::query()->create(['name' => 'Speed check '.Str::random(6), 'is_active' => false]);
        $this->info('Creating '.number_format($count).' leads in a throwaway brand…');

        try {
            $this->fill($brand, $count);

            // A bulk load leaves the index statistics stale for a while; a real
            // table that grew over months has them up to date.
            DB::statement('ANALYZE TABLE leads');

            $results = app(CurrentBrand::class)->within($brand, fn (): array => [
                'Lead list, first page (50 rows + count)' => $this->time(function (): void {
                    Lead::query()->with(['status', 'department', 'assignee'])->latest('id')->limit(50)->get();
                    Lead::query()->count();
                }),
                'Lead list, Unassigned view' => $this->time(function (): void {
                    Lead::query()->unassigned()->with('status')->oldest('id')->limit(50)->get();
                    Lead::query()->unassigned()->count();
                }),
                'Lead list, search by email' => $this->time(function (): void {
                    Lead::query()->where(fn (Builder $query): Builder => $query->where('email', 'like', 'lead50%')->orWhere('name', 'like', 'Lead 50%'))->limit(50)->get();
                }),
                'Dashboard numbers (not cached)' => $this->time(function (): void {
                    $today = now()->startOfDay();
                    Lead::query()->where('created_at', '>=', $today)->count();
                    Lead::query()->where('created_at', '>=', now()->subDays(7))->count();
                    Lead::query()->unassigned()->count();
                    Lead::query()->count();
                    Lead::query()->whereIn('status_id', LeadStatus::query()->where('is_won', true)->pluck('id'))->count();
                    Lead::query()->where('created_at', '>=', now()->subDays(29)->startOfDay())->selectRaw('date(created_at) as day, count(*) as total')->groupBy('day')->toBase()->get();
                    Lead::query()->where('created_at', '>=', now()->subDays(30))->groupBy('source_type')->toBase()->selectRaw('source_type, count(*) as total')->get();
                    Lead::query()->groupBy('status_id')->toBase()->selectRaw('status_id, count(*) as total')->get();
                }),
                'New / Unassigned queue (8 rows)' => $this->time(function (): void {
                    Lead::query()->queue()->with(['status', 'department'])->limit(8)->get();
                }),
            ]);
        } finally {
            $this->info('Removing the throwaway brand…');
            DB::table('leads')->where('brand_id', $brand->getKey())->delete();
            $brand->forceDelete();
            DB::statement('ANALYZE TABLE leads');
        }

        $this->table(['Screen', 'Milliseconds', 'Result'], collect($results)->map(fn (float $ms, string $label): array => [$label, number_format($ms, 1), $ms <= $budget ? 'OK' : 'TOO SLOW'])->values()->all());

        $slow = collect($results)->filter(fn (float $ms): bool => $ms > $budget);

        if ($slow->isNotEmpty()) {
            $this->error($slow->count().' check(s) took longer than '.$budget.' ms with '.number_format($count).' leads.');

            return self::FAILURE;
        }

        $this->info('Every check is under '.$budget.' ms with '.number_format($count).' leads. The dashboard numbers are also cached for 60 seconds.');

        return self::SUCCESS;
    }

    private function fill(Brand $brand, int $count): void
    {
        $statuses = LeadStatus::query()->forBrand($brand)->pluck('id')->all();
        $sources = ['website', 'facebook', 'messenger', 'instagram', 'manual', 'import'];
        $now = now();
        $bar = $this->output->createProgressBar($count);

        for ($offset = 0; $offset < $count; $offset += 2000) {
            $rows = [];

            for ($i = $offset; $i < min($count, $offset + 2000); $i++) {
                $created = $now->subMinutes(random_int(0, 60 * 24 * 365));

                $rows[] = [
                    'brand_id' => $brand->getKey(),
                    'source_type' => $sources[$i % count($sources)],
                    'external_id' => 'speed-'.$i,
                    'name' => 'Lead '.$i,
                    'email' => 'lead'.$i.'@example.com',
                    'phone' => '+4477'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                    'status_id' => $statuses[$i % count($statuses)],
                    'last_activity_at' => $created,
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }

            DB::table('leads')->insert($rows);
            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine();
    }

    /** Best of three runs, in milliseconds. */
    private function time(callable $queries): float
    {
        $best = INF;

        for ($run = 0; $run < 3; $run++) {
            $start = hrtime(true);
            $queries();
            $best = min($best, (hrtime(true) - $start) / 1e6);
        }

        return $best;
    }
}
