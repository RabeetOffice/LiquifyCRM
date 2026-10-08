<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire\Concerns;

use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Search, sort and page state for list screens, kept in the URL so a
 * filtered view can be shared or reopened.
 */
trait WithTableState
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'asc')]
    public string $direction = 'asc';

    #[Url(except: 50)]
    public int $perPage = 50;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sort === $field) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->direction = 'asc';
        }

        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'pagination.lq';
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function sortColumn(array $allowed, string $default): string
    {
        return in_array($this->sort, $allowed, true) ? $this->sort : $default;
    }

    protected function sortDirection(string $default = 'asc'): string
    {
        return $this->sort === '' ? $default : ($this->direction === 'desc' ? 'desc' : 'asc');
    }
}
