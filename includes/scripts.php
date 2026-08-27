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
    return stylesheetTag('admin/shell.css');
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
    $tags = [adminCoreStyles()];
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

function scriptTag(string $path, array $attrs = []): string
{
    $relative = jsAsset($path);
    $src = $relative;
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($fullPath)) {
        $src .= '?v=' . filemtime($fullPath);
    }
    $src = htmlspecialchars($src);
    $extra = '';
    foreach ($attrs as $key => $value) {
        $extra .= ' ' . htmlspecialchars((string) $key) . '="' . htmlspecialchars((string) $value) . '"';
    }

    return '<script src="' . $src . '"' . $extra . '></script>';
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

function actionCoreScripts(): string
{
    return scriptTags([
        'core/confirm.js',
        'core/loading.js',
    ]);
}

function adminCoreScripts(): string
{
    return scriptTags([
        'admin/sidebar.js',
        'core/admin-auth.js',
        'core/confirm.js',
        'core/loading.js',
        'core/poll.js',
        'core/realtime.js',
        'admin/notifications.js',
        'core/reminders.js',
    ]);
}

function lucideInitScript(): string
{
    return scriptTag('core/lucide-init.js');
}
