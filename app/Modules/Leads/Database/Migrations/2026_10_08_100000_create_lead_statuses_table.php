<?php

declare(strict_types=1);

use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->string('color', 7);
            $table->string('tint', 7);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestamps();

            $table->unique(['brand_id', 'slug']);
            $table->index(['brand_id', 'sort']);
        });

        // Every existing brand gets the document's pipeline; new brands get it on creation.
        $now = now();

        foreach (DB::table('brands')->whereNull('deleted_at')->pluck('id') as $brandId) {
            foreach (LeadStatus::DEFAULTS as $sort => $status) {
                DB::table('lead_statuses')->insert([
                    'brand_id' => $brandId,
                    'name' => $status['name'],
                    'slug' => $status['slug'],
                    'color' => $status['color'],
                    'tint' => $status['tint'],
                    'sort' => $sort,
                    'is_default' => $status['is_default'] ?? false,
                    'is_won' => $status['is_won'] ?? false,
                    'is_lost' => $status['is_lost'] ?? false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_statuses');
    }
};
