<?php

declare(strict_types=1);

$target = __DIR__.'/../vendor/discord-php-helpers/voice/src/Discord/Voice/Client/WS.php';

if (! file_exists($target)) {
    fwrite(STDOUT, "[patch-voice] Skipped: {$target} not found.\n");
    exit(0);
}

$contents = file_get_contents($target);

if ($contents === false) {
    fwrite(STDERR, "[patch-voice] Failed to read WS.php\n");
    exit(1);
}

if (str_contains($contents, 'public const MAX_DAVE_PROTOCOL_VERSION = 1;')) {
    fwrite(STDOUT, "[patch-voice] Already patched.\n");
    exit(0);
}

$patched = str_replace(
    'public const MAX_DAVE_PROTOCOL_VERSION = 0;',
    'public const MAX_DAVE_PROTOCOL_VERSION = 1;',
    $contents,
    $count
);

if (($count ?? 0) < 1) {
    fwrite(STDERR, "[patch-voice] Pattern not found; package may have changed.\n");
    exit(1);
}

if (file_put_contents($target, $patched) === false) {
    fwrite(STDERR, "[patch-voice] Failed to write patched WS.php\n");
    exit(1);
}

fwrite(STDOUT, "[patch-voice] Patched MAX_DAVE_PROTOCOL_VERSION to 1.\n");
