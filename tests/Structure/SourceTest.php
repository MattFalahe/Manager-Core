<?php

namespace ManagerCore\Tests\Structure;

use PHPUnit\Framework\TestCase;

/**
 * Things a mistake in the source would break on a live install that nothing
 * else catches before then, read from the files themselves.
 */
class SourceTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';
    private const SRC = self::ROOT . 'src/';
    private const HELP_TEXT = 'src/Resources/lang/en/help.php';

    /** @return string[] paths of the files under src/ with this ending */
    private static function files(string $ending, string $under = ''): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC . $under, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getPathname(), $ending)) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /** @return array<string, string[]> each command's name, with the files that give it */
    private static function commands(): array
    {
        $commands = [];
        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            preg_match("/signature = 'manager-core:([a-z-]+)/", file_get_contents($path), $signature);
            $commands[$signature[1] ?? basename($path) . ' has no signature'][] = basename($path);
        }
        ksort($commands);

        return $commands;
    }

    private static function text($token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /** @return array a method's body as PHP tokens, without whitespace or comments */
    private static function body(string $file, string $method): array
    {
        $tokens = array_values(array_filter(
            token_get_all(file_get_contents(self::SRC . $file)),
            fn ($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        foreach ($tokens as $at => $token) {
            $name = $tokens[$at + 1] ?? null;
            if (!is_array($token) || $token[0] !== T_FUNCTION || !is_array($name) || $name[1] !== $method) {
                continue;
            }

            $open = $at;
            while ($tokens[$open] !== '{') {
                $open++;
            }

            for ($depth = 0, $close = $open; $close < count($tokens); $close++) {
                $text = self::text($tokens[$close]);
                $depth += in_array($text, ['{', '${'], true) ? 1 : ($text === '}' ? -1 : 0);
                if ($depth === 0) {
                    return array_slice($tokens, $open + 1, $close - $open - 1);
                }
            }
        }

        return [];
    }

    /** @return string[] each statement in these tokens as one string, a closure passed in a call staying part of its statement */
    private static function statements(array $tokens): array
    {
        $statements = [''];
        $depth = 0;
        foreach ($tokens as $token) {
            $text = self::text($token);
            $depth += in_array($text, ['(', '['], true) ? 1 : (in_array($text, [')', ']'], true) ? -1 : 0);
            $statements[array_key_last($statements)] .= $text;
            if ($text === ';' && $depth === 0) {
                $statements[] = '';
            }
        }

        return $statements;
    }

    /**
     * Blade reads a directive with \B@\w+, so counting @if and @endif proves
     * nothing: {{ $h }}h@endif is not a directive, and leaves its @if open.
     */
    public function test_every_blade_directive_is_closed_the_way_blade_reads_it(): void
    {
        $opens = [
            'if' => 'endif', 'unless' => 'endunless', 'isset' => 'endisset', 'foreach' => 'endforeach',
            'forelse' => 'endforelse', 'for' => 'endfor', 'while' => 'endwhile', 'push' => 'endpush',
            'can' => 'endcan', 'error' => 'enderror', 'switch' => 'endswitch',
        ];
        $closes = array_flip($opens) + ['endsection' => 'section', 'stop' => 'section', 'endphp' => 'php'];

        foreach (self::files('.blade.php') as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
            $source = preg_replace('/\B@php\b(?!\s*\().*?\B@endphp\b/s', '', $source);
            // A section with its content in the call has nothing to close.
            $source = preg_replace("/\B@section\(\s*'[^']*'\s*,.*\)\s*$/m", '', $source);

            preg_match_all('/\B@(\w+)/', $source, $matches);
            $stack = [];
            foreach ($matches[1] as $directive) {
                if ($directive === 'section') {
                    $stack[] = 'section';
                } elseif (isset($opens[$directive])) {
                    $stack[] = $directive;
                } elseif (isset($closes[$directive])) {
                    $this->assertSame($closes[$directive], array_pop($stack), basename($path) . ": @$directive closes the wrong block");
                }
            }

            $this->assertSame([], $stack, basename($path) . ': left open');
        }
    }

    public function test_every_language_key_a_literal_call_names_exists(): void
    {
        $lang = [];
        foreach (self::files('.php', 'Resources/lang/en') as $path) {
            $lang[basename($path, '.php')] = require $path;
        }

        foreach (self::files('.php') as $path) {
            preg_match_all("/(?:\btrans|\b__|\btrans_choice|@lang)\(\s*'manager-core::([a-z_-]+)\.([a-zA-Z0-9_.-]+)'\s*[,)]/", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $file, $key]) {
                $value = $lang[$file] ?? null;
                foreach (explode('.', $key) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                $this->assertNotNull($value, basename($path) . " names $file.$key, which is not there");
            }
        }
    }

    public function test_migrations_are_named_classes_matching_their_file(): void
    {
        foreach (self::files('.php', 'Database/migrations') as $path) {
            $source = file_get_contents($path);
            $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path, '.php'));
            $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $suffix)));

            $this->assertStringNotContainsString('return new class', $source, basename($path));
            $this->assertMatchesRegularExpression('/\bclass ' . $class . ' extends Migration\b/', $source, basename($path));
            $this->assertStringContainsString('manager_core', $suffix, basename($path) . ' has to name the plugin: migrations share one namespace');
        }
    }

    public function test_migration_numbers_run_in_order_with_none_repeated(): void
    {
        $numbers = array_map(fn ($path) => (int) substr(basename($path), 11, 6), self::files('.php', 'Database/migrations'));

        $this->assertSame(range(1, count($numbers)), $numbers);
    }

    public function test_index_names_fit_in_what_mysql_allows(): void
    {
        foreach (self::files('.php', 'Database/migrations') as $path) {
            preg_match_all("/'(mc_[a-z0-9_]+)'/", file_get_contents($path), $matches);
            foreach ($matches[1] as $name) {
                $this->assertLessThanOrEqual(64, strlen($name), $name);
            }
        }
    }

    /**
     * SeAT's tables are read, never written. Our data lives in our own tables,
     * and the one SeAT table we fill is the schedule, with our own commands.
     */
    public function test_nothing_writes_a_table_that_is_not_the_plugins(): void
    {
        $written = [];
        foreach (self::files('.php') as $path) {
            preg_match_all("/DB::table\('([a-zA-Z_]+)(?: as \w+)?'\)((?:(?!DB::table|;).)*?)->(insert|insertOrIgnore|insertUsing|insertGetId|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\(/s", file_get_contents($path), $matches, PREG_SET_ORDER);
            foreach ($matches as [, $table]) {
                if (!str_starts_with($table, 'manager_core_') && !($table === 'schedules' && basename($path) === 'ScheduleSeeder.php')) {
                    $written[] = basename($path) . " writes $table";
                }
            }
        }

        $this->assertSame([], $written);
    }

    /** Not a column, not an index: SeAT's schema is SeAT's. */
    public function test_nothing_changes_a_table_that_is_not_the_plugins(): void
    {
        $changed = [];
        foreach (self::files('.php', 'Database/migrations') as $path) {
            $source = file_get_contents($path);

            preg_match_all("/Schema::(?:create|table|drop|dropIfExists|rename)\(\s*'([a-zA-Z_]+)'/", $source, $named);
            preg_match_all('/\b(?:ALTER TABLE|CREATE (?:UNIQUE )?INDEX \S+ ON|DROP TABLE(?: IF EXISTS)?)\s+`?([a-zA-Z_]+)/i', $source, $statements);

            foreach (array_merge($named[1], $statements[1]) as $table) {
                if (!str_starts_with($table, 'manager_core_')) {
                    $changed[] = basename($path) . " changes $table";
                }
            }
        }

        foreach (self::files('.php') as $path) {
            if (!str_contains(str_replace('\\', '/', $path), 'Database/migrations/') && preg_match('/Schema::(create|table|drop|dropIfExists|rename)\(/', file_get_contents($path))) {
                $changed[] = basename($path) . ' changes a schema outside a migration';
            }
        }

        $this->assertSame([], $changed);
    }

    public function test_every_command_is_registered(): void
    {
        $provider = file_get_contents(self::SRC . 'ManagerCoreServiceProvider.php');

        foreach (glob(self::SRC . 'Console/Commands/*Command.php') as $path) {
            $class = basename($path, '.php');
            $this->assertStringContainsString("$class::class", $provider, $class);
        }
    }

    /** Two commands under one name leave only the one registered last. */
    public function test_every_command_has_a_name_of_its_own(): void
    {
        foreach (self::commands() as $command => $files) {
            $this->assertCount(1, $files, "manager-core:$command is given by " . implode(' and ', $files));
        }
    }

    public function test_the_readme_covers_every_command_and_names_none_that_is_gone(): void
    {
        $readme = file_get_contents(self::ROOT . 'README.md');
        $known = array_keys(self::commands());

        foreach ($known as $command) {
            $this->assertStringContainsString("manager-core:$command", $readme, "README.md leaves out $command");
        }

        preg_match_all('/manager-core:([a-z][a-z-]*)/', $readme, $named);
        $this->assertSame([], array_values(array_diff(array_unique($named[1]), $known)), 'README.md names commands that are not there');
    }

    public function test_help_covers_every_command_and_names_none_that_is_gone(): void
    {
        preg_match_all('/manager-core:([a-z][a-z-]*)/', file_get_contents(self::ROOT . self::HELP_TEXT), $named);
        $named = array_unique($named[1]);
        $known = array_keys(self::commands());

        $this->assertSame([], array_values(array_diff($known, $named)), 'Help leaves these out');
        $this->assertSame([], array_values(array_diff($named, $known)), 'Help names commands that are not there');
    }

    /** A package name with a branch in it stops working the day the branch goes, and people copy what the docs say. */
    public function test_the_docs_name_the_package_and_no_branch(): void
    {
        foreach (['README.md', 'CHANGELOG.MD', self::HELP_TEXT] as $file) {
            $this->assertDoesNotMatchRegularExpression('/manager-core:\^?dev\b|dev-dev\b/', file_get_contents(self::ROOT . $file), $file);
        }
    }

    /** Comments and docs are read by strangers on GitHub, and CSS comments reach every user's browser. */
    public function test_nothing_published_names_private_notes_or_local_paths(): void
    {
        $paths = array_merge([self::ROOT . 'README.md', self::ROOT . 'CHANGELOG.MD'], self::files('.php'), self::files('.css'), self::files('.js'));

        foreach ($paths as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:feedback|project|reference)_[a-z0-9_]+\.md\b|\bfeedback_[a-z_]+|\bproject_(?:mining|manager|seat|hr|structure)_|\bMEMORY\.md\b|\bClaude\b|\bCo-Authored-By\b|[FC]:[\\\\\/](?:Claude|Users)/',
                file_get_contents($path),
                basename($path)
            );
        }
    }

    /**
     * The sweep stores what it finds in SeAT's table and leaves the dispatching
     * to the poll. A poll that returned without dispatching, as it did with
     * nobody in the key pool, left those notifications undelivered.
     */
    public function test_the_poll_dispatches_on_every_way_out_once_it_has_looked_for_key_holders(): void
    {
        $depth = 0;
        $dispatchedAt = [];
        $lookedUp = false;
        foreach (self::body('Jobs/ESI/PollEsiNotifications.php', 'handle') as $token) {
            $text = self::text($token);
            if ($text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === '}') {
                // A dispatch in a block that has closed is not on the way to what comes after it.
                $dispatchedAt = array_filter($dispatchedAt, fn ($at) => $at < $depth);
                $depth--;
            } elseif ($text === 'getNextInRotation') {
                $lookedUp = true;
            } elseif ($text === 'dispatchUndispatched') {
                $dispatchedAt[] = $depth;
            } elseif ($lookedUp && is_array($token) && $token[0] === T_RETURN) {
                $this->assertNotEmpty($dispatchedAt, 'handle() returns without dispatching');
            }
        }

        $this->assertTrue($lookedUp);
        $this->assertNotEmpty($dispatchedAt, 'handle() ends without dispatching');
    }

    /**
     * Nothing prunes the notification table, so an install that ran with nobody
     * in the key pool holds every notification the sweep found, none of them
     * dispatched. Those are hours or days old by now and stay where they are,
     * so the read starts where NotificationAge says the oldest due one can be.
     * One that every handler turned down stays undispatched, and only its
     * delivery rows keep it from being read again: without them it would be
     * read on every poll, and fifty of them would hold back everything newer.
     */
    public function test_the_poll_reads_only_what_a_handler_can_still_be_due(): void
    {
        $statements = self::statements(self::body('Jobs/ESI/PollEsiNotifications.php', 'dispatchUndispatched'));
        $source = implode('', $statements);
        $reads = array_values(array_filter($statements, fn ($statement) => str_contains($statement, "EsiNotification::where('dispatched',false)")));

        $this->assertCount(1, $reads);
        $this->assertStringContainsString("\$query=EsiNotification::where('dispatched',false)->where('timestamp','>=',\$cutoff)->whereIn('type',\$registeredTypes);", $reads[0]);
        $this->assertStringContainsString('$cutoff=$clock->copy()->subSeconds($now-NotificationAge::dispatchCutoff($now,$since,$registry->getLongestMaxAgeHours()));', $source);
        $this->assertStringContainsString('$recording=Schema::hasTable(EsiNotificationDelivery::TABLE);', $source);
        $this->assertStringContainsString(
            "if(\$recording){\$query->whereNotExists(function(\$recorded){\$recorded->select(DB::raw(1))->from(EsiNotificationDelivery::TABLE.' as d')->whereColumn('d.notification_id','manager_core_esi_notifications.notification_id');});}",
            $source,
            'a notification with delivery rows is not read again'
        );
        $this->assertStringContainsString('$undispatched=$query->orderBy(', $source);
    }

    /**
     * Each handler says how old it takes a notification. Handed over without
     * asking NotificationAge, one ESI served hours late would set off an alert
     * that is hours out of date, in a plugin that asked not to get it.
     */
    public function test_the_poll_hands_a_notification_only_to_the_handlers_it_is_not_too_old_for(): void
    {
        $source = implode('', self::statements(self::body('Jobs/ESI/PollEsiNotifications.php', 'dispatchUndispatched')));

        $this->assertSame(1, substr_count($source, '->deliver('), 'one way to hand a notification over');
        $this->assertStringNotContainsString('->dispatch(', $source, 'dispatch() hands it to every handler, however old');
        $this->assertStringContainsString(
            '$tooOld=NotificationAge::whyNotDue($sentAt,$storedAt,$now,$since,$hours);if($tooOld===null){[$status,$detail]=$registry->deliver($notification,$entry);$handedOver=true;}else{[$status,$detail]=[EsiNotificationDelivery::TOO_OLD,$tooOld];}',
            $source
        );
        $this->assertStringContainsString("\$hours=\$entry['max_age_hours']??NotificationAge::hoursFor(\$notification->type);", $source);
        $this->assertStringContainsString('$sentAt=Carbon::parse($notification->timestamp)->getTimestamp();', $source);
        $this->assertStringContainsString('$storedAt=Carbon::parse($notification->created_at??$notification->timestamp)->getTimestamp();', $source);
        $this->assertSame(1, substr_count($source, '->markDispatched()'));
        $this->assertStringContainsString('if($handedOver||!$recording){$notification->markDispatched();', $source, 'dispatched only once a handler got it');
    }

    /**
     * Intake is two weeks, opened out from the update: a poll or a sweep that
     * reached back two weeks on its first run would store what the old code
     * skipped, and a late one would be missed if the sweep went by when CCP
     * sent it rather than when SeAT stored it.
     */
    public function test_the_poll_and_the_sweep_keep_what_notification_age_lets_in(): void
    {
        $handle = implode('', self::statements(self::body('Jobs/ESI/PollEsiNotifications.php', 'handle')));
        $poll = implode('', self::statements(self::body('Jobs/ESI/PollEsiNotifications.php', 'pollKeyHolder')));
        $sweep = implode('', self::statements(self::body('Jobs/ESI/SweepSeatNotifications.php', 'handle')));

        $this->assertStringContainsString('$intakeCutoff=NotificationAge::intakeCutoff(Carbon::now()->getTimestamp(),EsiNotification::archiveSince());', $handle);
        $this->assertStringContainsString('if($timestamp->getTimestamp()<$intakeCutoff){continue;}', $poll);
        $this->assertDoesNotMatchRegularExpression('/sub(?:Hours|Minutes|Days)\(/', $poll);

        $this->assertStringContainsString('$recent=$clock->copy()->subHours(2);', $sweep);
        $this->assertStringContainsString('$oldest=$clock->copy()->subSeconds($clock->getTimestamp()-NotificationAge::intakeCutoff($clock->getTimestamp(),EsiNotification::archiveSince()));', $sweep);
        $this->assertStringContainsString(
            "DB::table('character_notifications as cn')->whereIn('cn.type',\$registeredTypes)->where(function(\$stored)use(\$recent){\$stored->where('cn.created_at','>=',\$recent)->orWhere('cn.timestamp','>=',\$recent);})->where('cn.timestamp','>=',\$oldest)->whereNotExists(",
            $sweep
        );
    }

    /**
     * SeAT stores a notification once for every character that got it, and the
     * sweep reads a window of 200. Counted in rows, copies of notifications
     * already held filled the window, and a new one could stay outside it.
     */
    public function test_the_sweeps_window_counts_notifications_it_does_not_hold_yet(): void
    {
        $limited = array_values(array_filter(
            self::statements(self::body('Jobs/ESI/SweepSeatNotifications.php', 'handle')),
            fn ($statement) => str_contains($statement, "DB::table('character_notifications") && str_contains($statement, '->limit(')
        ));

        $this->assertCount(1, $limited);
        // Both subqueries have to reach out to the row being swept: an alias
        // that shadows it, or a column compared with itself, matches always.
        $this->assertMatchesRegularExpression(
            "/^[^(]*DB::table\('character_notifications as (\w+)'\).*->whereNotExists\(function\([^)]*\)\{[^}]*'manager_core_esi_notifications as (?!\\1')(\w+)'[^}]*->whereColumn\('\\2\.notification_id','\\1\.notification_id'\)/",
            $limited[0],
            'it leaves out what is already held'
        );
        $this->assertMatchesRegularExpression(
            "/^[^(]*DB::table\('character_notifications as (\w+)'\).*->whereNotExists\(function\([^)]*\)\{[^}]*'character_notifications as (?!\\1')(\w+)'[^}]*->whereColumn\('\\2\.notification_id','\\1\.notification_id'\)[^}]*->whereColumn\('\\2\.id','[<>]','\\1\.id'\)/",
            $limited[0],
            'it takes the first copy of each notification'
        );
    }
}
