<?php

namespace App\Template;

/**
 * Template variables: "{{ name }}" placeholders, e.g. "Bonjour {{ client.prenom }}".
 * Names: letters, digits and "_", with "." for nesting (values can be sent flat or nested).
 */
final class Placeholders
{
    public const NAME_PATTERN = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*';
    private const PLACEHOLDER = '/\{\{\s*('.self::NAME_PATTERN.')\s*\}\}/';

    /** @return list<string> Names used in the texts, in order of appearance, without duplicates. */
    public static function names(?string ...$texts): array
    {
        $names = [];
        foreach ($texts as $text) {
            if (null !== $text && preg_match_all(self::PLACEHOLDER, $text, $matches)) {
                array_push($names, ...$matches[1]);
            }
        }

        return array_values(array_unique($names));
    }

    public static function isValidName(string $name): bool
    {
        return 1 === preg_match('/^'.self::NAME_PATTERN.'$/', $name);
    }

    /**
     * {"client": {"prenom": "Jean"}} and {"client.prenom": "Jean"} both give ["client.prenom" => "Jean"].
     *
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    public static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $name = $prefix.$key;
            if (\is_array($value)) {
                $flat += self::flatten($value, $name.'.');
            } elseif (\is_scalar($value) || $value instanceof \Stringable) {
                $flat[$name] = \is_bool($value) ? ($value ? 'oui' : 'non') : (string) $value;
            }
        }

        return $flat;
    }

    /**
     * Replaces the placeholders that have a value; the others are left untouched.
     * In HTML, values are text: they are escaped, so a value can never inject markup.
     *
     * @param array<string, string> $values flat values (see flatten())
     */
    public static function render(string $text, array $values, bool $html): string
    {
        return preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($values, $html): string {
            if (!\array_key_exists($match[1], $values)) {
                return $match[0];
            }

            // Plain text (the subject) stays on one line.
            return $html ? htmlspecialchars($values[$match[1]], \ENT_QUOTES | \ENT_HTML5) : preg_replace('/\s+/', ' ', $values[$match[1]]);
        }, $text) ?? $text;
    }
}
