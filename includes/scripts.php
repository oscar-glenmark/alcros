<?php
/**
 * Central helpers for loading organized JavaScript assets.
 *
 * Layout:
 *   assets/js/core/   — shared (poll, loading, auth, …)
 *   assets/js/admin/  — staff portal pages
 *   assets/js/public/ — citizen-facing pages
 */

function jsAsset(string $path): string
{
    return 'assets/js/' . ltrim(str_replace('\\', '/', $path), '/');
}

function scriptTag(string $path, array $attrs = []): string
{
    $src = htmlspecialchars(jsAsset($path));
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

function adminCoreScripts(): string
{
    return scriptTags([
        'admin/sidebar.js',
        'core/admin-auth.js',
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

function pageScripts(array $paths, array $attrs = []): string
{
    $out = scriptTags($paths, $attrs);
    return $out !== '' ? $out . "\n    " . lucideInitScript() : lucideInitScript();
}
