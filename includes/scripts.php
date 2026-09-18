<?php
/**
 * Central helpers for loading organized JavaScript and CSS assets.
 *
 * Layout:
 *   assets/js/core/   — shared (poll, loading, auth, …)
 *   assets/js/admin/  — staff portal pages
 *   assets/js/public/ — citizen-facing pages
 *   assets/css/admin/ — staff portal styles
 *   assets/css/public/ — citizen-facing styles
 */

function cssAsset(string $path): string
{
    return 'assets/css/' . ltrim(str_replace('\\', '/', $path), '/');
}

function stylesheetTag(string $path): string
{
    $relative = cssAsset($path);
    $href = $relative;
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($fullPath)) {
        $href .= '?v=' . filemtime($fullPath);
    }

    return '<link rel="stylesheet" href="' . htmlspecialchars($href) . '">';
}

function adminCoreStyles(): string
{
    return stylesheetTag('admin/shell.css') . "\n    "
        . stylesheetTag('admin/print-layout.css');
}

function adminPageStyles(string $page): string
{
    $relative = cssAsset('admin/' . $page . '.css');
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($fullPath)) {
        return '';
    }

    return stylesheetTag('admin/' . $page . '.css');
}

function adminLayoutHeadStyles(?string $page = null): string
{
    $tags = [adminCoreStyles(), actionCoreStyles()];
    if ($page !== null && $page !== '') {
        $pageStyles = adminPageStyles($page);
        if ($pageStyles !== '') {
            $tags[] = $pageStyles;
        }
    }

    return implode("\n    ", $tags);
}

function publicStylesheet(string $name): string
{
    return stylesheetTag('public/' . $name . '.css');
}

function jsAsset(string $path): string
{
    return 'assets/js/' . ltrim(str_replace('\\', '/', $path), '/');
}

function vendorAsset(string $path): string
{
    return 'assets/vendor/' . ltrim(str_replace('\\', '/', $path), '/');
}

function scriptAttrs(array $attrs = []): string
{
    if (!array_key_exists('defer', $attrs) && !array_key_exists('async', $attrs)) {
        $attrs['defer'] = 'defer';
    }

    $html = '';
    foreach ($attrs as $key => $value) {
        if ($value === false || $value === null || $value === '') {
            continue;
        }
        $html .= ' ' . htmlspecialchars((string) $key);
        if ($value !== true) {
            $html .= '="' . htmlspecialchars((string) $value) . '"';
        }
    }

    return $html;
}

function vendorScriptTag(string $path, array $attrs = []): string
{
    // Tailwind JIT must run before first paint; defer causes a flash of unstyled HTML on navigation.
    if ($path === 'tailwindcss.js' && !array_key_exists('defer', $attrs) && !array_key_exists('async', $attrs)) {
        $attrs['defer'] = false;
    }

    $relative = vendorAsset($path);
    $src = $relative;
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($fullPath)) {
        $src .= '?v=' . filemtime($fullPath);
    }

    return '<script src="' . htmlspecialchars($src) . '"' . scriptAttrs($attrs) . '></script>';
}

function vendorStylesheetTag(string $path): string
{
    $relative = vendorAsset($path);
    $href = $relative;
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($fullPath)) {
        $href .= '?v=' . filemtime($fullPath);
    }

    return '<link rel="stylesheet" href="' . htmlspecialchars($href) . '">';
}

function interFontTags(): string
{
    $fontPath = vendorAsset('inter/inter-latin-400-normal.woff2');
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fontPath);
    if (is_file($fullPath)) {
        $fontPath .= '?v=' . filemtime($fullPath);
    }

    return '<link rel="preload" href="' . htmlspecialchars($fontPath) . '" as="font" type="font/woff2" crossorigin>' . "\n    "
        . vendorStylesheetTag('inter/inter.css');
}

/** Local Tailwind + Inter + Lucide — works on LAN without internet. */
function alcrosUiHead(): string
{
    return vendorScriptTag('tailwindcss.js') . "\n    "
        . interFontTags() . "\n    "
        . vendorScriptTag('lucide.min.js');
}

function scriptTag(string $path, array $attrs = []): string
{
    $relative = jsAsset($path);
    $src = $relative;
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($fullPath)) {
        $src .= '?v=' . filemtime($fullPath);
    }
    return '<script src="' . htmlspecialchars($src) . '"' . scriptAttrs($attrs) . '></script>';
}

function scriptTags(array $paths, array $attrs = []): string
{
    return implode("\n    ", array_map(fn (string $path) => scriptTag($path, $attrs), $paths));
}

function pageConfigJson(array $config, string $id = 'page-config'): string
{
    $json = json_encode(
        $config,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    return '<script type="application/json" id="' . htmlspecialchars($id) . '">' . $json . '</script>';
}

function actionResultScript(?array $flash): string
{
    if (!$flash || !isset($flash[0], $flash[1]) || $flash[1] === '') {
        return '';
    }

    return pageConfigJson([
        'type' => $flash[0] === 'success' ? 'success' : 'error',
        'message' => (string) $flash[1],
    ], 'alcros-action-result');
}

function actionCoreStyles(): string
{
    return stylesheetTag('core/action-ui.css');
}

function actionCoreScripts(): string
{
    return actionCoreStyles() . "\n    " . scriptTags([
        'core/confirm.js',
        'core/loading.js',
        'core/action-result.js',
    ]);
}

function adminCoreScripts(): string
{
    $scripts = [
        'admin/sidebar.js',
        'core/admin-auth.js',
        'core/confirm.js',
        'core/loading.js',
        'core/action-result.js',
        'core/poll.js',
        'admin/admin-live.js',
        'admin/notifications.js',
        'admin/request-badge.js',
        'admin/appointment-badge.js',
        'core/realtime.js',
    ];

    return scriptTags($scripts);
}

function lucideInitScript(): string
{
    return scriptTag('core/lucide-init.js');
}
