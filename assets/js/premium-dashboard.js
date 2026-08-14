/**
 * EduCore Premium Dashboard Effects
 */
document.addEventListener("DOMContentLoaded", function() {
    const sidebar = document.getElementById('adminSidebar');
    if (!sidebar) return;

    const legacyScrollStorageKey = 'educore:admin-sidebar-scroll-top:v1';
    let storage = null;

    try {
        storage = window.sessionStorage;
    } catch (error) {
        // Continue with active-link positioning when storage is unavailable.
    }

    function isSidebarPageLink(link) {
        if (!link || typeof link.getAttribute !== 'function') return false;

        const href = (link.getAttribute('href') || '').trim();
        return href !== ''
            && href.charAt(0) !== '#'
            && !/^javascript:/i.test(href)
            && link.getAttribute('data-bs-toggle') !== 'collapse';
    }

    function getSidebarVariantKey() {
        const signature = Array.from(sidebar.querySelectorAll('a.nav-link[href]'))
            .filter(isSidebarPageLink)
            .map(function(link) { return link.getAttribute('href'); })
            .join('|');
        let hash = 2166136261;

        for (let index = 0; index < signature.length; index++) {
            hash ^= signature.charCodeAt(index);
            hash = Math.imul(hash, 16777619);
        }

        return (hash >>> 0).toString(36);
    }

    const scrollStorageKey = legacyScrollStorageKey + ':' + getSidebarVariantKey();

    function saveSidebarScroll() {
        if (!storage) return;

        try {
            storage.setItem(scrollStorageKey, String(Math.max(0, Math.round(sidebar.scrollTop))));
        } catch (error) {
            // Ignore browsers that block sessionStorage.
        }
    }

    function getStoredSidebarScroll() {
        if (!storage) return null;

        try {
            let storedValue = storage.getItem(scrollStorageKey);
            if (storedValue === null) {
                storedValue = storage.getItem(legacyScrollStorageKey);
                if (storedValue !== null) {
                    storage.setItem(scrollStorageKey, storedValue);
                    storage.removeItem(legacyScrollStorageKey);
                }
            }
            if (storedValue === null) return null;

            const scrollTop = Number(storedValue);
            return Number.isFinite(scrollTop) && scrollTop >= 0 ? scrollTop : null;
        } catch (error) {
            return null;
        }
    }

    function revealActiveSidebarLink() {
        const activeLinks = Array.from(sidebar.querySelectorAll('.nav-link.active'));
        const activeLink = activeLinks.find(isSidebarPageLink) || null;
        if (!activeLink) return;

        sidebar.querySelectorAll('.nav-link[aria-current]').forEach(function(link) {
            if (link !== activeLink) link.removeAttribute('aria-current');
        });
        activeLink.setAttribute('aria-current', 'page');

        if (typeof activeLink.scrollIntoView !== 'function') return;

        if (typeof sidebar.getBoundingClientRect === 'function'
            && typeof activeLink.getBoundingClientRect === 'function') {
            const sidebarRect = sidebar.getBoundingClientRect();
            const activeRect = activeLink.getBoundingClientRect();
            if (activeRect.top >= sidebarRect.top && activeRect.bottom <= sidebarRect.bottom) return;
        }

        activeLink.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    function restoreSidebarScroll() {
        const storedScrollTop = getStoredSidebarScroll();
        if (storedScrollTop !== null) {
            sidebar.scrollTop = storedScrollTop;
        }

        revealActiveSidebarLink();
    }

    sidebar.addEventListener('scroll', saveSidebarScroll, { passive: true });
    sidebar.addEventListener('click', function(event) {
        const target = event.target && typeof event.target.closest === 'function'
            ? event.target.closest('a.nav-link[href]')
            : null;
        if (target && target.getAttribute('href') !== '#') saveSidebarScroll();
    }, true);
    window.addEventListener('pagehide', saveSidebarScroll);

    if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(function() {
            window.requestAnimationFrame(restoreSidebarScroll);
        });
    } else {
        restoreSidebarScroll();
    }
});

document.addEventListener("DOMContentLoaded", function() {
    // --- Counter Animation ---
    document.querySelectorAll('.stat-card-number').forEach(function (numberElement) {
        if (numberElement.classList.contains('counter') || numberElement.querySelector('.counter')) return;
        const text = numberElement.textContent.trim();
        const match = text.match(/^([\d,]+(?:\.\d+)?)\s*([^\d]*)$/);
        if (!match) return;

        const target = Number(match[1].replace(/,/g, ''));
        if (!Number.isFinite(target)) return;

        const counter = document.createElement('span');
        counter.className = 'counter';
        counter.dataset.target = String(target);
        counter.textContent = '0';
        numberElement.textContent = '';
        numberElement.appendChild(counter);
        if (match[2]) numberElement.appendChild(document.createTextNode(match[2]));
    });

    const counters = document.querySelectorAll('.counter');

    counters.forEach(function (counter) {
        const targetText = counter.getAttribute('data-target') || '0';
        const renderedText = counter.textContent.trim();
        const numericText = renderedText.replace(/,/g, '');
        if (!counter.dataset.suffix && numericText && !Number.isNaN(Number.parseFloat(numericText))) {
            const suffix = renderedText.replace(/^[\d,.]+\s*/, '');
            if (suffix) counter.dataset.suffix = ' ' + suffix;
        }
        counter.textContent = '0' + (counter.dataset.suffix || '');
        counter.dataset.target = String(Number(targetText.replace(/,/g, '')) || 0);
    });

    const animateCounter = (counter) => {
        const target = +counter.getAttribute('data-target');
        const duration = +(counter.getAttribute('data-duration') || 800); // Faster duration (800ms default)
        const precision = Number.isInteger(target) ? 0 : Math.min(2, (String(target).split('.')[1] || '').length);
        const suffix = counter.dataset.suffix || '';

        const prefersReducedMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const preferenceDisablesAnimation = document.body
            && document.body.classList.contains('counter-animation-disabled');
        if (window.disableCounterAnimation || preferenceDisablesAnimation || prefersReducedMotion) {
            counter.innerText = target.toLocaleString(undefined, {
                minimumFractionDigits: precision,
                maximumFractionDigits: precision
            }) + suffix;
            return;
        }

        let startTime = null;

        const updateCount = (timestamp) => {
            if (!startTime) startTime = timestamp;
            const progress = Math.min((timestamp - startTime) / duration, 1);
            const currentCount = progress * target;

            counter.innerText = currentCount.toLocaleString(undefined, {
                minimumFractionDigits: precision,
                maximumFractionDigits: precision
            }) + suffix;

            if (progress < 1) {
                requestAnimationFrame(updateCount);
            } else {
                counter.innerText = target.toLocaleString(undefined, {
                    minimumFractionDigits: precision,
                    maximumFractionDigits: precision
                }) + suffix;
            }
        };
        requestAnimationFrame(updateCount);
    };

    // Use Intersection Observer for counters
    const observerOptions = { threshold: 0.1 };
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    counters.forEach(counter => observer.observe(counter));
});
