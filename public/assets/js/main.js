/* Tech SG Academy – UI interactions
 *
 * This file contains the site-wide interactions plus the interactive
 * HOME / "WHY TECH SG ACADEMY" orbit section.
 *
 * PHP variables are NOT modified by this file.
 * The WHY section reads the already-rendered PHP $reasons cards from
 * the DOM and uses them as its data source.
 */

(() => {
    'use strict';

    /* =========================================================
       MOBILE NAVIGATION
       ========================================================= */

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
        navToggle.addEventListener('click', () => {
            const isOpen = document.body.classList.contains('nav-open');
            setNav(!isOpen);
        });

        mobileNav.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('a')) {
                setNav(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setNav(false);
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 820) {
                setNav(false);
            }
        });
    }


    /* =========================================================
       SCROLL REVEAL ANIMATIONS
       ========================================================= */

    const revealables = document.querySelectorAll('[data-reveal]');

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                });
            },
            {
                threshold: 0.15,
                rootMargin: '0px 0px -36px 0px'
            }
        );

        revealables.forEach((element) => {
            observer.observe(element);
        });
    } else {
        revealables.forEach((element) => {
            element.classList.add('is-visible');
        });
    }


    /* =========================================================
       ANIMATED COUNTERS
       ========================================================= */

    const counters = document.querySelectorAll('[data-count]');

    const runCounter = (element) => {
        const target = Number(element.dataset.count || '0');
        const suffix = element.dataset.suffix || '';
        const duration = 1300;
        const start = performance.now();

        const frame = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = String(Math.round(target * eased)) + suffix;

            if (progress < 1) {
                requestAnimationFrame(frame);
            }
        };

        requestAnimationFrame(frame);
    };

    if ('IntersectionObserver' in window && counters.length > 0) {
        const counterObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    runCounter(entry.target);
                    counterObserver.unobserve(entry.target);
                });
            },
            { threshold: 0.6 }
        );

        counters.forEach((element) => {
            counterObserver.observe(element);
        });
    }


    /* =========================================================
       PASSWORD VISIBILITY TOGGLE
       ========================================================= */

    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.input-wrap')?.querySelector('input');

            if (!input) {
                return;
            }

            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';

            button.classList.toggle('is-on', show);
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });


    /* =========================================================
       REVIEW FORM
       Student / Client placeholder switching
       ========================================================= */

    const reviewContext = document.getElementById('review-context');

    const reviewerTypeInputs = document.querySelectorAll('input[name="reviewer_type"]');

    if (reviewContext && reviewerTypeInputs.length) {
        const applyContext = () => {
            const checked = document.querySelector('input[name="reviewer_type"]:checked');

            if (checked) {
                reviewContext.placeholder = checked.dataset.contextPlaceholder || '';
            }
        };

        reviewerTypeInputs.forEach((input) => {
            input.addEventListener('change', applyContext);
        });

        applyContext();
    }


    /* =========================================================
       FAQ SMOOTH OPEN / CLOSE
       =========================================================

       Native <details> keeps control of state; the answer is
       wrapped so its height can animate smoothly in both
       directions instead of snapping open/closed.
       ========================================================= */

    const faqItems = document.querySelectorAll('.faq-item');
    const reduceFaqMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (faqItems.length && !reduceFaqMotion) {
        const FAQ_DURATION = 300;
        const FAQ_EASING = 'cubic-bezier(.4, 0, .2, 1)';

        faqItems.forEach((item) => {
            const summary = item.querySelector('summary');
            const answer = item.querySelector('.faq-answer');

            if (!summary || !answer) {
                return;
            }

            const wrap = document.createElement('div');
            wrap.className = 'faq-answer-wrap';
            answer.parentNode.insertBefore(wrap, answer);
            wrap.appendChild(answer);

            let animating = false;

            const settle = (animation) => {
                animation.cancel();
                wrap.style.height = '';
                wrap.style.opacity = '';
                animating = false;
            };

            summary.addEventListener('click', (event) => {
                event.preventDefault();

                if (animating) {
                    return;
                }

                animating = true;

                if (item.open) {
                    const startHeight = wrap.offsetHeight;

                    const animation = wrap.animate(
                        [
                            { height: startHeight + 'px', opacity: 1 },
                            { height: '0px', opacity: 0 }
                        ],
                        { duration: FAQ_DURATION, easing: FAQ_EASING, fill: 'forwards' }
                    );

                    animation.onfinish = () => {
                        item.open = false;
                        settle(animation);
                    };

                    return;
                }

                // Open first, but keep the wrapper collapsed so the
                // full answer never flashes before the animation.
                wrap.style.height = '0px';
                wrap.style.opacity = '0';
                item.open = true;

                const endHeight = answer.offsetHeight;

                const animation = wrap.animate(
                    [
                        { height: '0px', opacity: 0 },
                        { height: endHeight + 'px', opacity: 1 }
                    ],
                    { duration: FAQ_DURATION, easing: FAQ_EASING, fill: 'forwards' }
                );

                animation.onfinish = () => {
                    settle(animation);
                };
            });
        });
    }


    /* =========================================================
       WHY TECH SG ACADEMY — circular orbit selector
       =========================================================

       IMPORTANT:

       Existing PHP cards are NOT deleted.

       Existing PHP structure:

       $reasons
       └── .why-grid
           ├── .why-card
           ├── .why-card
           └── ...

       JavaScript reads those cards, hides the original grid and
       builds a circular orbit of pills on the right. Clicking a pill
       swaps the matching content on the left.

       Deliberately CLICK ONLY: there is no scroll-jacking, no sticky
       scroll stage, no mouse-wheel paging and no keyboard paging, so
       the page always scrolls normally. The orbit only reacts when a
       pill is clicked.
       ========================================================= */

    const whySection = document.querySelector('.why-orbit-section');
    const whySourceGrid = whySection?.querySelector('.why-grid');

    if (whySection && whySourceGrid) {
        const sourceCards = Array.from(whySourceGrid.querySelectorAll('.why-card'));

        if (sourceCards.length) {

            /* -------------------------------------------------
               Convert existing PHP cards into JS data
               ------------------------------------------------- */

            const reasons = sourceCards.map((card, index) => ({
                number: String(index + 1).padStart(2, '0'),
                icon: card.querySelector('.why-icon')?.textContent.trim() || '',
                title: card.querySelector('h3')?.textContent.trim() || '',
                description: card.querySelector('p')?.textContent.trim() || ''
            }));

            const total = reasons.length;


            /* -------------------------------------------------
               Create interactive UI
               ------------------------------------------------- */

            const ui = document.createElement('div');

            ui.className = 'why-orbit-ui';

            ui.innerHTML = `
                <div class="why-orbit-copy">
                    <div class="why-orbit-number">
                        <small>CARD</small>
                        <strong data-why-number>01</strong>
                    </div>
                    <div class="why-orbit-content" aria-live="polite">
                        <h3 data-why-title></h3>
                        <p data-why-description></p>
                    </div>
                </div>

                <div class="why-orbit-visual">
                    <div class="why-orbit-glow"></div>
                    <div class="why-orbit-circle">
                        <div class="why-orbit-ring"></div>
                        <div class="why-orbit-ring why-orbit-ring-inner"></div>
                        <div class="why-orbit-center" aria-live="polite">
                            <strong data-why-center>01</strong>
                            <span>of ${String(total).padStart(2, '0')}</span>
                        </div>
                        <div class="why-orbit-pills" data-why-pills role="tablist" aria-label="Academy benefits"></div>
                    </div>
                </div>
            `;

            whySection.appendChild(ui);


            /* -------------------------------------------------
               UI references
               ------------------------------------------------- */

            const title = ui.querySelector('[data-why-title]');
            const description = ui.querySelector('[data-why-description]');
            const number = ui.querySelector('[data-why-number]');
            const center = ui.querySelector('[data-why-center]');
            const pillsHost = ui.querySelector('[data-why-pills]');
            const content = ui.querySelector('.why-orbit-content');


            /* -------------------------------------------------
               Create circular orbit pills
               ------------------------------------------------- */

            let active = 0;
            let textTimer = 0;

            const pills = reasons.map((reason, index) => {
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'why-orbit-pill';
                button.dataset.index = String(index);
                button.setAttribute('role', 'tab');
                button.setAttribute('aria-selected', 'false');
                button.setAttribute('aria-label', `Card ${reason.number}: ${reason.title}`);

                // Each orbit pill shows the same emoji as its source card so the
                // benefit is recognisable at a glance.
                button.innerHTML = `
                    <span class="pill-icon" aria-hidden="true">${reason.icon}</span>
                    <span class="pill-index">${reason.number}</span>
                `;

                button.addEventListener('click', () => {
                    setCard(index);
                });

                pillsHost.appendChild(button);

                return button;
            });


            /* -------------------------------------------------
               Helpers
               ------------------------------------------------- */

            const normalize = (index) => ((index % total) + total) % total;

            const getOrbitRadius = () => {
                if (window.innerWidth <= 580) {
                    return 112;
                }
                if (window.innerWidth <= 820) {
                    return 130;
                }
                return 158;
            };


            /* -------------------------------------------------
               Position every pill around the circle
               ------------------------------------------------- */

            const positionPills = () => {
                const step = 360 / total;
                const radius = getOrbitRadius();

                pills.forEach((pill, index) => {
                    const angle = -90 + (index - active) * step;

                    pill.style.transform =
                        `translate(-50%, -50%) rotate(${angle}deg) translateY(-${radius}px) rotate(${-angle}deg)`;

                    const isActive = index === active;

                    pill.classList.toggle('active', isActive);
                    pill.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
            };


            /* -------------------------------------------------
               Render active card
               ------------------------------------------------- */

            const render = () => {
                const reason = reasons[active];

                number.textContent = reason.number;
                center.textContent = reason.number;

                const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                if (!reduceMotion) {
                    content.classList.add('is-changing');

                    window.clearTimeout(textTimer);

                    textTimer = window.setTimeout(() => {
                        title.textContent = reason.title;
                        description.textContent = reason.description;
                        content.classList.remove('is-changing');
                    }, 150);
                } else {
                    title.textContent = reason.title;
                    description.textContent = reason.description;
                    content.classList.remove('is-changing');
                }

                positionPills();
            };


            /* -------------------------------------------------
               Set active card
               ------------------------------------------------- */

            function setCard(index) {
                active = normalize(index);
                render();
            }


            /* -------------------------------------------------
               Reposition on resize (layout only, no content flash)
               ------------------------------------------------- */

            window.addEventListener('resize', positionPills);


            /* -------------------------------------------------
               Initial state
               ------------------------------------------------- */

            setCard(0);
        }
    }

})();
