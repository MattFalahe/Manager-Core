<?php

// The tests run without SeAT or a database, so only code that needs neither is
// loaded: the price figures, the rules and the source files themselves.

require __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    foreach (['ManagerCore\\Tests\\' => __DIR__ . '/', 'ManagerCore\\' => dirname(__DIR__) . '/src/'] as $prefix => $root) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});

// Nothing here reads a table, so a model needs no more than a class to extend.
if (!class_exists('Illuminate\Database\Eloquent\Model')) {
    require __DIR__ . '/Stubs/Model.php';
}

// A structure read waits on a cache lock, which throws this when another read holds it.
if (!class_exists('Illuminate\Contracts\Cache\LockTimeoutException')) {
    require __DIR__ . '/Stubs/LockTimeoutException.php';
}

// A structure read is kept in the cache. Here the cache holds what a test puts in it.
if (!class_exists('Illuminate\Support\Facades\Cache')) {
    class_alias(ManagerCore\Tests\ArrayCache::class, 'Illuminate\Support\Facades\Cache');
}

// The notification registry logs as it goes. Here the log keeps the lines for a test to read.
if (!class_exists('Illuminate\Support\Facades\Log')) {
    class_alias(ManagerCore\Tests\ArrayLog::class, 'Illuminate\Support\Facades\Log');
}

if (!function_exists('now')) {
    function now(): Carbon\Carbon
    {
        return Carbon\Carbon::now();
    }
}

// The config as it ships, with nothing set in the environment, under the key
// the service provider merges it into.
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return $default;
    }
}

if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        static $config;
        $config ??= ['manager-core' => require dirname(__DIR__) . '/src/Config/manager-core.config.php'];

        $value = $config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
