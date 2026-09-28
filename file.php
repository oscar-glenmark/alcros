<?php
/**
 * Authenticated file delivery for sensitive uploads (IDs, staff photos).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireStaffLogin();

/**
 * Stream bytes for embeds (<img>, iframe PDF, etc.). Top-level tab visits get an HTML shell with favicon + title.
 */
function filePhpShouldStreamBinary(): bool
{
    if (isset($_GET['raw']) && (string) $_GET['raw'] === '1') {
        return true;
    }

    $dest = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
    if ($dest === '') {
        return true;
    }

    return in_array($dest, ['image', 'iframe', 'embed', 'video', 'audio', 'style', 'script', 'font'], true);
}

function filePhpRespondHtmlError(int $status, string $title, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $pageTitle = $title . ' - ALCROS';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo faviconLinkTag();
    echo '<title>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;color:#0f172a}'
        . '.card{max-width:28rem;background:#fff;border:1px solid #e2e8f0;border-radius:1rem;padding:2rem;text-align:center}'
        . 'h1{font-size:1.125rem;margin:0 0 .5rem}p{margin:0;color:#64748b;font-size:.875rem;line-height:1.5}</style>';
    echo '</head><body><div class="card"><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
    exit;
}

function filePhpRenderViewer(string $relative, string $mime, string $filename): never
{
    $rawUrl = buildAuthUrl('file.php', ['f' => $relative, 'raw' => '1']);
    $pageTitle = $filename . ' - ALCROS';

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo faviconLinkTag();
    echo '<title>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>'
        . 'html,body{height:100%;margin:0;background:#0f172a;color:#f8fafc;font-family:system-ui,sans-serif}'
        . '.bar{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;background:#071428;border-bottom:1px solid rgba(255,255,255,.08)}'
        . '.bar img{width:28px;height:28px;border-radius:9999px;background:#fff}'
        . '.bar span{font-size:.8125rem;font-weight:700;letter-spacing:.02em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '.frame{height:calc(100% - 53px);display:flex;align-items:center;justify-content:center;padding:1rem;box-sizing:border-box}'
        . '.frame img{max-width:100%;max-height:100%;object-fit:contain;border-radius:.5rem;background:#fff}'
        . '.frame iframe,.frame embed{width:100%;height:100%;border:0;border-radius:.5rem;background:#fff}'
        . '.fallback{font-size:.875rem;color:#cbd5e1;text-align:center}'
        . '.fallback a{color:#93c5fd}</style>';
    echo '</head><body>';
    echo '<div class="bar">' . alcrosFaviconImg(28) . '<span>' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '</span></div>';
    echo '<div class="frame">';

    if (str_starts_with($mime, 'image/')) {
        echo '<img src="' . htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8') . '" alt="">';
    } elseif ($mime === 'application/pdf') {
        echo '<iframe src="' . htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '"></iframe>';
    } else {
        echo '<p class="fallback">Preview is not available for this file type.<br><a href="'
            . htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8') . '">Download / open file</a></p>';
    }

    echo '</div></body></html>';
    exit;
}

$relative = normalizeUploadRelativePath((string) ($_GET['f'] ?? ''));
if ($relative === null) {
    filePhpRespondHtmlError(404, 'File not found', 'The requested file path is invalid or not allowed.');
}

$full = __DIR__ . '/' . $relative;
if (!is_file($full)) {
    filePhpRespondHtmlError(404, 'File not found', 'The requested file could not be found on the server.');
}

$mime = mime_content_type($full) ?: 'application/octet-stream';
$filename = basename($full);

if (!filePhpShouldStreamBinary()) {
    filePhpRenderViewer($relative, $mime, $filename);
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($full);
exit;
