<?php

declare(strict_types=1);

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Support\Spreadsheet;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * An import from a CSV or Excel file, one tab at a time: every tab of the
 * workbook is shown along the bottom like in Excel, the open tab's rows are
 * on screen, its columns are matched to fields (guessed from its heading
 * row, kept per tab) and it is imported on its own. Imported tabs keep their
 * result, so the next tab can follow without choosing the file again.
 *
 * The component uses WithFileUploads and gives the aliases its fields are
 * guessed from.
 */
trait WithSpreadsheetImport
{
    /** Rows of the open tab shown on screen. */
    public const PREVIEW_ROWS = 50;

    /** The import's own dialog: a refresh of the list behind it must not start the import over. */
    public bool $open = false;

    public ?TemporaryUploadedFile $file = null;

    /** @var list<array{name: string, rows: int, read: bool}> rows: under the heading row once the tab was read, the file's count before */
    #[Locked]
    public array $tabs = [];

    #[Locked]
    public int $tab = 0;

    /** @var list<string> */
    #[Locked]
    public array $headers = [];

    /** @var list<list<mixed>> */
    #[Locked]
    public array $preview = [];

    /** Rows of the open tab under its heading row. */
    #[Locked]
    public int $total = 0;

    /** @var array<int, array<string, string>> tab => field => column index */
    public array $maps = [];

    /** @var array<int, array{created: int, merged: int, failed: int, errors: list<string>}> tab => result */
    #[Locked]
    public array $results = [];

    /** @return array<string, list<string>> field => headings that mean it */
    abstract protected function aliases(): array;

    public function updatedFile(): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240']]);

        try {
            $tabs = Spreadsheet::tabs($this->file->getRealPath(), $this->extension());
        } catch (Throwable $exception) {
            $this->addError('file', 'That file could not be read: '.$exception->getMessage());

            return;
        }

        if ($tabs === []) {
            $this->addError('file', 'The file is empty.');

            return;
        }

        $this->tabs = array_map(fn (array $tab): array => [...$tab, 'read' => false], $tabs);
        $this->maps = [];
        $this->results = [];

        // Open the first tab that holds something.
        $first = 0;

        foreach ($tabs as $index => $tab) {
            if ($tab['rows'] > 0) {
                $first = $index;
                break;
            }
        }

        $this->openTab($first);
    }

    public function openTab(int $index): void
    {
        if (! isset($this->tabs[$index])) {
            return;
        }

        $this->tab = $index;
        $this->resetErrorBag();

        try {
            $rows = $this->readTab();
        } catch (Throwable $exception) {
            $this->headers = [];
            $this->preview = [];
            $this->total = 0;
            $this->addError('file', 'The tab "'.$this->tabs[$index]['name'].'" could not be read: '.$exception->getMessage());

            return;
        }

        $this->headers = $rows === [] ? [] : array_map(fn (mixed $cell): string => trim((string) $cell), array_shift($rows));
        $this->preview = array_slice($rows, 0, self::PREVIEW_ROWS);
        $this->total = count($rows);
        $this->tabs[$index]['rows'] = $this->total;
        $this->tabs[$index]['read'] = true;

        if (! isset($this->maps[$index])) {
            $this->maps[$index] = $this->guessMapping();
        }
    }

    public function resetImport(): void
    {
        $this->reset('file', 'tabs', 'tab', 'headers', 'preview', 'total', 'maps', 'results');
        $this->resetErrorBag();
    }

    /**
     * Every row of the open tab under its heading row, for the import.
     *
     * @return list<list<mixed>>
     */
    protected function tabRows(): array
    {
        $rows = $this->readTab();
        array_shift($rows);

        return $rows;
    }

    /**
     * The open tab's cell for a field, trimmed; null when the field is skipped or the cell is empty.
     *
     * @param  list<mixed>  $row
     */
    protected function cellFor(array $row, string $field): ?string
    {
        $index = $this->maps[$this->tab][$field] ?? '';

        if ($index === '') {
            return null;
        }

        $value = $row[(int) $index] ?? null;

        return $value === null ? null : (trim((string) $value) ?: null);
    }

    protected function mapped(string $field): bool
    {
        return ($this->maps[$this->tab][$field] ?? '') !== '';
    }

    /** @param  array{created: int, merged: int, failed: int, errors: list<string>}  $result */
    protected function finishTab(array $result): void
    {
        $this->results[$this->tab] = $result;
    }

    protected function fileName(): ?string
    {
        return $this->file?->getClientOriginalName();
    }

    /** What the open tab is called, for the audit line ("" for a CSV). */
    protected function tabName(): string
    {
        return count($this->tabs) > 1 ? (string) ($this->tabs[$this->tab]['name'] ?? '') : '';
    }

    /** @return list<list<mixed>> */
    private function readTab(): array
    {
        if ($this->file === null || ! isset($this->tabs[$this->tab])) {
            return [];
        }

        return Spreadsheet::rows($this->file->getRealPath(), $this->extension(), $this->tabs[$this->tab]['name']);
    }

    /** @return array<string, string> */
    private function guessMapping(): array
    {
        $map = [];

        foreach ($this->aliases() as $field => $names) {
            foreach ($this->headers as $index => $header) {
                if (in_array(mb_strtolower($header), $names, true)) {
                    $map[$field] = (string) $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function extension(): string
    {
        return mb_strtolower((string) $this->file?->getClientOriginalExtension());
    }
}
