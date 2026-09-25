<?php

/**
 * Prints the `groups` array of a tightenco/ziggy config file as JSON.
 *
 * Run by SourceIndexer::loadZiggyGroups() in a separate process, with cwd = the
 * project root, so a config that calls env()/config() (or fatals) cannot take the
 * indexer down. A missing helper function is stubbed to return null so the common
 * `env('X')` shape still yields the static parts of the array.
 */
declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "usage: ziggy-groups.php <config/ziggy.php>\n");
    exit(1);
}

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

$config = include $path;

if (! is_array($config) || ! isset($config['groups']) || ! is_array($config['groups'])) {
    echo '{}';
    exit(0);
}

echo json_encode($config['groups'], JSON_UNESCAPED_SLASHES);
