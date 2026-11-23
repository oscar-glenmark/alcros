<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$missing = [];
$seen = [];

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $p = $f->getPathname();
    if (str_contains($p, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $c = file_get_contents($p);
    if ($c === false) {
        continue;
    }
    if (preg_match_all("#(?:scriptTag|stylesheetTag|publicStylesheetTag)\\s*\\(\\s*['\"]([a-z0-9][a-z0-9_./-]+)['\"]#i", $c, $m)) {
        foreach ($m[1] as $rel) {
            if (str_ends_with($rel, '/')) {
                continue;
            }
            $seen[$rel] = 'app';
        }
    }
    if (preg_match_all("#vendor(?:Script|Stylesheet)Tag\\s*\\(\\s*['\"]([a-z0-9][a-z0-9_./-]+)['\"]#i", $c, $m)) {
        foreach ($m[1] as $rel) {
            $seen[$rel] = 'vendor';
        }
    }
}

require_once $root . '/includes/scripts.php';

function verifyAssetPath(string $root, string $rel, string $kind): ?string
{
    if ($kind === 'vendor') {
        $relative = str_contains($rel, '.css')
            ? 'assets/vendor/' . ltrim($rel, '/')
            : vendorAsset($rel);
    } elseif (str_contains($rel, '.css')) {
        $relative = cssAsset($rel);
    } else {
        $relative = jsAsset($rel);
    }
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

    return is_file($full) ? null : $relative;
}

foreach ($seen as $rel => $kind) {
    $err = verifyAssetPath($root, $rel, $kind === 'vendor' ? 'vendor' : 'app');
    if ($err !== null) {
        $missing[] = $rel . ' -> ' . $err;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "Missing assets:\n" . implode("\n", $missing) . "\n");
    exit(1);
}

echo 'OK: ' . count($seen) . " asset references resolved\n";