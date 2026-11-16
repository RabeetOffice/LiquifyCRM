<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Ai;

use RuntimeException;

/** The assistant cannot answer now. Thrown by `Ai::ask()` with words a person can read. */
final class AiUnavailable extends RuntimeException {}
