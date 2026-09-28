<?php
/**
 * Writes config/database.local.php from environment (used by GitHub Actions deploy).
 *
 * Required env: INFINITYFREE_DB_HOST, INFINITYFREE_DB_NAME, INFINITYFREE_DB_USER, INFINITYFREE_DB_PASSWORD
 */

$host = getenv('INFINITYFREE_DB_HOST') ?: '';
$name = getenv('INFINITYFREE_DB_NAME') ?: '';
$user = getenv('INFINITYFREE_DB_USER') ?: '';
$pass = getenv('INFINITYFREE_DB_PASSWORD');
if ($pass === false) {
    $pass = '';
}

if ($host === '' || $name === '' || $user === '') {
    fwrite(STDERR, "Missing INFINITYFREE_DB_HOST, INFINITYFREE_DB_NAME, or INFINITYFREE_DB_USER.\n");
    exit(1);
}

$lines = [
    '<?php',
    '/** Generated for production deploy — do not commit. */',
    'define(' . var_export('DB_HOST', true) . ', ' . var_export($host, true) . ');',
    'define(' . var_export('DB_NAME', true) . ', ' . var_export($name, true) . ');',
    'define(' . var_export('DB_USER', true) . ', ' . var_export($user, true) . ');',
    'define(' . var_export('DB_PASS', true) . ', ' . var_export($pass, true) . ');',
    '',
];

$target = dirname(__DIR__) . '/config/database.local.php';
file_put_contents($target, implode("\n", $lines));
fwrite(STDOUT, "Wrote {$target}\n");
