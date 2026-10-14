<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Enums\PushResult;
use App\Modules\Core\Models\PushSubscription;

/** Sends one desktop alert to one browser (WebPushTransport; a fake in tests). */
interface PushTransport
{
    public function deliver(PushSubscription $subscription, string $payload): PushResult;
}
