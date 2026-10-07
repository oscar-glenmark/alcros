(function (global) {
    'use strict';

    function escapeAttr(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function sideLabel(label) {
        return label.replace(/\s*ID\s*$/i, '').trim() || label;
    }

    function appRootPath() {
        var path = window.location.pathname || '/';
        var slash = path.lastIndexOf('/');
        return slash >= 0 ? path.slice(0, slash + 1) : '/';
    }

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

    function openLink(label, src, isPdf) {
        var pdfAttr = isPdf ? ' data-staff-id-pdf="1"' : '';
        return '<a href="#" class="admin-id-preview-open" data-staff-id-open data-staff-id-src="' + escapeAttr(src) +
            '" data-staff-id-label="' + escapeAttr(label) + '"' + pdfAttr + '>View full size</a>';
    }

    function idPreviewCard(label, path, eager) {
        if (!path) return '';

        var embedPath = resolveStaffUploadUrl(path);
        if (!embedPath) return '';

        var isPdf = /\.pdf(\?|$)/i.test(embedPath);
        var safeEmbedPath = escapeAttr(embedPath);
        var loadAttr = eager ? ' loading="eager" fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"';

        if (isPdf) {
            return '<div class="admin-id-preview-card">' +
                '<span class="admin-id-preview-label">' + escapeAttr(sideLabel(label)) + '</span>' +
                '<div class="admin-id-preview-pdf">' +
                '<i data-lucide="file-text" class="w-7 h-7 mb-1 opacity-60"></i>' +
                '<span>PDF document</span></div>' +
                openLink(label, embedPath, true) +
                '</div>';
        }

        return '<div class="admin-id-preview-card">' +
            '<span class="admin-id-preview-label">' + escapeAttr(sideLabel(label)) + '</span>' +
            '<img src="' + safeEmbedPath + '" alt="' + escapeAttr(label) + '" class="admin-id-preview-image" draggable="false"' + loadAttr +
            ' onerror="this.classList.add(\'admin-id-preview-image--error\');this.alt=\'Preview unavailable\';">' +
            openLink(label, embedPath, false) +
            '</div>';
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
        wireCards: function () { /* handled by staff-id-view.js */ },
        resolveUrl: resolveStaffUploadUrl
    };
})(window);
