<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

/**
 * De-duplication inside a brand by email or phone. A repeat enquiry on an
 * open lead goes to that lead's timeline; a Won lead starts a new one (it
 * will be linked to the client in week 5).
 */
final class LeadMatcher
{
    public static function find(Brand|int $brand, ?string $email, ?string $phone, ?int $exceptId = null): ?Lead
    {
        $email = filled($email) ? mb_strtolower(trim((string) $email)) : null;
        $phone = filled($phone) ? trim((string) $phone) : null;

        if ($email === null && $phone === null) {
            return null;
        }

        return Lead::query()
            ->forBrand($brand)
            ->open()
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId))
            ->where(function (Builder $query) use ($email, $phone): void {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->latest('id')
            ->first();
    }
}
