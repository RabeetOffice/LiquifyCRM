<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Groq's free tier (user request, 16 Nov 2026): the first choice of the live chat plan, with Zero Data Retention
 * switched on in the Groq console. Its limits are per model, so the models of `liquify.ai.groq_models` are tried in
 * order: one that answers 429 rests for as long as Groq says and the next one is asked. No package: plain Http.
 */
final class GroqProvider implements AiProvider
{
    public function name(): string
    {
        return 'Groq';
    }

    public function ready(): bool
    {
        return $this->key() !== '' && $this->models() !== [];
    }

    public function answer(string $instructions, string $text, int $maxTokens): string
    {
        $why = 'no key';

        foreach ($this->ready() ? $this->models() : [] as $model) {
            $resting = 'ai:groq:resting:'.md5($model);

            if (Cache::has($resting)) {
                $why = $model.' is at its limit';

                continue;
            }

            // The GPT-OSS models think before they write, and the thinking counts as words: little of it, kept out of
            // the answer, with room for it on top of the answer's own length.
            $thinks = str_starts_with($model, 'openai/gpt-oss');

            try {
                $response = Http::timeout(20)->connectTimeout(6)->withToken($this->key())->asJson()->acceptJson()->post($this->base().'/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $instructions],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'temperature' => 0.4,
                    'max_completion_tokens' => $maxTokens + ($thinks ? 600 : 0),
                    ...($thinks ? ['reasoning_effort' => 'low', 'include_reasoning' => false] : []),
                    // Qwen writes its thinking into the answer unless it is told not to think.
                    ...(str_starts_with($model, 'qwen/') ? ['reasoning_effort' => 'none'] : []),
                ]);
            } catch (Throwable $exception) {
                $why = $exception->getMessage();

                continue;
            }

            if ($response->status() === 429) {
                // Groq says how long: at least a few seconds, never longer than a quarter of an hour at a time.
                Cache::put($resting, true, max(10, min(900, (int) ceil((float) $response->header('retry-after')) ?: 60)));
                $why = $model.' is at its limit';

                continue;
            }

            if ($response->status() === 404) {
                // Groq no longer has this model (its list changes): not asked again for an hour. GROQ_MODELS names others.
                Cache::put($resting, true, 3600);
                $why = $model.' is not a model of this Groq account';

                continue;
            }

            $answer = $response->successful() ? $response->json('choices.0.message.content') : null;

            if (is_string($answer) && trim($answer) !== '') {
                return trim($answer);
            }

            $why = $response->successful() ? $model.' answered nothing' : $model.' answered '.$response->status();
        }

        throw new AiUnavailable($why);
    }

    private function key(): string
    {
        return trim((string) config('liquify.ai.groq_key'));
    }

    /** @return list<string> */
    private function models(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('liquify.ai.groq_models')))));
    }

    /** Groq's address; only a test against a stand-in server changes it. */
    private function base(): string
    {
        return rtrim((string) config('liquify.ai.groq_url', 'https://api.groq.com/openai/v1'), '/');
    }
}
