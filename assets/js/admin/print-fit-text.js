(function () {
    'use strict';

    function parsePt(value) {
        var n = parseFloat(value);
        return isFinite(n) && n > 0 ? n : 0;
    }

    function baseFontPt(el) {
        var stored = parsePt(el.getAttribute('data-base-font-pt'));
        if (stored > 0) {
            return stored;
        }

        var inline = String(el.style.fontSize || '');
        if (/pt/i.test(inline)) {
            stored = parsePt(inline);
            if (stored > 0) {
                el.setAttribute('data-base-font-pt', String(stored));
                return stored;
            }
        }

        return 10;
    }

    function overflows(el) {
        return el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1;
    }

    function fitPrintField(el) {
        if (!el || !el.classList.contains('print-field')) {
            return;
        }
        if (el.classList.contains('print-field--certification')) {
            return;
        }

        var text = (el.textContent || '').replace(/\u00a0/g, ' ').trim();
        if (!text) {
            return;
        }

        if (el.clientWidth < 2 || el.clientHeight < 2) {
            return;
        }

        var basePt = baseFontPt(el);
        var minPt = Math.max(4, Math.round(basePt * 0.4 * 4) / 4);
        var lo = minPt;
        var hi = basePt;

        el.style.fontSize = basePt + 'pt';
        if (!overflows(el)) {
            return;
        }

        var guard = 0;
        while (hi - lo > 0.2 && guard < 40) {
            guard++;
            var mid = Math.round(((lo + hi) / 2) * 4) / 4;
            el.style.fontSize = mid + 'pt';
            if (overflows(el)) {
                hi = mid;
            } else {
                lo = mid;
            }
        }

        el.style.fontSize = lo + 'pt';
        if (overflows(el) && lo > minPt) {
            el.style.fontSize = minPt + 'pt';
        }
    }

    function ownerDocument(root) {
        if (root && root.nodeType === 9) {
            return root;
        }
        if (root && root.ownerDocument) {
            return root.ownerDocument;
        }
        return document;
    }

    function runWhenReady(doc, run) {
        var finished = false;
        function done() {
            if (finished) {
                return;
            }
            finished = true;
            run();
        }

        window.setTimeout(done, 1500);

        try {
            if (doc.fonts && doc.fonts.ready) {
                doc.fonts.ready.then(done).catch(done);
                return;
            }
        } catch (err) {
            /* ignore */
        }

        requestAnimationFrame(function () {
            requestAnimationFrame(done);
        });
    }

    function fitAll(root, done) {
        root = root && root.querySelectorAll ? root : document;
        var fields = root.querySelectorAll('.print-field');
        if (!fields.length) {
            if (typeof done === 'function') {
                done();
            }
            return;
        }

        var doc = ownerDocument(root);

        runWhenReady(doc, function () {
            fields.forEach(fitPrintField);
            if (typeof done === 'function') {
                done();
            }
        });
    }

    window.AlcrosPrintFitText = {
        fitOne: fitPrintField,
        fitAll: fitAll
    };
})();
