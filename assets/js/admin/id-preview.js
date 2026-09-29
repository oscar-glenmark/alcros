(function (global) {
    'use strict';

    function escapeAttr(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function sideLabel(label) {
        return label.replace(/\s*ID\s*$/i, '').trim() || label;
    }

    /** App root path (e.g. /alcros/) from the current admin page. */
    function appRootPath() {
        var path = window.location.pathname || '/';
        var slash = path.lastIndexOf('/');
        return slash >= 0 ? path.slice(0, slash + 1) : '/';
    }

    /** Ensure file.php URLs are under the app root and carry fresh staff auth + raw=1 for <img>. */
    function resolveStaffUploadUrl(path) {
        if (!path) return '';

        try {
            var url = new URL(String(path), window.location.origin + appRootPath());

            if (!/\/file\.php$/i.test(url.pathname)) {
                return String(path);
            }

            var root = appRootPath().replace(/\/$/, '');
            if (root && !url.pathname.startsWith(root + '/')) {
                url.pathname = root + '/file.php';
            }

            url.searchParams.set('raw', '1');

            var token = sessionStorage.getItem('alcros_auth') || '';
            if (token) {
                url.searchParams.set('alcros_auth', token);
            }

            return url.pathname + '?' + url.searchParams.toString();
        } catch (e) {
            return String(path);
        }
    }

    function idPreviewCard(label, path, eager) {
        if (!path) return '';

        path = resolveStaffUploadUrl(path);
        if (!path) return '';

        var safePath = escapeAttr(path);
        var isPdf = /\.pdf(\?|$)/i.test(path);
        var loadAttr = eager ? ' loading="eager" fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"';

        if (isPdf) {
            return '<a href="' + safePath + '" target="_blank" rel="noopener noreferrer" class="admin-id-preview-card admin-id-preview-card--pdf">' +
                '<span class="admin-id-preview-label">' + escapeAttr(sideLabel(label)) + '</span>' +
                '<div class="admin-id-preview-pdf">' +
                '<i data-lucide="file-text" class="w-7 h-7 mb-1 opacity-60"></i>' +
                '<span>PDF · Click to open</span></div>' +
                '<span class="admin-id-preview-caption">' + escapeAttr(label) + '</span></a>';
        }

        return '<a href="' + safePath + '" target="_blank" rel="noopener noreferrer" class="admin-id-preview-card">' +
            '<span class="admin-id-preview-label">' + escapeAttr(sideLabel(label)) + '</span>' +
            '<img src="' + safePath + '" alt="' + escapeAttr(label) + '" class="admin-id-preview-image"' + loadAttr +
            ' onerror="this.classList.add(\'admin-id-preview-image--error\');this.alt=\'Preview unavailable\';">' +
            '<span class="admin-id-preview-caption">' + escapeAttr(label) + ' · Click to open</span></a>';
    }

    function renderIdPreviewGrid(frontPath, backPath) {
        var html = idPreviewCard('Front ID', frontPath, true) + idPreviewCard('Back ID', backPath, false);
        if (!html) {
            return '<span class="text-xs text-gray-400 italic">No ID files uploaded.</span>';
        }
        return '<div class="admin-id-preview-grid">' + html + '</div>';
    }

    global.AlcrosIdPreview = {
        renderGrid: renderIdPreviewGrid,
        resolveUrl: resolveStaffUploadUrl
    };
})(window);
