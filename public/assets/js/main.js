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
       DASHBOARD LOGOUT CONFIRMATION
       Both the desktop account card and the mobile tab action use the same
       accessible confirmation surface, so a destructive session action never
       depends on the browser's plain confirm dialog.
       ========================================================= */

    const logoutForms = document.querySelectorAll('[data-logout-form]');
    const logoutConfirm = document.querySelector('[data-logout-confirm]');
    let pendingLogoutForm = null;
    let logoutTrigger = null;

    if (logoutForms.length && logoutConfirm) {
        const closeLogoutConfirm = () => {
            logoutConfirm.hidden = true;
            document.body.classList.remove('logout-confirm-open');
            // A swipe that reached the edge but whose dialog was cancelled must not leave the handle
            // parked at the right, where it could never be swiped again - reset it to the start.
            if (pendingLogoutForm) {
                const swipe = pendingLogoutForm.querySelector('[data-logout-swipe]');
                if (swipe) {
                    swipe.classList.remove('is-complete');
                    const knob = swipe.querySelector('.logout-swipe-handle');
                    if (knob) { knob.style.transition = ''; knob.style.transform = ''; delete knob.dataset.dragging; }
                    const bar = swipe.querySelector('.logout-swipe-fill');
                    if (bar) bar.style.width = '';
                }
            }
            if (logoutTrigger) logoutTrigger.focus({ preventScroll: true });
            pendingLogoutForm = null;
        };
        const openLogoutConfirm = (form, trigger) => {
            pendingLogoutForm = form;
            logoutTrigger = trigger;
            logoutConfirm.hidden = false;
            document.body.classList.add('logout-confirm-open');
            logoutConfirm.querySelector('[data-logout-cancel]')?.focus({ preventScroll: true });
        };

        logoutForms.forEach((form) => form.addEventListener('submit', (event) => {
            event.preventDefault();
            openLogoutConfirm(form, event.submitter || form.querySelector('button[type="submit"]'));
        }));
        logoutConfirm.querySelectorAll('[data-logout-cancel]').forEach((button) => button.addEventListener('click', closeLogoutConfirm));
        logoutConfirm.querySelector('[data-logout-confirm-submit]')?.addEventListener('click', () => {
            if (!pendingLogoutForm) return;
            const form = pendingLogoutForm;
            pendingLogoutForm = null;
            form.submit();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !logoutConfirm.hidden) closeLogoutConfirm();
        });
    }


    /* =========================================================
       SWIPE-TO-LOGOUT (desktop account card)
       The handle is a real submit button, so nothing here is required to log out: with no
       JavaScript the button submits, and a keyboard Enter/Space still submits (that click has
       event.detail === 0). This only layers the drag gesture on top - a completed drag to the
       right edge calls requestSubmit(), which the confirmation above intercepts to open the
       dialog. A plain pointer tap is ignored so the gesture stays deliberate, and a release short
       of the edge springs the handle back.
       ========================================================= */

    const swipeReduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('[data-logout-swipe]').forEach((swipe) => {
        const form = swipe.closest('form');
        const handle = swipe.querySelector('.logout-swipe-handle');
        const fill = swipe.querySelector('.logout-swipe-fill');
        if (!form || !handle) return;

        let dragging = false;
        let completed = false;
        let startX = 0;
        let maxX = 0;

        const paint = (x) => {
            handle.style.transform = x ? `translateX(${x}px)` : '';
            if (fill) fill.style.width = (x + handle.offsetWidth) + 'px';
        };

        handle.addEventListener('pointerdown', (event) => {
            if (event.button) return;
            dragging = true;
            completed = false;
            startX = event.clientX;
            const track = swipe.getBoundingClientRect();
            const knob = handle.getBoundingClientRect();
            maxX = Math.max(0, track.width - knob.width - 6);
            handle.dataset.dragging = '1';
            handle.style.transition = 'none';
            if (handle.setPointerCapture) { try { handle.setPointerCapture(event.pointerId); } catch (e) {} }
        });

        handle.addEventListener('pointermove', (event) => {
            if (!dragging) return;
            paint(Math.min(Math.max(event.clientX - startX, 0), maxX));
        });

        const endDrag = (event) => {
            if (!dragging) return;
            dragging = false;
            delete handle.dataset.dragging;
            if (handle.releasePointerCapture) { try { handle.releasePointerCapture(event.pointerId); } catch (e) {} }
            const dx = Math.min(Math.max(event.clientX - startX, 0), maxX);
            handle.style.transition = '';
            if (dx >= maxX - 6) {
                completed = true;
                paint(maxX);
                swipe.classList.add('is-complete');
                const fire = () => { if (form.requestSubmit) form.requestSubmit(); else form.submit(); };
                if (swipeReduce) fire(); else setTimeout(fire, 140);
            } else {
                paint(0);
                swipe.classList.remove('is-complete');
            }
        };
        handle.addEventListener('pointerup', endDrag);
        handle.addEventListener('pointercancel', () => {
            dragging = false;
            delete handle.dataset.dragging;
            handle.style.transition = '';
            paint(0);
            swipe.classList.remove('is-complete');
        });

        // Block a pointer tap that never swiped; let keyboard activation (detail 0) submit.
        handle.addEventListener('click', (event) => {
            if (event.detail !== 0 && !completed) event.preventDefault();
            completed = false;
        });
    });


    /* =========================================================
       AUTO-SUBMIT FILTER DROPDOWNS
       A period/range <select> inside a GET form reloads the page on change,
       so choosing an option behaves like the pill links it replaces.
       ========================================================= */

    document.querySelectorAll('select[data-auto-submit]').forEach((select) => {
        select.addEventListener('change', () => {
            if (select.form) select.form.submit();
        });
    });


    /* =========================================================
       SCROLL REVEAL ANIMATIONS
       ========================================================= */

    const revealables = document.querySelectorAll('[data-reveal]');

    const setImageState = (image) => {
        const frame = image.closest('[data-image-frame]');
        if (!frame) return;
        const status = frame.querySelector('.course-image-status');
        if (image.complete && image.naturalWidth > 0) {
            frame.classList.add('is-loaded');
            frame.classList.remove('is-image-error');
            if (status) status.textContent = '';
        } else {
            frame.classList.remove('is-loaded');
            frame.classList.add('is-image-error');
            if (status) status.textContent = 'Image unavailable';
        }
    };
    document.querySelectorAll('[data-course-image]').forEach((image) => {
        image.addEventListener('load', () => setImageState(image), { once: true });
        image.addEventListener('error', () => setImageState(image), { once: true });
        if (image.complete) setImageState(image);
    });

    const courseSearchForm = document.querySelector('[data-course-search-form]');
    const courseSearchInput = document.querySelector('[data-course-search-input]');
    const catalogueCards = [...document.querySelectorAll('.catalog-course-grid .course-card')];
    const courseSearchEmpty = document.querySelector('[data-course-search-empty]');

    if (courseSearchForm && courseSearchInput instanceof HTMLInputElement && catalogueCards.length) {
        const filterCourses = () => {
            const query = courseSearchInput.value.trim().toLocaleLowerCase();
            let visibleCount = 0;

            catalogueCards.forEach((card) => {
                const matches = card.textContent.toLocaleLowerCase().includes(query);
                card.hidden = !matches;
                if (matches) visibleCount += 1;
            });

            if (courseSearchEmpty) courseSearchEmpty.hidden = visibleCount > 0;
        };

        courseSearchInput.addEventListener('input', filterCourses);
        courseSearchForm.addEventListener('submit', (event) => {
            event.preventDefault();
            filterCourses();
            document.getElementById('course-list')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

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
       Works inside the auth .input-wrap and the admin .admin-input-wrap.
       ========================================================= */

    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.input-wrap, .admin-input-wrap')?.querySelector('input');

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
       CHAT PANELS
       The three-dot menu over a conversation and the emoji grid in the composer are the same shape:
       one button, one panel, closed by a click anywhere else or by Escape. Neither sends anything - a
       panel only ever opens something, and only the Send button submits.
       ========================================================= */

    const chatPanels = document.querySelectorAll('[data-chat-panel-toggle]');

    const closeChatPanels = () => {
        chatPanels.forEach((toggle) => {
            const panel = document.getElementById(toggle.getAttribute('data-chat-panel-toggle'));

            if (!panel) {
                return;
            }

            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        });
    };

    chatPanels.forEach((toggle) => {
        const panel = document.getElementById(toggle.getAttribute('data-chat-panel-toggle'));

        if (!panel) {
            return;
        }

        toggle.addEventListener('click', (event) => {
            event.stopPropagation();

            const wasOpen = !panel.hidden;
            closeChatPanels();
            panel.hidden = wasOpen;
            toggle.setAttribute('aria-expanded', String(!wasOpen));

            if (!wasOpen && panel.hasAttribute('data-chat-focus-first')) {
                const first = panel.querySelector('button, a[href], input, textarea');

                if (first) {
                    first.focus();
                }
            }
        });

        panel.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    });

    document.addEventListener('click', closeChatPanels);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeChatPanels();
        }
    });


    /* =========================================================
       CHAT EMOJI INSERT
       The glyph goes in where the caret is, not glued to the end, so a message can be fixed as it is
       written. Length is counted in UTF-16 units because that is what the caret positions mean.
       ========================================================= */

    document.querySelectorAll('[data-chat-composer] button[data-emoji]').forEach((button) => {
        button.addEventListener('click', () => {
            const composer = button.closest('[data-chat-composer]');
            const input = composer ? composer.querySelector('[data-chat-input]') : null;
            const glyph = button.getAttribute('data-emoji') || '';

            if (!input || !glyph) {
                return;
            }

            const from = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
            const to = typeof input.selectionEnd === 'number' ? input.selectionEnd : from;

            input.value = input.value.slice(0, from) + glyph + input.value.slice(to);
            input.focus();
            input.setSelectionRange(from + glyph.length, from + glyph.length);
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

    document.querySelectorAll('[data-otp-countdown]').forEach((row) => {
        const resend = row.querySelector('button');
        const timer = row.querySelector('.otp-resend-timer');
        let remaining = Math.max(0, Number.parseInt(row.dataset.otpCountdown || '0', 10));
        if (!resend || !timer) return;
        const update = () => {
            resend.disabled = remaining > 0;
            if (remaining <= 0) {
                timer.textContent = '';
                return;
            }
            const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
            const seconds = String(remaining % 60).padStart(2, '0');
            timer.textContent = `${minutes}:${seconds}`;
            remaining -= 1;
            window.setTimeout(update, 1000);
        };
        update();
    });

    /* Enrolment checkout. Keep its submit status in a fixed overlay while the server sends the OTP. */

    document.querySelectorAll('form[data-enrol-form]').forEach((form) => {
        const buttons = Array.from(form.querySelectorAll('button'));
        const primary = buttons.find((button) => button.type === 'submit' && !button.getAttribute('formaction'));
        const label = primary ? (primary.querySelector('.enrol-button-label') || primary) : null;
        const originalText = label ? label.textContent : '';
        if (buttons.length === 0) return;

        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            const action = (event.submitter && event.submitter.formAction) || form.action;
            const isResend = action.indexOf('/enrol/verify/resend') !== -1;
            const isBack = action.indexOf('/enrol/back') !== -1;
            buttons.forEach((button) => { button.disabled = true; });
            if (isBack) return;
            if (!label) return;
            if (isResend) {
                label.textContent = 'Sending a new code…';
            } else if (originalText.indexOf('Create account') === 0) {
                label.textContent = 'Creating account and sending code…';
            } else {
                label.textContent = 'Checking your code…';
            }
        });

        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            buttons.forEach((button) => { button.disabled = false; });
            if (label) label.textContent = originalText;
        });
    });

    document.querySelectorAll('form[data-submit-progress-form]').forEach((form) => {
        const overlay = document.querySelector('[data-submit-progress-overlay]');
        if (!overlay) return;
        const message = overlay.querySelector('[data-submit-progress-message]');
        let submitting = false;
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            if (submitting) {
                event.preventDefault();
                return;
            }
            const decision = event.submitter ? event.submitter.value : '';
            if (form.hasAttribute('data-progress-approval-only') && decision === 'rejected') {
                if (form.dataset.rejectionConfirmed !== 'true'
                    && !window.confirm('Are you sure you want to reject this payment? The student will be notified.')) {
                    event.preventDefault();
                    return;
                }
                delete form.dataset.rejectionConfirmed;
                overlay.setAttribute('aria-label', 'Rejecting payment');
            } else if (form.hasAttribute('data-progress-approval-only') && decision !== 'approved') {
                return;
            } else if (form.hasAttribute('data-progress-approval-only')) {
                overlay.setAttribute('aria-label', 'Approving payment');
            }
            submitting = true;
            if (message) {
                message.textContent = form.hasAttribute('data-progress-approval-only')
                    ? (decision === 'rejected'
                        ? 'Your payment rejection is being processed. Please wait.'
                        : 'Your payment is being approved. Please wait.')
                    : form.hasAttribute('data-contact-submit-form')
                        ? 'Your message is being sent. Please wait.'
                    : form.closest('.payment-form')
                        ? 'Your payment approval request is being sent to the admin. Please wait.'
                        : 'Please wait, OTP is being sent to your Gmail.';
            }
            overlay.hidden = false;
        });
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            submitting = false;
            overlay.hidden = true;
        });
    });

    document.querySelectorAll('.payment-decision-form').forEach((form) => {
        const note = form.querySelector('[name="review_note"]');
        const reject = form.querySelector('[name="decision"][value="rejected"]');
        if (!note || !reject) return;
        const validateRejectionNote = () => {
            note.setCustomValidity(note.value.trim() ? '' : 'Add a review note before rejecting this payment.');
        };
        reject.addEventListener('click', (event) => {
            if (!window.confirm('Are you sure you want to reject this payment? The student will be notified.')) {
                delete form.dataset.rejectionConfirmed;
                event.preventDefault();
                return;
            }
            form.dataset.rejectionConfirmed = 'true';
            validateRejectionNote();
        });
        note.addEventListener('input', () => note.setCustomValidity(''));
    });

    /* Attendance grace window. The deadline is stored server side and re-checked on submit, so    */
    /* this timer only mirrors it visually and stops a student clicking after the window elapsed.  */

    document.querySelectorAll('[data-attendance-countdown]').forEach((form) => {
        const button = form.querySelector('.attendance-mark-button');
        const timer = form.querySelector('.attendance-timer');
        if (!button || !timer) return;
        let remaining = Math.max(0, Number.parseInt(form.dataset.attendanceCountdown || '0', 10));
        const close = () => {
            button.disabled = true;
            timer.classList.add('is-closed');
            timer.textContent = 'Window closed · this day is recorded as absent';
        };
        const update = () => {
            if (remaining <= 0) {
                close();
                return;
            }
            const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
            const seconds = String(remaining % 60).padStart(2, '0');
            timer.textContent = `Time left ${minutes}:${seconds}`;
            remaining -= 1;
            window.setTimeout(update, 1000);
        };
        update();
    });

    /* Donut hover tooltip. The ring is a single conic-gradient, so the slice under the pointer is */
    /* recovered from the angle around the centre and matched to the server-supplied segment list. */

    document.querySelectorAll('[data-donut-tooltip]').forEach((wrap) => {
        const donut = wrap.querySelector('.donut');
        const tip = wrap.querySelector('.donut-tooltip');
        if (!donut || !tip) return;

        let segments = [];
        try { segments = JSON.parse(donut.dataset.donutSegments || '[]'); } catch (error) { segments = []; }
        const total = segments.reduce((sum, segment) => sum + (Number(segment.value) || 0), 0);
        if (total <= 0) return;

        const hide = () => {
            tip.classList.remove('is-visible');
            tip.setAttribute('aria-hidden', 'true');
        };
        const show = (clientX, clientY) => {
            const rect = donut.getBoundingClientRect();
            const dx = clientX - (rect.left + rect.width / 2);
            const dy = clientY - (rect.top + rect.height / 2);
            const radius = rect.width / 2;
            const distance = Math.sqrt(dx * dx + dy * dy);
            if (distance > radius || distance < radius * 0.5) { hide(); return; }
            let degrees = (Math.atan2(dx, -dy) * 180) / Math.PI;
            if (degrees < 0) degrees += 360;
            const point = degrees / 3.6;
            let cursor = 0;
            let active = segments[segments.length - 1];
            for (const segment of segments) {
                cursor += ((Number(segment.value) || 0) / total) * 100;
                if (point < cursor) { active = segment; break; }
            }
            tip.textContent = '';
            const swatch = document.createElement('i');
            swatch.style.background = active.color;
            const label = document.createTextNode(`${active.label} `);
            const value = document.createElement('strong');
            value.textContent = `${active.value} · ${active.pct}%`;
            tip.append(swatch, label, value);
            const wrapRect = wrap.getBoundingClientRect();
            tip.style.left = `${clientX - wrapRect.left}px`;
            tip.style.top = `${clientY - wrapRect.top}px`;
            tip.classList.add('is-visible');
            tip.setAttribute('aria-hidden', 'false');
        };

        donut.addEventListener('pointermove', (event) => show(event.clientX, event.clientY));
        donut.addEventListener('pointerleave', hide);
    });

    /* Lesson video player. The browser only ever reports where the playhead has reached, and the */
    /* server turns those reports into capped forward deltas, so nothing here can hand out         */
    /* completion credit by itself. Heartbeats stop while the tab is hidden, because an embed      */
    /* cannot expose its playhead and an unseen tab is not watching anything.                     */

    document.querySelectorAll('[data-lesson-player]').forEach((player) => {
        const endpoint = player.dataset.endpoint || '';
        const lessonId = player.dataset.lessonId || '0';
        if (!endpoint || lessonId === '0') return;

        const trackable = player.dataset.trackable === '1';
        const provider = player.dataset.provider || '';
        const video = player.querySelector('.lesson-video');
        const frame = player.querySelector('.lesson-iframe');
        if (trackable && !video) return;
        if (!trackable && !frame) return;

        const fill = player.querySelector('[data-watch-fill]');
        const track = player.querySelector('.lesson-watch-track');
        const text = player.querySelector('[data-watch-text]');
        const note = player.querySelector('[data-watch-note]');
        const unlockHint = document.querySelector('[data-unlock-hint]');
        const completeButton = document.querySelector('[data-complete-button]');
        const completeForm = completeButton ? completeButton.closest('form') : null;
        const completedLabel = document.querySelector('[data-completed-label]');
        const interval = Math.max(5, Number.parseInt(player.dataset.heartbeat || '10', 10)) * 1000;
        const duration = Math.max(0, Number.parseInt(player.dataset.duration || '0', 10));
        const heartbeatSeconds = Math.max(5, Number.parseInt(player.dataset.heartbeat || '10', 10));

        /* Content protection. Everything below resists and records, and none of it decides watch      */
        /* time: a blocked action is a line on the admin's desk, never a deduction from the student's   */
        /* progress, so a browser that fires an event at the wrong moment cannot cost anybody a grade.  */

        const viewEndpoint = player.dataset.viewEndpoint || '';
        const shell = player.querySelector('[data-video-shell]');
        const overlay = player.querySelector('[data-watermark-overlay]');
        const protectionNote = player.querySelector('[data-protection-note]');
        let noteTimer = 0;

        const announce = (message) => {
            if (!protectionNote) return;
            protectionNote.textContent = message;
            protectionNote.hidden = false;
            if (noteTimer) window.clearTimeout(noteTimer);
            noteTimer = window.setTimeout(() => { protectionNote.hidden = true; }, 6000);
        };

        const notify = (event, detail) => {
            if (!viewEndpoint) return;
            const body = new FormData();
            body.append('_csrf', player.dataset.csrf || '');
            body.append('lesson_id', lessonId);
            body.append('event', event);
            body.append('detail', String(detail || '').slice(0, 120));
            // Fire and forget. A protection report that cannot be sent must not hold up or break playback.
            window.fetch(viewEndpoint, { method: 'POST', credentials: 'same-origin', body, keepalive: true }).catch(() => {});
        };

        const paintMark = (selector, value) => {
            if (!overlay || !value) return;
            overlay.querySelectorAll(selector).forEach((node) => { node.textContent = value; });
        };

        const activeFullscreen = () => document.fullscreenElement || document.webkitFullscreenElement || null;

        const leaveFullscreen = () => {
            if (!activeFullscreen()) return Promise.resolve();
            try {
                if (typeof document.exitFullscreen === 'function') {
                    return document.exitFullscreen() || Promise.resolve();
                }
                if (typeof document.webkitExitFullscreen === 'function') document.webkitExitFullscreen();
            } catch (error) {
                // The browser was already on its way out; there is nothing here for the page to undo.
            }
            return Promise.resolve();
        };

        let position = Math.max(0, Number.parseInt(player.dataset.position || '0', 10));
        let activeSeconds = 0;
        // Embedded providers do not expose a native HTMLMediaElement. YouTube reports its
        // play/pause state through postMessage; until that state says PLAYING, no watch time is
        // allowed to accumulate. Other legacy embeds keep the existing visible-tab behaviour.
        let embedPlaying = provider !== 'youtube';
        let sending = false;
        let finished = player.dataset.completed === '1';
        let ticker = 0;

        const measured = () => (trackable && video ? Math.floor(video.currentTime) : position + activeSeconds);

        const markCompleted = () => {
            finished = true;
            if (ticker) window.clearInterval(ticker);
            if (completeForm) completeForm.hidden = true;
            if (completedLabel) completedLabel.hidden = false;
            if (note) note.textContent = 'Watch requirement met — this lesson is recorded as complete.';
        };

        const paint = (data) => {
            const ratio = Math.max(0, Math.min(100, Number(data.ratio) || 0));
            if (fill) fill.style.setProperty('--watch-width', `${ratio}%`);
            if (track) track.setAttribute('aria-valuenow', String(Math.round(ratio)));
            if (text) {
                text.textContent = data.duration
                    ? `${data.watched_label} / ${data.duration_label}`
                    : data.watched_label;
            }
            if (data.completed && !finished) markCompleted();
            // The rotating part of the account mark rides this response rather than a request of its
            // own, so a long recording cannot leave a stale trace code printed over the picture.
            if (data.watermark) {
                if (data.watermark.trace) {
                    player.dataset.trace = data.watermark.trace;
                    paintMark('[data-wm-trace]', data.watermark.trace);
                }
                paintMark('[data-wm-time]', data.watermark.time);
            }
            if (!data.completed && unlockHint && data.required) {
                const remaining = Math.max(0, Number(data.required) - Number(data.watched));
                unlockHint.textContent = remaining > 0
                    ? `Unlocks after ${Math.ceil(remaining / 60)} more minute${Math.ceil(remaining / 60) === 1 ? '' : 's'} of watch time`
                    : 'Ready — confirm below.';
            }
        };

        const report = (keepalive) => {
            if (finished || sending) return;
            const value = measured();
            if (value <= 0) return;
            if (trackable && video && (video.paused || video.ended)) return;
            const body = new FormData();
            body.append('_csrf', player.dataset.csrf || '');
            body.append('lesson_id', lessonId);
            body.append('position', String(value));
            sending = true;
            window.fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body,
                keepalive: Boolean(keepalive),
            }).then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    sending = false;
                    if (!data || !data.ok) return;
                    position = Math.max(position, value);
                    activeSeconds = 0;
                    paint(data);
                })
                .catch(() => { sending = false; });
        };

        const tick = () => {
            if (finished) return;
            if (document.visibilityState !== 'visible') return;
            if (!trackable && provider === 'youtube' && !embedPlaying) return;
            if (!trackable) activeSeconds += Math.round(interval / 1000);
            if (duration && position + activeSeconds > duration + 60) activeSeconds = Math.max(0, duration - position);
            report(false);
        };

        /* Fullscreen is the one interaction that can put a recording on a screen with nothing around */
        /* it, so the shell carrying the account mark - never the media element by itself - is what is */
        /* allowed to grow.                                                                           */
        const onFullscreenChange = () => {
            const open = activeFullscreen();
            if (!open || open === shell) return;
            if (open !== video && open !== frame) return;
            if (open === video && shell && typeof shell.requestFullscreen === 'function') {
                // The control bar asked for the video alone. Hand the request to the shell instead.
                leaveFullscreen().then(() => {
                    try {
                        const attempt = shell.requestFullscreen();
                        if (attempt && typeof attempt.catch === 'function') {
                            attempt.catch(() => announce('This recording could not be enlarged with the account mark kept beside it.'));
                        }
                    } catch (error) {
                        announce('Fullscreen could not be started for this recording.');
                    }
                });
                return;
            }
            // An embed filling the screen would show the provider's player and none of this page's mark.
            leaveFullscreen();
            announce('Fullscreen is switched off for this provider’s player, so the account mark cannot be left behind.');
            notify('fullscreen_blocked', 'Fullscreen attempted on the embedded player.');
        };
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);

        if (shell) {
            shell.addEventListener('contextmenu', (event) => {
                event.preventDefault();
                announce('Right-click is disabled on the player. The recording stays with the lesson.');
                notify('context_menu', 'Context menu suppressed over the player.');
            });
            shell.addEventListener('dragstart', (event) => {
                event.preventDefault();
                notify('download_blocked', 'The media was dragged out of the player.');
            });
        }

        // The save shortcut is refused only while the player itself holds focus, so saving any other
        // part of the site is left alone.
        player.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && !event.altKey && String(event.key).toLowerCase() === 's') {
                event.preventDefault();
                announce('Lesson recordings cannot be saved from the player.');
                notify('download_blocked', 'Save shortcut pressed inside the player.');
            }
        });

        if (video) {
            // disablepictureinpicture should stop this outright; the listener is for a browser that
            // ignores the attribute, so the mark cannot end up floating over a corner of the desktop.
            video.addEventListener('enterpictureinpicture', () => {
                try {
                    if (document.pictureInPictureElement === video && typeof document.exitPictureInPicture === 'function') {
                        document.exitPictureInPicture().catch(() => {});
                    }
                } catch (error) {
                    // Nothing to exit: this browser never let it start.
                }
                announce('Picture-in-picture is turned off for lesson recordings.');
                notify('pip_blocked', 'Picture-in-picture was entered and exited.');
            });

            // A jump forward of more than three heartbeats in one step is noted for the desk, and only
            // for the desk - the watch-time rule keeps counting forward play exactly as before. A student
            // returning to minute forty has not jumped anywhere, so the resume seek is not reported.
            let lastSeen = position;
            let started = false;
            video.addEventListener('playing', () => { started = true; }, { once: true });
            video.addEventListener('timeupdate', () => { lastSeen = Math.floor(video.currentTime); });
            video.addEventListener('seeking', () => {
                const where = Math.floor(video.currentTime);
                if (started && where - lastSeen > heartbeatSeconds * 3) {
                    notify('seek_ahead', `Jumped ${where - lastSeen} seconds forward in one step.`);
                }
                lastSeen = where;
            });
        }

        if (!trackable && provider === 'youtube' && frame) {
            const youtubeOrigins = new Set(['https://www.youtube-nocookie.com', 'https://www.youtube.com']);
            const youtubeMessage = (event) => {
                if (event.source !== frame.contentWindow || !youtubeOrigins.has(event.origin)) return;
                let payload;
                try { payload = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (error) { return; }
                if (!payload) return;
                const state = payload.event === 'onStateChange'
                    ? Number(payload.info)
                    : payload.event === 'infoDelivery' && payload.info
                        ? Number(payload.info.playerState)
                        : NaN;
                if (!Number.isFinite(state)) return;
                if (state === 1) {
                    embedPlaying = true;
                } else if (state === 0 || state === 2 || state === 3 || state === 5) {
                    embedPlaying = false;
                    activeSeconds = 0;
                }
            };
            window.addEventListener('message', youtubeMessage);
            const subscribe = () => {
                const post = (message) => frame.contentWindow?.postMessage(JSON.stringify(message), '*');
                post({ event: 'listening', id: 1, channel: 'lesson-player' });
                post({ event: 'command', func: 'addEventListener', args: ['onStateChange'], id: 1, channel: 'lesson-player' });
            };
            frame.addEventListener('load', subscribe);
            subscribe();
        }

        if (!finished) {
            ticker = window.setInterval(tick, interval);
            if (video) {
                // A returning student resumes where the server last saw the playhead.
                if (position > 5) {
                    video.addEventListener('loadedmetadata', () => {
                        if (Number.isFinite(video.duration) && position < video.duration - 5) {
                            video.currentTime = position;
                        }
                    }, { once: true });
                }
                video.addEventListener('seeked', () => { position = Math.max(position, Math.floor(video.currentTime)); });
                video.addEventListener('ended', () => report(false));
            }
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    activeSeconds = 0;
                } else {
                    report(false);
                }
            });
            window.addEventListener('pagehide', () => report(true));
        }
    });

    /* =========================================================
       BODY SCROLL LOCK — one owner for every overlay

       Both the mobile sidebar drawer and the admin course
       editor lock page scrolling. They share a single inline
       body overflow slot, so the value is always recomputed
       from the two states instead of being set and cleared
       independently. Closing a modal can therefore never wipe
       the drawer's lock, and closing the drawer can never
       unlock a modal that is still open.
       ========================================================= */

    let modalOpenCount = 0;
    let closeTopModal = () => {};

    const bodyScrollLock = () => {
        const drawerOpen = document.body.classList.contains('dashboard-menu-open');

        document.body.style.overflow = (modalOpenCount > 0 || drawerOpen) ? 'hidden' : '';
    };

    const dashboardToggle = document.querySelector('[data-dashboard-toggle]');
    const dashboardClose = document.querySelector('[data-dashboard-close]');
    const setDashboardMenu = (open) => {
        document.body.classList.toggle('dashboard-menu-open', open);
        if (dashboardToggle) dashboardToggle.setAttribute('aria-expanded', String(open));
        bodyScrollLock();
    };
    if (dashboardToggle) dashboardToggle.addEventListener('click', () => {
        setDashboardMenu(!document.body.classList.contains('dashboard-menu-open'));
    });
    if (dashboardClose) dashboardClose.addEventListener('click', () => setDashboardMenu(false));
    document.querySelectorAll('.dashboard-side-nav a').forEach((link) => {
        link.addEventListener('click', () => setDashboardMenu(false));
    });

    /* Keep the current page findable. The desktop sidebar and the mobile
       bottom strip are their own scroll boxes, and a fresh load always puts
       them back at the first item, so the highlighted link of a page further
       down the list can sit out of sight. Do not use scrollIntoView() here:
       it can scroll every ancestor, including the document, and its options
       do not support preventScroll. Move the navigation container only. */
    const activeNavLink = document.querySelector('.dashboard-side-nav a.active');
    if (activeNavLink) {
        const navScroller = activeNavLink.closest('.dashboard-side-nav');

        if (navScroller && window.matchMedia('(max-width: 902px)').matches) {
            const linkRect = activeNavLink.getBoundingClientRect();
            const navRect = navScroller.getBoundingClientRect();
            if (linkRect.left < navRect.left || linkRect.right > navRect.right) {
                navScroller.scrollLeft += (linkRect.left - navRect.left) - ((navRect.width - linkRect.width) / 2);
            }
        } else if (navScroller) {
            const linkTop = activeNavLink.offsetTop;
            const linkBottom = linkTop + activeNavLink.offsetHeight;
            const visibleTop = navScroller.scrollTop;
            const visibleBottom = visibleTop + navScroller.clientHeight;

            if (linkTop < visibleTop || linkBottom > visibleBottom) {
                const centeredTop = linkTop - ((navScroller.clientHeight - activeNavLink.offsetHeight) / 2);
                navScroller.scrollTop = Math.max(0, centeredTop);
            }
        }
    }

    const liveClock = document.querySelector('[data-live-clock]');
    const liveDate = document.querySelector('[data-live-date]');
    if (liveClock || liveDate) {
        const updateDashboardClock = () => {
            const now = new Date();
            if (liveClock) liveClock.textContent = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(now);
            if (liveDate) liveDate.textContent = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(now);
        };
        updateDashboardClock();
        window.setInterval(updateDashboardClock, 1000);
    }
    document.querySelectorAll('[data-task-filter]').forEach((filterButton) => {
        filterButton.addEventListener('click', () => {
            const mode = filterButton.getAttribute('data-task-filter');
            const card = filterButton.closest('.todo-card');
            const list = card?.querySelector('.todo-list');
            if (!list) return;
            card.querySelectorAll('[data-task-filter]').forEach((button) => button.classList.toggle('is-active', button === filterButton));
            list.querySelectorAll('[data-task-row]').forEach((row) => {
                const completed = row.getAttribute('data-task-done') === '1';
                row.hidden = mode === 'done' ? !completed : completed;
            });
        });
    });

    const searchWrap = document.querySelector('[data-dashboard-search-wrap]');
    const dashboardSearch = searchWrap?.querySelector('[data-dashboard-search]');
    const searchResults = searchWrap?.querySelector('.dashboard-search-results');
    if (searchWrap && dashboardSearch && searchResults) {
        let searchTimer = 0;
        let searchRequest = null;
        const closeSearch = () => {
            searchResults.hidden = true;
            dashboardSearch.setAttribute('aria-expanded', 'false');
        };
        const showSearchMessage = (message) => {
            searchResults.replaceChildren();
            const hint = document.createElement('p');
            hint.className = 'dashboard-search-hint';
            hint.textContent = message;
            searchResults.append(hint);
            searchResults.hidden = false;
            dashboardSearch.setAttribute('aria-expanded', 'true');
        };
        const searchAcrossDashboard = async () => {
            const query = dashboardSearch.value.trim();
            if (query.length < 2) {
                if (query) showSearchMessage('Type at least two characters to search your dashboard.');
                else closeSearch();
                return;
            }
            if (searchRequest) searchRequest.abort();
            searchRequest = new AbortController();
            showSearchMessage('Searching dashboard records…');
            try {
                const endpoint = searchWrap.dataset.searchUrl + '?q=' + encodeURIComponent(query);
                const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, signal: searchRequest.signal });
                if (!response.ok) throw new Error('Search is unavailable. Try again.');
                const payload = await response.json();
                if (query !== dashboardSearch.value.trim()) return;
                const items = Array.isArray(payload.results) ? payload.results : [];
                searchResults.replaceChildren();
                if (!items.length) {
                    const empty = document.createElement('p');
                    empty.className = 'dashboard-search-hint';
                    empty.textContent = 'No matching dashboard records found.';
                    searchResults.append(empty);
                } else {
                    items.forEach((item, index) => {
                        const link = document.createElement('a');
                        link.className = 'dashboard-search-result';
                        link.href = searchWrap.dataset.searchBase + item.path;
                        link.setAttribute('role', 'option');
                        link.setAttribute('aria-selected', 'false');
                        link.dataset.searchResultIndex = String(index);
                        const copy = document.createElement('span');
                        copy.className = 'dashboard-search-result-copy';
                        const title = document.createElement('strong');
                        title.textContent = item.label || 'Dashboard result';
                        const detail = document.createElement('small');
                        detail.textContent = item.detail || '';
                        copy.append(title, detail);
                        const type = document.createElement('span');
                        type.className = 'dashboard-search-result-type';
                        type.textContent = item.type || 'Result';
                        link.append(copy, type);
                        searchResults.append(link);
                    });
                }
                searchResults.hidden = false;
                dashboardSearch.setAttribute('aria-expanded', 'true');
            } catch (error) {
                if (error.name !== 'AbortError') showSearchMessage('Search is unavailable. Try again.');
            }
        };
        dashboardSearch.addEventListener('focus', () => {
            if (dashboardSearch.value.trim().length < 2) showSearchMessage('Search students, courses, assignments, payments and more.');
        });
        dashboardSearch.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(searchAcrossDashboard, 180);
        });
        dashboardSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeSearch();
            if (event.key === 'Enter') {
                const first = searchResults.querySelector('.dashboard-search-result');
                if (first) { event.preventDefault(); window.location.assign(first.href); }
            }
        });
        document.addEventListener('click', (event) => {
            if (!searchWrap.contains(event.target)) closeSearch();
        });
    }


    /* =========================================================
       ADMIN MODAL — course editor overlay

       The overlay also opens without JavaScript through a CSS
       :target fallback. Once this script runs it takes over:
       the address bar stays clean, Escape closes the dialog and
       the page behind it stops scrolling.
       ========================================================= */

    const adminModalTriggers = document.querySelectorAll('[data-admin-modal-open]');

    /* A confirmation dialog is only worth having if it repeats what is about to change, so
       every [data-confirm-value] node inside one is filled from the field whose id it names the
       moment the dialog opens. The markup ships the saved value, so the text is never blank
       and the dialog still reads correctly with JavaScript switched off. */
    const syncConfirmValues = (modal) => {
        modal.querySelectorAll('[data-confirm-value]').forEach((node) => {
            const key = node.dataset.confirmValue;
            const field = document.getElementById(key) || document.querySelector('[name="' + key + '"]');

            if (!field) {
                return;
            }

            if (field.type === 'checkbox') {
                node.textContent = field.checked ? 'Yes' : 'No';
                return;
            }

            node.textContent = field.value.trim() === '' ? '—' : field.value.trim();
        });
    };

    if (adminModalTriggers.length) {
        document.documentElement.classList.add('admin-modal-js');

        const openAdminModal = (modal) => {
            if (modal.classList.contains('is-open')) {
                return;
            }

            modal.classList.add('is-open');
            document.body.classList.add('admin-modal-open');
            modalOpenCount += 1;
            bodyScrollLock();
            syncConfirmValues(modal);

            const firstField = modal.querySelector(
                'input:not([type="hidden"]):not([type="radio"]), select, textarea'
            );

            if (firstField) {
                firstField.focus({ preventScroll: true });
            }
        };

        const closeAdminModal = (modal) => {
            if (!modal.classList.contains('is-open')) {
                return;
            }

            modal.classList.remove('is-open');
            modalOpenCount = Math.max(0, modalOpenCount - 1);

            if (modalOpenCount === 0) {
                document.body.classList.remove('admin-modal-open');
            }

            /* Recompute instead of clearing: an open drawer keeps its lock. */
            bodyScrollLock();

            const trigger = document.querySelector('[data-admin-modal-open="' + modal.id + '"]');

            if (trigger) {
                trigger.focus({ preventScroll: true });
            }
        };

        closeTopModal = () => {
            const openModal = document.querySelector('.admin-modal.is-open');

            if (openModal) {
                closeAdminModal(openModal);
            }
        };

        /* Every open and close is driven from script. The markup still carries
           the #target href as the no-JavaScript fallback, but following it as a
           link would leave the fragment in the address bar, and a fragment
           survives a refresh: the browser then scrolls to it again by itself and
           the dialog reopens on a page the user never asked to reload. */
        adminModalTriggers.forEach((trigger) => {
            trigger.addEventListener('click', (event) => {
                const modal = document.getElementById(trigger.dataset.adminModalOpen);

                if (!modal) {
                    return;
                }

                event.preventDefault();
                openAdminModal(modal);
            });
        });

        document.querySelectorAll('[data-admin-modal]').forEach((modal) => {
            modal.querySelectorAll('[data-admin-modal-close]').forEach((control) => {
                control.addEventListener('click', (event) => {
                    event.preventDefault();
                    closeAdminModal(modal);
                });
            });
        });

        const enrolOtpModal = document.querySelector('[data-enrol-otp-auto-open]');
        if (enrolOtpModal) {
            openAdminModal(enrolOtpModal);
        }

        /* Deep link support: /admin/courses#course-edit-3 opens the editor. The
           fragment is spent once it has done its job, so the next refresh starts
           at the top of the page with the dialog closed. */
        const deepLinkId = window.location.hash.replace('#', '');

        if (deepLinkId) {
            const deepLinkModal = document.getElementById(deepLinkId);

            if (deepLinkModal && deepLinkModal.matches('[data-admin-modal]')) {
                openAdminModal(deepLinkModal);
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        }
    }

    /* =========================================================
       LESSON EDITOR DEEP LINK — /admin/courses#lesson-7 opens
       the matching accordion so the lesson library can send an
       admin straight to the editor it came from.
       ========================================================= */

    /* The fragment is a one-time instruction, not a permanent state: left in
       the address bar it makes every later refresh scroll the page on its own
       and re-open the accordion, so it is dropped once the editor is showing. */
    if (window.location.hash.startsWith('#lesson-')) {
        const deepLinkLesson = document.getElementById(window.location.hash.slice(1));

        if (deepLinkLesson && deepLinkLesson.matches('.lesson-editor')) {
            deepLinkLesson.open = true;
            /* The browser scrolled before the panel grew, so bring it back into view. */
            window.requestAnimationFrame(() => {
                deepLinkLesson.scrollIntoView({ block: 'center', behavior: 'smooth' });
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            });
        }
    }

    /* =========================================================
       ESCAPE — closes only the top-most overlay layer

       The admin modal sits above the sidebar drawer in the
       stacking order, so it is dismissed first. The drawer is
       only closed when no modal is left open.
       ========================================================= */

    const notificationAudience = document.querySelector('[data-notification-audience]');
    const recipientPicker = document.querySelector('[data-notification-recipient-picker]');
    if (notificationAudience && recipientPicker) {
        const syncRecipientPicker = () => {
            recipientPicker.hidden = notificationAudience.value !== 'selected_students';
        };
        notificationAudience.addEventListener('change', syncRecipientPicker);
        syncRecipientPicker();
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        if (modalOpenCount > 0) {
            closeTopModal();
            return;
        }

        if (document.body.classList.contains('dashboard-menu-open')) {
            setDashboardMenu(false);
        }
    });

})();

(() => {
    /**
     * The confirmation overlay rendered by app/Views/components/flash-toast.php.
     *
     * PHP prints the card and CSS keeps it hidden from a scripted page, so this script is what
     * floats it: it opens the layer, closes each card on its own timer, and lets the reader
     * dismiss it early with the button, the dim background or Escape. An error is held on screen
     * longer than a success because it usually needs a correction, not just an acknowledgement.
     */
    const layer = document.querySelector('[data-flash-layer]');
    if (!layer) return;

    const cards = Array.from(layer.querySelectorAll('[data-flash-card]'));
    if (cards.length === 0) return;

    const timers = new Map();
    const LEAVE_MS = 260;

    const dismiss = card => {
        const timer = timers.get(card);
        if (timer) {
            clearTimeout(timer);
            timers.delete(card);
        }
        if (!card.isConnected || card.classList.contains('is-leaving')) return;
        card.classList.add('is-leaving');
        window.setTimeout(() => {
            card.remove();
            if (!layer.querySelector('[data-flash-card]')) layer.remove();
        }, LEAVE_MS);
    };

    layer.classList.add('is-open');
    cards.forEach((card, index) => {
        const stated = Number.parseInt(card.getAttribute('data-flash-stay') || '', 10);
        const stays = Number.isFinite(stated) && stated > 0
            ? stated
            : (card.classList.contains('is-error') ? 5200 : 4000);
        // Stacked confirmations leave one after the other instead of all at once, so each one gets
        // its moment of being read.
        timers.set(card, window.setTimeout(() => dismiss(card), stays + index * 400));
    });

    layer.addEventListener('click', event => {
        // Only the button and the dim background close the card; a click that lands on the text,
        // the icon or the card's own padding must not swallow a confirmation.
        if (event.target.hasAttribute('data-flash-backdrop') || event.target.closest('[data-flash-close]')) {
            cards.forEach(dismiss);
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !layer.isConnected) return;
        // Escape belongs to the topmost modal when one is open, so the card leaves it alone.
        if (document.body.classList.contains('admin-modal-open')) return;
        cards.forEach(dismiss);
    });
})();
