<?php

namespace App\Support;

/**
 * Panel revision #3: applicant names are encrypted (Applicant::casts()), so an ordinary
 * `WHERE first_name = ?` can never match - encryption is non-deterministic ciphertext, by
 * design (that's what makes it safe). This is the "separate, keyed search index for
 * normalized names" the panel asked for instead: a keyed (HMAC) hash of the normalized
 * full name, stored alongside the encrypted columns, so a lookup can ask the database
 * "does any row's name hash equal this one" without ever decrypting a row it doesn't
 * already know is a match.
 *
 * What this supports: exact matching only, case- and whitespace-insensitive (the same
 * normalization ensureNotDuplicate() already applied in PHP before this existed). What it
 * does NOT support, and can't: partial names, "starts with", misspellings, or any kind of
 * fuzzy/substring search - an HMAC's whole point is that two different inputs never produce
 * related outputs, so there is no way to match a hash against part of a name. A real
 * partial-name search would need a different mechanism (e.g. a separate index of hashed
 * n-grams), which is a bigger design and a bigger privacy tradeoff (it leaks fragments of
 * the name into a more guessable index) - deliberately not built here. If partial search is
 * ever needed, design that decision explicitly rather than quietly extending this one.
 */
class NameSearchIndex
{
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    public static function hash(string $firstName, string $lastName): string
    {
        $value = self::normalize($lastName).'|'.self::normalize($firstName);

        return hash_hmac('sha256', $value, self::key());
    }

    private static function key(): string
    {
        return (string) config('name-search.key');
    }
}
