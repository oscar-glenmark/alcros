(function () {
    'use strict';

    function bindTabPress(barSelector, tabSelector, moreItemSelector) {
        var bars = document.querySelectorAll(barSelector);
        if (!bars.length) {
            return;
        }

        bars.forEach(function (bar) {
            bar.querySelectorAll(tabSelector).forEach(function (tab) {
                if (tab.dataset.alcrosTabPress === '1') {
                    return;
                }
                tab.dataset.alcrosTabPress = '1';

                function pressOn() {
                    tab.classList.add('is-pressed');
                }

                function pressOff() {
                    tab.classList.remove('is-pressed');
                }

                tab.addEventListener('touchstart', pressOn, { passive: true });
                tab.addEventListener('touchend', pressOff, { passive: true });
                tab.addEventListener('touchcancel', pressOff, { passive: true });
                tab.addEventListener('mousedown', pressOn);
                tab.addEventListener('mouseup', pressOff);
                tab.addEventListener('mouseleave', pressOff);
            });

            if (moreItemSelector) {
                bar.querySelectorAll(moreItemSelector).forEach(function (item) {
                    if (item.dataset.alcrosTabPress === '1') {
                        return;
                    }
                    item.dataset.alcrosTabPress = '1';

                    function pressOn() {
                        item.classList.add('is-pressed');
                    }

                    function pressOff() {
                        item.classList.remove('is-pressed');
                    }

                    item.addEventListener('touchstart', pressOn, { passive: true });
                    item.addEventListener('touchend', pressOff, { passive: true });
                    item.addEventListener('touchcancel', pressOff, { passive: true });
                    item.addEventListener('mousedown', pressOn);
                    item.addEventListener('mouseup', pressOff);
                    item.addEventListener('mouseleave', pressOff);
                });
            }
        });
    }

    function init() {
        bindTabPress('.citizen-mobile-shell', '.citizen-mobile-tab', '.citizen-mobile-more-item');
        bindTabPress('.admin-mobile-shell', '.admin-mobile-tab', '.admin-mobile-more-item');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();