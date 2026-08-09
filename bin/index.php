#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/../src/SourceIndexer.php';

use QuickDex\SourceIndexer;

$positional = [];
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } else {
        $positional[] = $arg;
    }
}

$root   = $positional[0] ?? getcwd();
$dbPath = $positional[1] ?? $root.DIRECTORY_SEPARATOR.'quickdex.db';

// Guard against indexing a multi-project parent (the origin of the stray
// Development/quickdex.db that served cross-project results). If the root holds
// more than one nested git repo, it's almost certainly the wrong directory.
$nestedGit = glob(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'.git') ?: [];
if (count($nestedGit) > 1) {
    printf(
        "WARNING: %s contains %d nested git repos — this looks like a multi-project parent, not a single project.\n"
        ."         The index will span all of them. cd into one project first, or pass an explicit root.\n",
        $root,
        count($nestedGit)
    );
}

$start   = microtime(true);
$indexer = new SourceIndexer($root, $dbPath);
$indexer->build($force);
$elapsed = round(microtime(true) - $start, 2);

printf("QuickDex built: %s (%.2fs)\n", $dbPath, $elapsed);
