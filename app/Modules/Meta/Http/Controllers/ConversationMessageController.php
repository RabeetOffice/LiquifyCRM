<?php

declare(strict_types=1);

namespace App\Modules\Meta\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Meta\Actions\ReplyOnMessenger;
use App\Modules\Meta\Exceptions\MetaException;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /b/{brand}/conversations/{conversation}/messages: reply on Messenger or Instagram (the chat's channel). */
final class ConversationMessageController
{
    public function __invoke(Request $request, Brand $brand, Conversation $conversation, ReplyOnMessenger $reply): JsonResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        try {
            $message = $reply->handle($conversation, $data['body'], $request->user());
        } catch (MetaException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['id' => $message->getKey(), 'status' => $message->status->value], 202);
    }
}
