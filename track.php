<?php
/**
 * Legacy URL — redirects to floating track on the home page.
 */
$code = strtoupper(trim($_GET['code'] ?? $_GET['track'] ?? ''));
$target = 'index.php' . ($code !== '' ? '?track=' . urlencode($code) : '');
header('Location: ' . $target, true, 302);
exit;
