<?php

declare(strict_types=1);

namespace Ecosystem;

/**
 * Pairs Blade's block directives the way Blade reads them.
 *
 * Blade only sees a directive where \B@\w+ matches, so "{{ $h }}h@endif" is
 * plain text and leaves its @if open even though a count of @if and @endif
 * balances. Everything below follows the compiler: comments, @verbatim and
 * @php blocks are taken out first, and only what is left is paired.
 */
final class Blade
{
    /** opener => the directives that close it */
    private const BLOCKS = [
        'if' => ['endif'], 'unless' => ['endunless'], 'isset' => ['endisset'], 'empty' => ['endempty'],
        'foreach' => ['endforeach'], 'forelse' => ['endforelse'], 'for' => ['endfor'], 'while' => ['endwhile'],
        'switch' => ['endswitch'], 'push' => ['endpush'], 'prepend' => ['endprepend'],
        'pushonce' => ['endpushonce'], 'prependonce' => ['endprependonce'], 'pushif' => ['endpushif'],
        'once' => ['endonce'], 'error' => ['enderror'], 'can' => ['endcan'], 'cannot' => ['endcannot'],
        'canany' => ['endcanany'], 'auth' => ['endauth'], 'guest' => ['endguest'], 'env' => ['endenv'],
        'production' => ['endproduction'], 'component' => ['endcomponent'], 'slot' => ['endslot'],
        'fragment' => ['endfragment'], 'hassection' => ['endif'], 'sectionmissing' => ['endif'],
        'section' => ['endsection', 'stop', 'show', 'overwrite', 'append'],
    ];

    /**
     * @return array<int, array{0: int, 1: string}> line and problem, empty when balanced
     */
    public static function problems(string $source): array
    {
        $problems = [];

        // Keep newlines so line numbers survive the removals.
        $blank = fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]);
        $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source);
        $source = preg_replace_callback('/\B@verbatim\b.*?\B@endverbatim\b/s', $blank, $source);
        $source = preg_replace_callback('/\B@php\b(?!\s*\().*?\B@endphp\b/s', $blank, $source);
        $source = preg_replace_callback('/<\?php.*?(\?>|$)/s', $blank, $source);
        // @@if and @{{ are Blade's escapes for a literal @.
        $source = preg_replace_callback('/@@\w+|@\{\{/', $blank, $source);

        $closers = [];
        foreach (self::BLOCKS as $open => $closes) {
            foreach ($closes as $close) {
                $closers[$close][] = $open;
            }
        }

        $stack = [];
        preg_match_all('/\B@(\w+)([ \t]*\()?/', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $name = strtolower($match[1][0]);
            $hasArgs = isset($match[2]) && $match[2][0] !== '';
            $offset = $match[0][1];
            $line = Source::lineOf($source, $offset);

            if ($name === 'php' && $hasArgs) {
                continue;
            }
            if ($name === 'empty' && !$hasArgs) {
                // The @empty inside @forelse, not the @empty(...) block.
                continue;
            }
            if (in_array($name, ['section', 'push', 'prepend', 'slot'], true) && $hasArgs && self::hasSecondArgument($source, $offset)) {
                // @section('title', 'Text') is complete on its own.
                continue;
            }

            if (isset(self::BLOCKS[$name])) {
                $stack[] = [$name, $line];
                continue;
            }
            if (isset($closers[$name])) {
                $top = end($stack);
                if ($top && in_array($top[0], $closers[$name], true)) {
                    array_pop($stack);
                } else {
                    $problems[] = [$line, "@$name closes nothing" . ($top ? " (the open block is @{$top[0]} from line {$top[1]})" : '')];
                }
            }
        }

        foreach ($stack as [$name, $line]) {
            $problems[] = [$line, "@$name is never closed"];
        }

        return $problems;
    }

    /** True when the directive's argument list holds a top-level comma. */
    private static function hasSecondArgument(string $source, int $offset): bool
    {
        $start = strpos($source, '(', $offset);
        if ($start === false) {
            return false;
        }
        $depth = 0;
        $quote = null;
        $len = strlen($source);
        for ($i = $start; $i < $len; $i++) {
            $c = $source[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
            } elseif ($c === ',' && $depth === 1) {
                return true;
            }
        }

        return false;
    }
}
