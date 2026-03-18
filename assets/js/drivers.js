/* Endurotech iRacing Drivers — Frontend JS v1.9 */
(function () {
    'use strict';

    var isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

    /* ----------------------------------------------------------------
     * Animated stat counters — no comma formatting for iRating
     * ---------------------------------------------------------------- */
    function formatCount(n) {
        return String(n);
    }

    function initCounters() {
        var els = document.querySelectorAll('[data-counter]');
        if ( ! els.length ) { return; }

        if ( ! ('IntersectionObserver' in window) ) {
            els.forEach(function (el) {
                el.classList.remove('edr-counter-init');
                el.classList.add('edr-counter-done');
            });
            return;
        }

        els.forEach(function (el) {
            el.classList.add('edr-counter-init');
        });

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if ( ! entry.isIntersecting ) { return; }
                var el     = entry.target;
                var target = parseInt(el.getAttribute('data-counter'), 10);
                if ( isNaN(target) || target < 0 ) {
                    el.classList.remove('edr-counter-init');
                    el.classList.add('edr-counter-done');
                    observer.unobserve(el);
                    return;
                }

                var duration  = Math.min(1200, Math.max(400, target * 0.2));
                var startTime = null;

                function step(timestamp) {
                    if ( ! startTime ) { startTime = timestamp; }
                    var elapsed  = timestamp - startTime;
                    var progress = Math.min(elapsed / duration, 1);
                    var eased    = 1 - Math.pow(1 - progress, 3);
                    var current  = Math.round(eased * target);
                    el.textContent = formatCount(current);
                    if ( progress < 1 ) {
                        requestAnimationFrame(step);
                    } else {
                        el.textContent = formatCount(target);
                        el.classList.remove('edr-counter-init');
                        el.classList.add('edr-counter-done');
                    }
                }

                el.classList.remove('edr-counter-init');
                requestAnimationFrame(step);
                observer.unobserve(el);
            });
        }, { threshold: 0.2 });

        els.forEach(function (el) { observer.observe(el); });
    }

    /* ----------------------------------------------------------------
     * Mobile tap-to-flip for driver cards
     * On touch devices, hover doesn't fire; we toggle .edr-flipped on tap.
     * Tapping outside any card un-flips all cards.
     * ---------------------------------------------------------------- */
    function initMobileFlip() {
        if ( ! isTouch ) { return; }

        document.addEventListener('click', function (e) {
            var card = e.target.closest('.edr-driver-card.edr-flippable');
            if ( card ) {
                var wasFlipped = card.classList.contains('edr-flipped');
                // Unflip all cards in this grid
                var grid = card.closest('.edr-cards-grid');
                if ( grid ) {
                    grid.querySelectorAll('.edr-driver-card.edr-flippable').forEach(function (c) {
                        c.classList.remove('edr-flipped');
                    });
                }
                if ( ! wasFlipped ) {
                    card.classList.add('edr-flipped');
                }
                e.stopPropagation();
            } else {
                // Tapped outside — unflip all
                document.querySelectorAll('.edr-driver-card.edr-flippable.edr-flipped').forEach(function (c) {
                    c.classList.remove('edr-flipped');
                });
            }
        });
    }

    /* ----------------------------------------------------------------
     * Role filter bar
     * ---------------------------------------------------------------- */
    function initFilterBar() {
        var bars = document.querySelectorAll('.edr-filter-bar');
        bars.forEach(function (bar) {
            var wrap  = bar.closest('.edr-drivers-wrap');
            if ( ! wrap ) { return; }
            var cards = wrap.querySelectorAll('.edr-driver-card[data-role]');

            bar.addEventListener('click', function (e) {
                var btn = e.target.closest('.edr-filter-btn');
                if ( ! btn ) { return; }
                var filter = btn.getAttribute('data-filter');
                bar.querySelectorAll('.edr-filter-btn').forEach(function (b) {
                    b.classList.remove('edr-filter-active');
                });
                btn.classList.add('edr-filter-active');
                cards.forEach(function (card) {
                    card.style.display = ( filter === 'all' || card.getAttribute('data-role') === filter ) ? '' : 'none';
                });
            });
        });
    }

    /* ----------------------------------------------------------------
     * Init
     * ---------------------------------------------------------------- */
    function init() {
        initCounters();
        initMobileFlip();
        initFilterBar();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
