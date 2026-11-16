<?php

declare(strict_types=1);

namespace App\Modules\Core\Support\Ai;

/**
 * What stays here when text goes to the assistant: every email address, every phone number and the person's name
 * are replaced by placeholders ("[email1]", "[phone1]", "[name1]") before the text leaves, and put back in what the
 * assistant answers. So the AI service never receives who the person is or how to reach them, and a suggested
 * "Hi [name1]" still reads "Hi Aoife" in the message box.
 *
 * It hides too much sooner than too little: a long number that is not a phone is hidden as well, and comes back
 * unchanged all the same.
 */
final class ContactMask
{
    private const EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9\-]+(?:\.[A-Z0-9\-]+)+/i';

    /** Seven digits or more with the spaces, dots, dashes and brackets people put between them. */
    private const PHONE = '/(?<![\w+\[])\+?\(?\d[\d\s().\-]{5,22}\d(?![\w\]])/u';

    /** Words that are a name only with their capital: "will" and "may" stay readable, "Will" and "May" are hidden. */
    private const ALSO_WORDS = ['will', 'may', 'rose', 'mark', 'grace', 'hope', 'joy', 'faith', 'bill', 'sue', 'pat', 'rob', 'art', 'don', 'dawn', 'june', 'april', 'summer', 'penny', 'rich', 'frank', 'page', 'king', 'young', 'long', 'best', 'good', 'white', 'black', 'brown', 'green'];

    /** Never a name: what a chat calls somebody who gave none, and the small words of a name. */
    private const NOT_NAMES = ['visitor', 'guest', 'customer', 'client', 'user', 'unknown', 'there', 'the', 'and', 'van', 'von', 'der', 'del', 'bin', 'mrs', 'miss', 'sir'];

    /** @var array<string, string> placeholder => what it stands for */
    private array $real = [];

    /** @var array<string, int> */
    private array $count = ['email' => 0, 'phone' => 0, 'name' => 0];

    /** @var list<string> */
    private array $names;

    /** @param  list<string|null>  $names  the person's names as the CRM knows them (the chat's, the lead's, the profile's) */
    public function __construct(array $names = [])
    {
        $words = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if (mb_strlen($name) < 3 || in_array(mb_strtolower($name), self::NOT_NAMES, true)) {
                continue;
            }

            // The whole name first, then each of its words, so "Aoife Byrne" and a lone "Byrne" are both hidden.
            $words[] = $name;

            foreach (preg_split('/[\s,]+/u', $name) ?: [] as $word) {
                $word = trim($word, " \t.'\"()@");

                if (mb_strlen($word) >= 3 && ! in_array(mb_strtolower($word), self::NOT_NAMES, true)) {
                    $words[] = $word;
                }
            }
        }

        // Longest first: the full name before its parts.
        $words = array_values(array_unique($words));
        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $this->names = $words;
    }

    /** The text as it may leave: contacts and names replaced by placeholders. */
    public function hide(string $text): string
    {
        $text = (string) preg_replace_callback(self::EMAIL, fn (array $found): string => $this->placeholder('email', $found[0]), $text);

        $text = (string) preg_replace_callback(self::PHONE, function (array $found): string {
            $digits = strlen((string) preg_replace('/\D/', '', $found[0]));
            // A date ("2026-11-16", "16.11.2026") is not a phone number.
            $date = preg_match('/^\(?\d{4}[.\-]\d{1,2}[.\-]\d{1,2}$|^\(?\d{1,2}[.\-]\d{1,2}[.\-]\d{2,4}$/', trim($found[0])) === 1;

            return $digits >= 7 && ! $date ? $this->placeholder('phone', $found[0]) : $found[0];
        }, $text);

        foreach ($this->names as $name) {
            $exact = in_array(mb_strtolower($name), self::ALSO_WORDS, true);
            $pattern = '/(?<![\p{L}\p{N}\[])'.preg_quote($name, '/').'(?![\p{L}\p{N}\]])/u'.($exact ? '' : 'i');

            $text = (string) preg_replace_callback($pattern, fn (array $found): string => $this->placeholder('name', $found[0]), $text);
        }

        return $text;
    }

    /** The assistant's answer with the real words back where it used a placeholder. */
    public function show(string $text): string
    {
        $text = $this->real === [] ? $text : strtr($text, $this->real);

        // A placeholder the assistant made up stands for nothing: plain words instead of "[name3]" on the screen.
        return (string) preg_replace(['/\[name\d*\]/', '/\[email\d*\]/', '/\[phone\d*\]/'], ['the customer', 'their email address', 'their phone number'], $text);
    }

    /** The same words always get the same placeholder, so the assistant can tell two numbers apart. */
    private function placeholder(string $kind, string $value): string
    {
        $known = array_search($value, $this->real, true);

        if (is_string($known)) {
            return $known;
        }

        $placeholder = '['.$kind.(++$this->count[$kind]).']';
        $this->real[$placeholder] = $value;

        return $placeholder;
    }
}
