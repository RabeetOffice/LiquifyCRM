<?php

declare(strict_types=1);

namespace App\Modules\Departments\Support;

use App\Modules\Core\Enums\DepartmentKind;
use App\Modules\Sales\Support\ClientCare;

/**
 * The departments most brands start with, offered as one click when a brand
 * is added: front sales works the leads, and after the first sale the client
 * goes to the people who look after it (upsellers and project managers).
 */
final class StarterTeams
{
    /**
     * @return array<string, array{name: string, kind: DepartmentKind, color: string, description: string}>
     */
    public static function all(): array
    {
        $teams = [
            'front-sales' => [
                'name' => 'Front Sales',
                'kind' => DepartmentKind::Sales,
                'color' => '#2563EB',
                'description' => 'Works new leads from the website and Facebook until the first sale.',
            ],
            'upsell' => [
                'name' => 'Upsell',
                'kind' => DepartmentKind::Upsell,
                'color' => '#059669',
                'description' => 'Takes the client over after the first sale and sells more to them.',
            ],
            'project-management' => [
                'name' => 'Project Management',
                'kind' => DepartmentKind::Upsell,
                'color' => '#7C3AED',
                'description' => 'Project managers who run the client\'s work after the sale. Clients can be handed to it, like Upsell.',
            ],
        ];

        // Teams that look after clients need the tables of that update; until then only front sales exists.
        return ClientCare::ready() ? $teams : array_filter($teams, fn (array $team): bool => $team['kind'] === DepartmentKind::Sales);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
