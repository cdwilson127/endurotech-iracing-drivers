/* Endurotech iRacing Drivers — Frontend JS v1.6 */
(function () {
    'use strict';

    /* ----------------------------------------------------------------
     * Animated stat counters
     * Uses IntersectionObserver to trigger count-up when scrolled in.
     * Elements must have data-counter="{target}" attribute.
     * ---------------------------------------------------------------- */
    function initCounters() {
        var els = document.querySelectorAll('[data-counter]');
        if ( ! els.length ) { return; }

        if ( ! ('IntersectionObserver' in window) ) {
            // Fallback: just show the numbers
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

                var start    = 0;
                var duration = Math.min(1200, Math.max(400, target * 0.2));
                var startTime = null;

                function step(timestamp) {
                    if ( ! startTime ) { startTime = timestamp; }
                    var elapsed  = timestamp - startTime;
                    var progress = Math.min(elapsed / duration, 1);
                    // Ease-out cubic
                    var eased    = 1 - Math.pow(1 - progress, 3);
                    var current  = Math.round(eased * target);
                    el.textContent = current.toLocaleString();
                    if ( progress < 1 ) {
                        requestAnimationFrame(step);
                    } else {
                        el.textContent = target.toLocaleString();
                        el.classList.remove('edr-counter-init');
                        el.classList.add('edr-counter-done');
                    }
                }

                el.classList.remove('edr-counter-init');
                requestAnimationFrame(step);
                observer.unobserve(el);
            });
        }, { threshold: 0.2 });

        els.forEach(function (el) {
            observer.observe(el);
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

                // Update active state
                bar.querySelectorAll('.edr-filter-btn').forEach(function (b) {
                    b.classList.remove('edr-filter-active');
                });
                btn.classList.add('edr-filter-active');

                // Show/hide cards
                cards.forEach(function (card) {
                    if ( filter === 'all' || card.getAttribute('data-role') === filter ) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
    }

    /* ----------------------------------------------------------------
     * Init on DOM ready
     * ---------------------------------------------------------------- */
    function init() {
        initCounters();
        initFilterBar();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
