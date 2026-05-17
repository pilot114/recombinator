#!/usr/bin/env php
<?php

declare(strict_types=1);

$metaPath    = __DIR__ . '/../examples/meta.json';
$examplesDir = realpath(__DIR__ . '/../examples');

$meta = json_decode((string) file_get_contents($metaPath), true);
if (!is_array($meta)) {
    fwrite(STDERR, "Failed to parse examples/meta.json\n");
    exit(1);
}

foreach ($meta as $id => $config) {
    if (($config['type'] ?? '') !== 'project') {
        continue;
    }

    $source = $config['url'] ?? null;
    if ($source === null) {
        continue;
    }

    $dir = $examplesDir . '/' . $id;

    echo "==> {$id}\n";

    if (is_dir($dir)) {
        echo "    Removing existing directory...\n";
        run("rm -rf " . escapeshellarg($dir), $examplesDir);
    }

    echo "    Cloning {$source}...\n";
    run("git clone --depth=1 " . escapeshellarg($source) . " " . escapeshellarg($dir), $examplesDir);

    foreach ($config['setup'] ?? [] as $cmd) {
        echo "    $ {$cmd}\n";
        run($cmd, $dir);
    }

    echo "    Done.\n\n";
}

echo "All projects updated.\n";

// ---------------------------------------------------------------------------

function run(string $cmd, string $cwd): void
{
    $proc = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes, $cwd);
    if ($proc === false) {
        fwrite(STDERR, "Failed to start: {$cmd}\n");
        exit(1);
    }
    $code = proc_close($proc);
    if ($code !== 0) {
        fwrite(STDERR, "Command exited with code {$code}: {$cmd}\n");
        exit($code);
    }
}
