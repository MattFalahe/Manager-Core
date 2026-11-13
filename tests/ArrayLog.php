<?php

namespace ManagerCore\Tests;

/** Keeps what is logged, so a test can see it. */
final class ArrayLog
{
    /** @var array<int, array{0: string, 1: string}> level and message */
    public static array $lines = [];

    public static function __callStatic(string $level, array $arguments): void
    {
        self::$lines[] = [$level, (string) ($arguments[0] ?? '')];
    }
}
