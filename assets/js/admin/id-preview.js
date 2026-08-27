(function (global) {
    'use strict';

    function escapeAttr(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    function sideLabel(label) {
        return label.replace(/\s*ID\s*$/i, '').trim() || label;
    }

    function idPreviewCard(label, path) {
        if (!path) return '';

        var safePath = escapeAttr(path);
        var isPdf = /\.pdf(\?|$)/i.test(path);

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
            '<img src="' + safePath + '" alt="' + escapeAttr(label) + '" class="admin-id-preview-image" loading="lazy">' +
            '<span class="admin-id-preview-caption">' + escapeAttr(label) + ' · Click to open</span></a>';
    }

    function renderIdPreviewGrid(frontPath, backPath) {
        var html = idPreviewCard('Front ID', frontPath) + idPreviewCard('Back ID', backPath);
        if (!html) {
            return '<span class="text-xs text-gray-400 italic">No ID files uploaded.</span>';
        }
        return '<div class="admin-id-preview-grid">' + html + '</div>';
    }

    global.AlcrosIdPreview = {
        card: idPreviewCard,
        renderGrid: renderIdPreviewGrid
    };
})(window);
