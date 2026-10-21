/* Tech SG Academy – UI interactions (nav, reveal, counters, auth helpers). */
(() => {
    'use strict';

    /* Mobile navigation */
    const navToggle = document.querySelector('[data-nav-toggle]');
    const mobileNav = document.getElementById('mobile-nav');

    const setNav = (open) => {
        document.body.classList.toggle('nav-open', open);
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', String(open));
            navToggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        }
    };

    if (navToggle && mobileNav) {
        navToggle.addEventListener('click', () => setNav(!document.body.classList.contains('nav-open')));
        mobileNav.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('a')) setNav(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setNav(false);
        });
        window.addEventListener('resize', () => {
            if (window.innerWidth > 820) setNav(false);
        });
    }

    /* Scroll-reveal animations */
    const revealables = document.querySelectorAll('[data-reveal]');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -36px 0px' });
        revealables.forEach((el) => observer.observe(el));
    } else {
        revealables.forEach((el) => el.classList.add('is-visible'));
    }

    /* Animated counters */
    const counters = document.querySelectorAll('[data-count]');
    const runCounter = (el) => {
        const target = Number(el.dataset.count || '0');
        const suffix = el.dataset.suffix || '';
        const duration = 1300;
        const start = performance.now();
        const frame = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = String(Math.round(target * eased)) + suffix;
            if (progress < 1) requestAnimationFrame(frame);
        };
        requestAnimationFrame(frame);
    };
    if ('IntersectionObserver' in window && counters.length > 0) {
        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                runCounter(entry.target);
                counterObserver.unobserve(entry.target);
            });
        }, { threshold: 0.6 });
        counters.forEach((el) => counterObserver.observe(el));
    }

    /* Password visibility toggle */
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.input-wrap')?.querySelector('input');
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.classList.toggle('is-on', show);
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    /* Review form: swap context placeholder between student and client modes */
    const reviewContext = document.getElementById('review-context');
    const reviewerTypeInputs = document.querySelectorAll('input[name="reviewer_type"]');
    if (reviewContext && reviewerTypeInputs.length) {
        const applyContext = () => {
            const checked = document.querySelector('input[name="reviewer_type"]:checked');
            if (checked) reviewContext.placeholder = checked.dataset.contextPlaceholder || '';
        };
        reviewerTypeInputs.forEach((input) => input.addEventListener('change', applyContext));
        applyContext();
    }

})();
