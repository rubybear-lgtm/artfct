<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Mirrors `validate_slug` in backend/src/store.rs — the slug becomes a DNS
 * label in spec 5, so the same constraints apply here: 1-24 characters,
 * ASCII only, lowercase letters/digits/'-', no leading/trailing '-', and
 * no '--'.
 */
class TeamSlug implements ValidationRule
{
    public const MAX_LENGTH = 24;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be between 1 and '.self::MAX_LENGTH.' characters.');

            return;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $fail('The :attribute must be between 1 and '.self::MAX_LENGTH.' characters.');

            return;
        }

        if (str_contains($value, '--')) {
            $fail('The :attribute cannot contain \'--\'.');

            return;
        }

        if (! mb_check_encoding($value, 'ASCII')) {
            $fail('The :attribute must contain only ASCII characters.');

            return;
        }

        if (str_starts_with($value, '-') || str_ends_with($value, '-')) {
            $fail('The :attribute cannot start or end with \'-\'.');

            return;
        }

        if (! preg_match('/^[a-z0-9-]+$/', $value)) {
            $fail('The :attribute must contain only lowercase letters, digits, and \'-\'.');
        }
    }

    /**
     * Whether the given string satisfies every slug constraint. Used
     * outside the validator pipeline (e.g. when auto-generating slugs).
     */
    public static function isValid(string $value): bool
    {
        $failed = false;

        (new self)->validate('slug', $value, function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }
}
