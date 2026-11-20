<?php

declare(strict_types=1);

namespace Ecosystem;

/**
 * Collects findings and prints them for a person and for GitHub.
 *
 * GitHub shows only ten error annotations per step, so every check also gets
 * one annotation listing all of its findings; nothing is lost past the tenth.
 */
final class Report
{
    /** @var array<int, array{level: string, check: string, file: ?string, line: ?int, message: string}> */
    private array $findings = [];

    /** @var array<string, string> */
    private array $passed = [];

    public function __construct(private string $suite)
    {
    }

    public function error(string $check, ?string $file, ?int $line, string $message): void
    {
        $this->add('error', $check, $file, $line, $message);
    }

    public function notice(string $check, ?string $file, ?int $line, string $message): void
    {
        $this->add('notice', $check, $file, $line, $message);
    }

    /** Records what a check looked at, so a pass says what it covered. */
    public function covered(string $check, string $what): void
    {
        $this->passed[$check] = $what;
    }

    public function errorCount(): int
    {
        return count(array_filter($this->findings, fn ($f) => $f['level'] === 'error'));
    }

    private function add(string $level, string $check, ?string $file, ?int $line, string $message): void
    {
        $this->findings[] = compact('level', 'check', 'file', 'line', 'message');
    }

    public function finish(): int
    {
        $github = getenv('GITHUB_ACTIONS') === 'true';
        $byCheck = [];
        foreach ($this->findings as $f) {
            $byCheck[$f['check']][] = $f;
        }

        foreach ($this->passed as $check => $what) {
            $errors = count(array_filter($byCheck[$check] ?? [], fn ($f) => $f['level'] === 'error'));
            echo ($errors ? 'FAIL' : 'ok  ') . "  $check: $what" . ($errors ? ", $errors finding(s)" : '') . "\n";
        }
        foreach (array_keys($byCheck) as $check) {
            if (!isset($this->passed[$check])) {
                echo "FAIL  $check\n";
            }
        }
        echo "\n";

        $shown = 0;
        foreach ($byCheck as $check => $findings) {
            $lines = [];
            foreach ($findings as $f) {
                $where = $f['file'] ? $f['file'] . ($f['line'] ? ':' . $f['line'] : '') . '  ' : '';
                $lines[] = ($f['level'] === 'error' ? '' : '(info) ') . $where . $f['message'];
                echo '[' . $f['level'] . "] $check  $where{$f['message']}\n";

                // Per-line annotations for the first few errors, so they show in the diff view.
                if ($github && $f['level'] === 'error' && $f['file'] && $shown < 9) {
                    $shown++;
                    echo '::error ' . self::props(['file' => $f['file'], 'line' => $f['line'], 'title' => $check]) . '::' . self::escape($f['message']) . "\n";
                }
            }

            $errors = count(array_filter($findings, fn ($f) => $f['level'] === 'error'));
            if ($github) {
                $level = $errors ? 'error' : 'notice';
                $title = "$check: " . ($errors ? "$errors finding(s)" : count($findings) . ' note(s)');
                echo "::$level " . self::props(['title' => $title]) . '::' . self::escape(implode("\n", $lines)) . "\n";
            }
        }

        if ($github && ($summary = getenv('GITHUB_STEP_SUMMARY'))) {
            $md = "### Ecosystem: {$this->suite}\n\n";
            foreach ($this->passed as $check => $what) {
                $errors = count(array_filter($byCheck[$check] ?? [], fn ($f) => $f['level'] === 'error'));
                $md .= '- ' . ($errors ? ':x:' : ':white_check_mark:') . " **$check**: $what" . ($errors ? ", $errors finding(s)" : '') . "\n";
            }
            foreach ($byCheck as $check => $findings) {
                $md .= "\n<details><summary>$check</summary>\n\n";
                foreach ($findings as $f) {
                    $where = $f['file'] ? '`' . $f['file'] . ($f['line'] ? ':' . $f['line'] : '') . '` ' : '';
                    $md .= '- ' . ($f['level'] === 'error' ? '' : '(info) ') . $where . str_replace("\n", ' ', $f['message']) . "\n";
                }
                $md .= "\n</details>\n";
            }
            file_put_contents($summary, $md . "\n", FILE_APPEND);
        }

        $total = $this->errorCount();
        echo $total ? "\n$total finding(s).\n" : "\nNo findings.\n";

        return $total ? 1 : 0;
    }

    private static function escape(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    /** @param array<string, string|int|null> $props */
    private static function props(array $props): string
    {
        $out = [];
        foreach ($props as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $out[] = $key . '=' . str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], (string) $value);
        }

        return implode(',', $out);
    }
}
