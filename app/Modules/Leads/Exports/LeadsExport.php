<?php

declare(strict_types=1);

namespace App\Modules\Leads\Exports;

use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** CSV export of the leads a person is allowed to see (Admin, Manager). */
final class LeadsExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  Builder<Lead>  $query
     */
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query->with(['status', 'department', 'assignee']);
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['ID', 'Name', 'Email', 'Phone', 'Status', 'Source', 'Department', 'Assigned to', 'Next follow-up', 'Received', 'Message'];
    }

    /**
     * @param  Lead  $row
     * @return list<string|int|null>
     */
    public function map($row): array
    {
        return [
            $row->getKey(),
            $row->name,
            $row->email,
            $row->phone,
            $row->status->name,
            $row->source_type->getLabel(),
            $row->department?->name,
            $row->assignee?->name,
            $row->next_follow_up_at?->format('Y-m-d H:i'),
            $row->created_at?->format('Y-m-d H:i'),
            $row->message,
        ];
    }
}
