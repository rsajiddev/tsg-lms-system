/* Tech SG Academy – UI interactions
 *
 * IMPORTANT:
 * This file contains the existing site-wide interactions plus
 * the redesigned HOME / WHY TECH SG ACADEMY experience.
 *
 * PHP variables are NOT modified by this file.
 * The WHY section reads the already-rendered PHP $reasons cards
 * from the DOM and uses them as its data source.
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
            navToggle.setAttribute(
                'aria-expanded',
                String(open)
            );

            navToggle.setAttribute(
                'aria-label',
                open ? 'Close menu' : 'Open menu'
            );
        }
    };

    if (navToggle && mobileNav) {
        navToggle.addEventListener('click', () => {
            const isOpen = document.body.classList.contains('nav-open');
            setNav(!isOpen);
        });

        mobileNav.addEventListener('click', (event) => {
            if (
                event.target instanceof Element &&
                event.target.closest('a')
            ) {
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
        const target = Number(
            element.dataset.count || '0'
        );

        const suffix = element.dataset.suffix || '';

        const duration = 1300;
        const start = performance.now();

        const frame = (now) => {
            const progress = Math.min(
                (now - start) / duration,
                1
            );

            const eased =
                1 - Math.pow(1 - progress, 3);

            element.textContent =
                String(Math.round(target * eased)) +
                suffix;

            if (progress < 1) {
                requestAnimationFrame(frame);
            }
        };

        requestAnimationFrame(frame);
    };

    if (
        'IntersectionObserver' in window &&
        counters.length > 0
    ) {
        const counterObserver =
            new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) {
                            return;
                        }

                        runCounter(entry.target);

                        counterObserver.unobserve(
                            entry.target
                        );
                    });
                },
                {
                    threshold: 0.6
                }
            );

        counters.forEach((element) => {
            counterObserver.observe(element);
        });
    }


    /* =========================================================
       PASSWORD VISIBILITY TOGGLE
       ========================================================= */

    document
        .querySelectorAll('[data-toggle-password]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                const input =
                    button
                        .closest('.input-wrap')
                        ?.querySelector('input');

                if (!input) {
                    return;
                }

                const show =
                    input.type === 'password';

                input.type =
                    show ? 'text' : 'password';

                button.classList.toggle(
                    'is-on',
                    show
                );

                button.setAttribute(
                    'aria-pressed',
                    String(show)
                );

                button.setAttribute(
                    'aria-label',
                    show
                        ? 'Hide password'
                        : 'Show password'
                );
            });
        });


    /* =========================================================
       REVIEW FORM
       Student / Client placeholder switching
       ========================================================= */

    const reviewContext =
        document.getElementById('review-context');

    const reviewerTypeInputs =
        document.querySelectorAll(
            'input[name="reviewer_type"]'
        );

    if (
        reviewContext &&
        reviewerTypeInputs.length
    ) {
        const applyContext = () => {
            const checked =
                document.querySelector(
                    'input[name="reviewer_type"]:checked'
                );

            if (checked) {
                reviewContext.placeholder =
                    checked.dataset.contextPlaceholder ||
                    '';
            }
        };

        reviewerTypeInputs.forEach((input) => {
            input.addEventListener(
                'change',
                applyContext
            );
        });

        applyContext();
    }


    /* =========================================================
       HOME HERO CLEANUP
       =========================================================
       
       Existing PHP/HTML remains untouched.

       We only remove visual elements that were specifically
       requested to disappear from the redesigned hero:
       
       1. Old "Ideas into practice" small card
       2. About Academy button
       
       Abstract coding shapes remain.
       ========================================================= */

    const homeHero =
        document.querySelector('.home-hero');

    if (homeHero) {
        const oldArtCard =
            homeHero.querySelector('.art-card');

        if (oldArtCard) {
            oldArtCard.remove();
        }

        const aboutButton =
            homeHero.querySelector(
                '.hero-actions a[href$="/about"]'
            );

        if (aboutButton) {
            aboutButton.remove();
        }

        const heroArt =
            homeHero.querySelector('.hero-art');

        if (heroArt) {
            heroArt.style.overflow = 'hidden';
        }
    }


    /* =========================================================
       WHY TECH SG ACADEMY
       =========================================================

       IMPORTANT:

       Existing PHP cards are NOT deleted.

       Existing PHP structure:

       $reasons
       └── .why-grid
           ├── .why-card
           ├── .why-card
           ├── ...
       
       JavaScript reads those cards and creates the circular
       interactive presentation.

       No PHP variable is changed.

       Removed from LEFT side:
       - Previous button
       - Next button
       - Progress indicator bars

       Kept:
       - Mouse wheel
       - Circle rotation
       - Orbit cards
       - Orbit card click
       - Keyboard navigation
       - Smooth transitions
       - Reduced motion
       - Responsive layout
    ========================================================= */

    const whySection =
        document.querySelector(
            '.why-orbit-section'
        );

    const whySourceGrid =
        whySection?.querySelector(
            '.why-grid'
        );

    if (
        whySection &&
        whySourceGrid
    ) {
        const sourceCards =
            Array.from(
                whySourceGrid.querySelectorAll(
                    '.why-card'
                )
            );

        if (sourceCards.length) {

            /* -------------------------------------------------
               Convert existing PHP cards into JS data
               ------------------------------------------------- */

            const reasons =
                sourceCards.map(
                    (card, index) => ({
                        number:
                            String(index + 1)
                                .padStart(2, '0'),

                        title:
                            card
                                .querySelector('h3')
                                ?.textContent
                                .trim() || '',

                        description:
                            card
                                .querySelector('p')
                                ?.textContent
                                .trim() || ''
                    })
                );

            const total =
                reasons.length;


            /* -------------------------------------------------
               Abstract icons
               ------------------------------------------------- */

            const icons = [
                '<span class="pill-icon code">&lt;/&gt;</span>',

                '<span class="pill-icon braces">{ }</span>',

                '<span class="pill-icon">▤</span>',

                '<span class="pill-icon">✎</span>',

                '<span class="pill-icon">◇</span>',

                '<span class="pill-icon">◎</span>'
            ];


            /* -------------------------------------------------
               Create scroll stage
               ------------------------------------------------- */

            const stage =
                document.createElement('div');

            stage.className =
                'why-orbit-stage';

            whySection.parentNode.insertBefore(
                stage,
                whySection
            );

            stage.appendChild(
                whySection
            );


            /* -------------------------------------------------
               Create interactive UI
               ------------------------------------------------- */

            const ui =
                document.createElement('div');

            ui.className =
                'why-orbit-ui';

            ui.innerHTML = `
                <div class="why-orbit-copy">

                    <div class="why-orbit-eyebrow">
                        WHY TECH SG ACADEMY
                    </div>

                    <div class="why-orbit-number">
                        <small>CARD</small>

                        <strong
                            data-why-number
                        >
                            01
                        </strong>
                    </div>

                    <div
                        class="why-orbit-content"
                        aria-live="polite"
                    >
                        <h3 data-why-title></h3>

                        <p
                            data-why-description
                        ></p>
                    </div>

                </div>


                <div class="why-orbit-visual">

                    <div class="why-orbit-glow"></div>

                    <div class="why-orbit-circle">

                        <div
                            class="why-orbit-ring"
                        ></div>

                        <div
                            class="why-orbit-ring
                                   why-orbit-ring-inner"
                        ></div>

                        <div
                            class="why-orbit-center"
                            aria-live="polite"
                        >

                            <strong
                                data-why-center
                            >
                                01
                            </strong>

                            <span>
                                of ${String(total).padStart(2, '0')}
                            </span>

                        </div>

                        <div
                            class="why-orbit-pills"
                            data-why-pills
                            role="tablist"
                            aria-label="Academy benefits"
                        ></div>

                    </div>

                </div>
            `;


            whySection.appendChild(
                ui
            );


            /* -------------------------------------------------
               UI references
               ------------------------------------------------- */

            const title =
                ui.querySelector(
                    '[data-why-title]'
                );

            const description =
                ui.querySelector(
                    '[data-why-description]'
                );

            const number =
                ui.querySelector(
                    '[data-why-number]'
                );

            const center =
                ui.querySelector(
                    '[data-why-center]'
                );

            const pillsHost =
                ui.querySelector(
                    '[data-why-pills]'
                );

            const content =
                ui.querySelector(
                    '.why-orbit-content'
                );


            /* -------------------------------------------------
               Create circular orbit pills
               ------------------------------------------------- */

            const pills =
                reasons.map(
                    (reason, index) => {

                        const button =
                            document.createElement(
                                'button'
                            );

                        button.type =
                            'button';

                        button.className =
                            'why-orbit-pill';

                        button.dataset.index =
                            String(index);

                        button.setAttribute(
                            'role',
                            'tab'
                        );

                        button.setAttribute(
                            'aria-selected',
                            'false'
                        );

                        button.setAttribute(
                            'aria-label',
                            `Card ${reason.number}: ${reason.title}`
                        );

                        button.setAttribute(
                            'tabindex',
                            '-1'
                        );

                        button.innerHTML = `
                            ${icons[index % icons.length]}

                            <span
                                class="pill-index"
                            >
                                ${reason.number}
                            </span>
                        `;

                        button.addEventListener(
                            'click',
                            () => {
                                setCard(
                                    index,
                                    true
                                );
                            }
                        );

                        pillsHost.appendChild(
                            button
                        );

                        return button;
                    }
                );


            /* -------------------------------------------------
               State
               ------------------------------------------------- */

            let active = 0;

            let wheelLocked =
                false;

            let scrollFrame =
                false;

            let textTimer =
                0;


            /* -------------------------------------------------
               Helpers
               ------------------------------------------------- */

            const normalize =
                (index) => (
                    (index % total) + total
                ) % total;


            const getOrbitRadius =
                () => {

                    if (
                        window.innerWidth <= 580
                    ) {
                        return 118;
                    }

                    if (
                        window.innerWidth <= 820
                    ) {
                        return 145;
                    }

                    return 185;
                };


            /* -------------------------------------------------
               Render active card
               ------------------------------------------------- */

            const render =
                (animate = true) => {

                    const reason =
                        reasons[active];

                    number.textContent =
                        reason.number;

                    center.textContent =
                        reason.number;


                    const reduceMotion =
                        window.matchMedia(
                            '(prefers-reduced-motion: reduce)'
                        ).matches;


                    if (
                        animate &&
                        !reduceMotion
                    ) {
                        content.classList.add(
                            'is-changing'
                        );

                        window.clearTimeout(
                            textTimer
                        );

                        textTimer =
                            window.setTimeout(
                                () => {

                                    title.textContent =
                                        reason.title;

                                    description.textContent =
                                        reason.description;

                                    content.classList.remove(
                                        'is-changing'
                                    );

                                },
                                150
                            );

                    } else {

                        title.textContent =
                            reason.title;

                        description.textContent =
                            reason.description;

                        content.classList.remove(
                            'is-changing'
                        );
                    }


                    /* -------------------------------------------------
                       Rotate orbit
                       ------------------------------------------------- */

                    const step =
                        360 / total;

                    const radius =
                        getOrbitRadius();


                    pills.forEach(
                        (pill, index) => {

                            const angle =
                                -90 +
                                (index - active) *
                                step;


                            pill.style.transform =
                                `translate(-50%, -50%)
                                 rotate(${angle}deg)
                                 translateY(-${radius}px)
                                 rotate(${-angle}deg)`;


                            const isActive =
                                index === active;


                            pill.classList.toggle(
                                'active',
                                isActive
                            );


                            pill.setAttribute(
                                'aria-selected',
                                isActive
                                    ? 'true'
                                    : 'false'
                            );


                            pill.setAttribute(
                                'tabindex',
                                isActive
                                    ? '0'
                                    : '-1'
                            );
                        }
                    );
                };


            /* -------------------------------------------------
               Set active card
               ------------------------------------------------- */

            function setCard(
                index,
                animate = true
            ) {
                active =
                    normalize(index);

                render(
                    animate
                );
            }


            /* -------------------------------------------------
               Keyboard navigation
               ------------------------------------------------- */

            pills.forEach((pill) => {
                pill.addEventListener('keydown', (event) => {
                    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                        event.preventDefault();
                        setCard(active + 1, true);
                        window.setTimeout(() => { pills[active]?.focus(); }, 30);
                        return;
                    }
                    if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                        event.preventDefault();

                        setCard(active - 1, true);
                        window.setTimeout(() => { pills[active]?.focus(); }, 30);
                        return;
                    }
                    if (event.key === 'Home') {
                        event.preventDefault();
                        setCard(0, true);
                        pills[0]?.focus();
                        return;
                    }
                    if (event.key === 'End') {
                        event.preventDefault();
                        setCard(total - 1, true);
                        pills[total - 1]?.focus();
                    }
                }
                );
            }
            );


            /* -------------------------------------------------
               Mouse wheel interaction
               ------------------------------------------------- */
            whySection.addEventListener(
                'wheel',
                (event) => {
                    if (window.innerWidth <= 820) {
                        return;
                    }
                    if (Math.abs(event.deltaY) < 8) {
                        return;
                    }
                    event.preventDefault();
                    if (wheelLocked) {
                        return;
                    }
                    wheelLocked = true;
                    if (event.deltaY > 0) {
                        setCard(active + 1, true);
                    } else {
                        setCard(active - 1, true);
                    }
                    window.setTimeout(() => { wheelLocked = false; }, 650);
                }, { passive: false });


            /* -------------------------------------------------
               Compact sticky scroll stage
               
               IMPORTANT:
               Do NOT use total * 100vh.
               
               Six cards previously created an enormous
               600vh section.
               
               We now use a much shorter scroll distance.
               ------------------------------------------------- */
            const setStageHeight = () => {

                if (window.innerWidth <= 820) {
                    stage.style.height = 'auto';
                    return;
                }
                const headerOffset = 76;

                /*
                 * Smaller value = less empty space.
                 *
                 * 72px per card gives enough movement
                 * for six cards without pushing the
                 * Daily Motivation section too far down.
                 */
                const perCardScroll = 72;
                const minimumViewport = Math.max(window.innerHeight - headerOffset, 1);
                const compactHeight = Math.max(minimumViewport + ((total - 1) * perCardScroll), window.innerHeight);
                stage.style.height = `${compactHeight}px`;
            };


            /* -------------------------------------------------
               Page scroll -> active card
               ------------------------------------------------- */
            window.addEventListener('scroll', () => {

                if (scrollFrame || window.innerWidth <= 820) {
                    return;
                }
                scrollFrame = true;
                window.requestAnimationFrame(() => {
                    scrollFrame = false;
                    const rect = stage.getBoundingClientRect();
                    const max = Math.max(stage.offsetHeight - window.innerHeight, 1);
                    const travelled = Math.min(Math.max(-rect.top, 0), max);
                    const progress = travelled / max;
                    const target = Math.round(progress * (total - 1));

                    if (target !== active) {
                        setCard(target, true);
                    }
                }
                );
            },
                { passive: true }
            );

            /* -------------------------------------------------
               Resize
               ------------------------------------------------- */
            window.addEventListener('resize', () => {
                setStageHeight();
                render(false);
            }
            );


            /* -------------------------------------------------
               Reduced motion
               ------------------------------------------------- */
            const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

            const applyReducedMotion = () => {

                if (reducedMotionQuery.matches) {
                    ui.classList.add('why-reduced-motion');
                } else {
                    ui.classList.remove('why-reduced-motion');
                }
            };


            applyReducedMotion();


            if (reducedMotionQuery.addEventListener) {
                reducedMotionQuery.addEventListener('change', applyReducedMotion);
            }


            /* -------------------------------------------------
               Initial state
               ------------------------------------------------- */
            setStageHeight();
            setCard(0, false);
        }
    }

})();