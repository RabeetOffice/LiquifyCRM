<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use Maatwebsite\Excel\Concerns\Import;

/** A no-concern import so Laravel-Excel returns the raw rows of a file. */
final class RawRows implements Import {}
