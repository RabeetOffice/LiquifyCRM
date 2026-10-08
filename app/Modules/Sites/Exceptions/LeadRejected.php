<?php

declare(strict_types=1);

namespace App\Modules\Sites\Exceptions;

use RuntimeException;

/** An incoming lead that cannot become a lead (spam, nobody to contact). */
final class LeadRejected extends RuntimeException {}
