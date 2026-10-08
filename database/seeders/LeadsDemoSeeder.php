<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadNote;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Models\Task;
use Illuminate\Database\Seeder;

/**
 * Sample leads for every active brand so the dashboard, list, pipeline and
 * follow-ups have something to show. Local and staging only:
 * php artisan db:seed --class=LeadsDemoSeeder
 */
final class LeadsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $faker = fake('en_GB');
        $sources = [LeadSource::Website, LeadSource::Website, LeadSource::Website, LeadSource::Facebook, LeadSource::Messenger, LeadSource::Manual, LeadSource::Import];
        $services = ['Publishing', 'Editing', 'Ghostwriting', 'Marketing', 'Illustration'];

        foreach (Brand::query()->where('is_active', true)->get() as $brand) {
            LeadStatus::createDefaultsFor($brand);

            $statuses = LeadStatus::query()->forBrand($brand)->ordered()->get();
            $departments = $brand->departments()->get();
            $agents = $brand->memberships()->where('role', BrandRole::Agent->value)->with('user')->get()->pluck('user')->filter();

            for ($i = 0; $i < 55; $i++) {
                $createdAt = now()->subDays(random_int(0, 60))->subMinutes(random_int(0, 1440));
                $department = $departments->isNotEmpty() && random_int(1, 10) > 2 ? $departments->random() : null;
                $agent = $department && $agents->isNotEmpty() && random_int(1, 10) > 3 ? $agents->random() : null;
                $status = $agent ? $statuses->random() : $statuses->firstWhere('is_default', true);
                $source = $sources[array_rand($sources)];

                /** @var Lead $lead */
                $lead = Lead::query()->create([
                    'brand_id' => $brand->getKey(),
                    'source_type' => $source,
                    'external_id' => $source === LeadSource::Website ? (string) random_int(1000, 9999).'-'.$i : null,
                    'name' => $faker->name(),
                    'email' => $faker->unique()->safeEmail(),
                    'phone' => '+4477009'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT),
                    'message' => $faker->randomElement([
                        'I have finished my first novel and would like to know about publishing packages.',
                        'Looking for help editing a memoir of about 80,000 words.',
                        'Can you tell me the cost of a children\'s book with illustrations?',
                        'Interested in marketing for a book that is already on Amazon.',
                        'Please call me about ghostwriting a business book.',
                    ]),
                    'status_id' => $status->getKey(),
                    'department_id' => $department?->getKey(),
                    'assigned_to' => $agent?->getKey(),
                    'assigned_at' => $agent ? $createdAt->copy()->addHours(random_int(1, 48)) : null,
                    'fields' => ['service' => $faker->randomElement($services), 'country' => $faker->randomElement(['United Kingdom', 'Australia', 'United States'])],
                    'utm' => $source === LeadSource::Website ? ['utm_source' => $faker->randomElement(['google', 'bing', 'newsletter']), 'utm_campaign' => 'autumn'] : null,
                    'first_contacted_at' => $status->is_default ? null : $createdAt->copy()->addHours(random_int(1, 72)),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'last_activity_at' => $createdAt->copy()->addHours(random_int(0, 96)),
                ]);

                Audit::log('leads.created', $lead, ['source' => $source->value], 'Lead created from '.$source->getLabel(), brand: $brand, logName: 'leads');

                if ($agent && random_int(1, 10) > 5) {
                    LeadNote::query()->create(['brand_id' => $brand->getKey(), 'lead_id' => $lead->getKey(), 'user_id' => $agent->getKey(), 'body' => $faker->randomElement(['Left a voicemail, will try again tomorrow.', 'Sent the price list by email.', 'Wants a call after 6pm.', 'Asked for sample edits first.'])]);
                }

                if ($agent && ! $status->is_won && ! $status->is_lost && random_int(1, 10) > 5) {
                    $dueAt = now()->addDays(random_int(-3, 7))->setTime(random_int(9, 17), 0);
                    Task::query()->create(['brand_id' => $brand->getKey(), 'lead_id' => $lead->getKey(), 'user_id' => $agent->getKey(), 'created_by' => $agent->getKey(), 'title' => 'Follow up', 'due_at' => $dueAt]);
                    $lead->forceFill(['next_follow_up_at' => $dueAt])->saveQuietly();
                }
            }
        }
    }
}
