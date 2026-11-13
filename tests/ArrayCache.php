<?php

namespace ManagerCore\Tests;

final class ArrayCache
{
    public static array $items = [];

    public static function get(string $key, $default = null)
    {
        return self::$items[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$items);
    }

    public static function put(string $key, $value, $ttl = null): bool
    {
        self::$items[$key] = $value;

        return true;
    }

    public static function add(string $key, $value, $ttl = null): bool
    {
        if (array_key_exists($key, self::$items)) {
            return false;
        }
        self::$items[$key] = $value;

        return true;
    }

    public static function forget(string $key): bool
    {
        unset(self::$items[$key]);

        return true;
    }
}
