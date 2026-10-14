<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/** What a browser's push service said about one desktop alert. */
enum PushResult
{
    case Delivered;

    /** The browser no longer takes alerts at this address (signed out, cleared, uninstalled): forget it. */
    case Gone;

    case Failed;
}
