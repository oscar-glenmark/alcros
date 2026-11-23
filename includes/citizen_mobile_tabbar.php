<?php
/**
 * Citizen portal - mobile bottom navigation (phones only).
 *
 * Set $citizenMobileTab before including: home | services | request | book | (empty = More highlights via overflow pages)
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/scripts.php';
require_once __DIR__ . '/lucide_icons.php';

$citizenMobileTab = $citizenMobileTab ?? '';
$publicRequestsAllowed = function_exists('arePublicRequestsAllowed') && arePublicRequestsAllowed();
$maintenanceMode = function_exists('isMaintenanceMode') && isMaintenanceMode();
$showRequestTab = $publicRequestsAllowed && !$maintenanceMode;

$citizenPrimaryTabs = ['home', 'services'];
if ($showRequestTab) {
    $citizenPrimaryTabs[] = 'request';
}
$citizenPrimaryTabs[] = 'book';
$citizenMoreActive = $citizenMobileTab !== '' && !in_array($citizenMobileTab, $citizenPrimaryTabs, true);

function citizenMobileTabLink(string $href, string $label, string $icon, bool $isActive): string
{
    $class = 'citizen-mobile-tab' . ($isActive ? ' is-active' : '');
    $iconHtml = lucideSvg($icon, 'pointer-events-none');

    return '<a href="' . htmlspecialchars($href) . '" class="' . $class . '">'
        . $iconHtml
        . '<span>' . htmlspecialchars($label) . '</span>'
        . '</a>';
}

function citizenMobileMoreLink(string $href, string $label, string $icon): string
{
    $iconHtml = lucideSvg($icon, 'citizen-mobile-more-item__icon pointer-events-none');

    return '<a href="' . htmlspecialchars($href) . '" class="citizen-mobile-more-item" role="menuitem">'
        . $iconHtml
        . '<span class="citizen-mobile-more-item__label">' . htmlspecialchars($label) . '</span>'
        . '</a>';
}

?>
<div class="citizen-mobile-shell no-print" id="citizenMobileShell">
    <div id="citizenMobileMoreBackdrop" class="citizen-mobile-more-backdrop hidden" aria-hidden="true"></div>
    <nav id="citizenMobileMoreDropup" class="citizen-mobile-more-dropup hidden" role="menu" aria-label="More navigation">
        <button type="button" class="citizen-mobile-more-item" role="menuitem" data-open-track>
            <?= lucideSvg('search', 'citizen-mobile-more-item__icon pointer-events-none') ?>
            <span class="citizen-mobile-more-item__label">Track request</span>
        </button>
        <?= citizenMobileMoreLink('index.php#about', 'About', 'info') ?>
        <?= citizenMobileMoreLink('index.php#faqs', 'FAQs', 'info') ?>
        <?= citizenMobileMoreLink('index.php#contact', 'Contact us', 'user') ?>
        <?= citizenMobileMoreLink('privacy.php', 'Privacy & safety', 'shield') ?>
        <?= citizenMobileMoreLink('login.php', 'Staff login', 'log-out') ?>
    </nav>
    <nav class="citizen-mobile-tabbar citizen-mobile-tabbar--public" aria-label="Citizen portal">
        <?= citizenMobileTabLink('index.php', 'Home', 'home', $citizenMobileTab === 'home') ?>
        <?= citizenMobileTabLink('services.php', 'Services', 'layers', $citizenMobileTab === 'services') ?>
        <?php if ($showRequestTab): ?>
        <?= citizenMobileTabLink('request.php?entry=hero', 'Request', 'file-text', $citizenMobileTab === 'request') ?>
        <?php endif; ?>
        <?= citizenMobileTabLink('book_appointment.php', 'Book', 'calendar', $citizenMobileTab === 'book') ?>
        <button
            type="button"
            class="citizen-mobile-tab<?= $citizenMoreActive ? ' is-active' : '' ?>"
            id="citizenMobileMoreTab"
            aria-label="More pages"
            aria-expanded="false"
            aria-controls="citizenMobileMoreDropup"
            aria-haspopup="menu"
        >
            <?= lucideSvg('ellipsis', 'pointer-events-none') ?>
            <span>More</span>
        </button>
    </nav>
</div>
<script>(function(){try{document.body.classList.add('citizen-portal');}catch(e){}})();</script>
<?= scriptTag('core/mobile-tab-press.js') ?>
<?= scriptTag('public/citizen-mobile-tabbar.js') ?>