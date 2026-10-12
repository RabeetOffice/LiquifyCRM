<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Work that should not wait for the scheduler's next minute: turning a lead
 * that just arrived into a lead on screen, sending a Messenger reply. During
 * a web request it runs right after the answer has gone out, in the same
 * process, so nobody waits for it and it needs no worker. From the command
 * line and inside queue workers it goes on the queue as usual.
 *
 * Only for jobs that look after their own failures (one try, the error kept
 * on the record): nothing retries what runs here.
 */
final class RunSoon
{
    public static function dispatch(object $job): void
    {
        if (app()->runningInConsole()) {
            dispatch($job);

            return;
        }

        dispatch($job)->afterResponse();
    }
}
