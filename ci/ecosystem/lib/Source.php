<?php

declare(strict_types=1);

namespace Ecosystem;

/**
 * Splits source into its comments and its strings, per file type.
 */
final class Source
{
    public static function lineOf(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, max(0, min($offset, strlen($text)))) + 1;
    }

    /** @return array<int, array{0: string, 1: int}> comment text and starting line */
    public static function comments(string $relative, string $text): array
    {
        if (str_ends_with($relative, '.blade.php')) {
            return self::markupComments($text);
        }
        if (str_ends_with($relative, '.php')) {
            return self::phpComments($text);
        }
        if (preg_match('/\.(js|css|scss|vue|ts)$/', $relative)) {
            return self::scriptComments($text);
        }
        if (preg_match('/\.html?$/', $relative)) {
            return self::markupComments($text);
        }

        return [];
    }

    /** @return array<int, array{0: string, 1: int}> */
    public static function phpComments(string $code): array
    {
        $out = [];
        foreach (self::tokens($code) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $out[] = [$token[1], $token[2]];
            }
        }

        return $out;
    }

    /** @return array<int, array{0: string, 1: int}> string literal contents and line */
    public static function phpStrings(string $code): array
    {
        $out = [];
        foreach (self::tokens($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[] = [$token[1], $token[2]];
            }
        }

        return $out;
    }

    /**
     * String literals that are not part of a Log:: or logger() call, since a
     * log line is read by whoever reads the logs, not shown on a page.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function phpStringsOutsideLogs(string $code): array
    {
        $tokens = self::tokens($code);
        $out = [];
        $depth = 0;
        $logDepth = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === '(') {
                $depth++;
                continue;
            }
            if ($t === ')') {
                $depth--;
                if ($logDepth !== null && $depth === $logDepth - 1) {
                    // Log::channel('x')->warning('...') carries on into the next call.
                    for ($j = $i + 1; $j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE; $j++);
                    $chained = $j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
                    $logDepth = $chained ? $logDepth : null;
                }
                continue;
            }
            if (!is_array($t)) {
                continue;
            }
            if ($logDepth === null && self::startsLogCall($tokens, $i)) {
                $logDepth = $depth + 1;
                continue;
            }
            if ($logDepth === null && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[] = [$t[1], $t[2]];
            }
        }

        return $out;
    }

    private static function startsLogCall(array $tokens, int $i): bool
    {
        $t = $tokens[$i];
        $name = ltrim($t[1], '\\');
        if ($t[0] === T_STRING && strtolower($name) === 'logger') {
            return true;
        }
        if (!in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
            return false;
        }
        if ($name !== 'Log' && $name !== 'Illuminate\Support\Facades\Log') {
            return false;
        }
        $next = $tokens[$i + 1] ?? null;

        return is_array($next) && $next[0] === T_DOUBLE_COLON;
    }

    /** PHP code with comments blanked out, line numbers kept. */
    public static function phpWithoutComments(string $code): string
    {
        $out = '';
        foreach (self::tokens($code) as $token) {
            if (is_array($token)) {
                $out .= ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)
                    ? preg_replace('/[^\n]/', ' ', $token[1])
                    : $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** @return array<int, array{0: string, 1: int}> Blade, HTML and script comments in a template */
    public static function markupComments(string $text): array
    {
        $out = [];
        foreach (['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '~/\*.*?\*/~s', '~(?<=^|[\s;{}(])//[^\n]*~m'] as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$comment, $offset]) {
                $out[] = [$comment, self::lineOf($text, $offset)];
            }
        }

        return $out;
    }

    /** @return array<int, array{0: string, 1: int}> */
    public static function scriptComments(string $text): array
    {
        $out = [];
        foreach (['~/\*.*?\*/~s', '~(?<=^|[\s;{}(])//[^\n]*~m'] as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$comment, $offset]) {
                $out[] = [$comment, self::lineOf($text, $offset)];
            }
        }

        return $out;
    }

    /** Any text with its comments blanked out, line numbers kept. */
    public static function withoutComments(string $relative, string $text): string
    {
        if (str_ends_with($relative, '.php') && !str_ends_with($relative, '.blade.php')) {
            return self::phpWithoutComments($text);
        }
        $patterns = ['~/\*.*?\*/~s', '~(?<=^|[\s;{}(])//[^\n]*~m'];
        if (str_ends_with($relative, '.blade.php') || preg_match('/\.html?$/', $relative)) {
            array_unshift($patterns, '/\{\{--.*?--\}\}/s', '/<!--.*?-->/s');
        }
        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, fn ($m) => preg_replace('/[^\n]/', ' ', $m[0]), $text);
        }

        return $text;
    }

    /** @return array<int, mixed> */
    private static function tokens(string $code): array
    {
        try {
            return token_get_all($code);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Laravel's Str::studly, as the migrator uses it to find a migration's class. */
    public static function studly(string $value): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', $value));

        return implode('', array_map(fn ($w) => ucfirst($w), $words));
    }

    /** True for text files the checks should read. */
    public static function isText(string $relative): bool
    {
        return preg_match('/\.(php|js|css|scss|vue|ts|json|md|MD|txt|yml|yaml|xml|dist|html?|sql|sh|ps1|neon)$/', $relative) === 1
            && !preg_match('/\.min\.(js|css)$/', $relative);
    }
}
