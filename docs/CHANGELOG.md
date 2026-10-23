# Changelog

All notable UI and feature changes made to the TSG Academy LMS public site, newest first.

## Unreleased — 2026-10-23 (home hero + WHY orbit bug fixes after redesign)

Fixed the bugs introduced by the home-page hero / WHY-section redesign, without adding new behaviour.

- **Home hero — restored the TSG card**: `main.js` was deleting the `.art-card` (the simple TSG card on
  the hero's right). The removal block is gone, so the card and the rotating orbit ring render again.
  The floating code/coding chips were lightly refreshed (rounded glass pills) and `overflow` on the
  hero art was relaxed so the shapes are no longer clipped.
- **WHY orbit — no more duplicate heading**: the section already has a `.section-heading`; the JS orbit
  copy no longer injects a second "WHY TECH SG ACADEMY" eyebrow.
- **WHY orbit — click only**: removed the sticky full-viewport scroll "stage", the mouse-wheel paging,
  the scroll-driven card switching and the arrow-key paging. The page now scrolls normally; the left
  content only changes when an orbit pill is clicked. Resize only repositions the pills (no content flash).
- **WHY orbit — consistent spacing**: the orbit section now uses the same vertical rhythm as the other
  home sections (`54px 0 8px`); the forced `100vh`/sticky height and the reduced Daily-Motivation padding
  override were removed, so gaps above/below match the rest of the site.
- **Mobile header**: brand stays on the left and the hamburger on the right in a single row (the earlier
  stacked layout at ≤580px is fixed). Rules are scoped to `.topbar` so the footer brand is unaffected.
- **Dead code cleanup**: collapsed the two conflicting CSS override blocks ("HOME REDESIGN" + "FINAL
  REFACTOR") into one, removing all now-unused `why-orbit-stage / eyebrow / progress / controls / hint`
  rules. No PHP variables or markup structure were changed.

### Follow-up polish (same day)
- **Reviews — mobile overlap fixed**: the left `.review-intro` was `position: sticky`, so once the
  section stacked into a single column on mobile it scrolled over the review form. It is now
  `position: static` at ≤980px; the sticky side-by-side behaviour is kept only on desktop.
- **WHY orbit — tighter spacing**: reduced the section top padding and the orbit row top margin, and
  shrank the orbit circle (460→400px visual, 440→380px circle) so the section no longer leaves large
  empty gaps above/below between the neighbouring sections.
- **WHY orbit — content fills the space**: enlarged the left card heading and paragraph (bigger font,
  wider max-width, taller line-height) so the copy balances the circle instead of floating in whitespace.
- **WHY orbit — emoji icons**: each orbit pill now shows the same emoji as its source reason card
  (read from `.why-icon`) instead of the abstract `</>` / `{ }` shapes, so the benefit is recognisable.
  The unused mono `.pill-icon.code/.braces` styles were removed.
- **WHY orbit — heading removed, copy fills the space**: the section's `.section-heading`
  ("WHY TECH SG ACADEMY" + "Learn skills the industry actually uses") was the main source of empty
  space and is removed (the section keeps an `aria-label` for accessibility). The left copy now
  top-aligns to the orbit row (`align-items/align-self: start`) so it starts where the heading ended.
  To fill the space below it, the six `$reasons` descriptions were rewritten as longer, professional
  copy (same emojis and titles) rather than by inflating the CSS font size or line-height, which is left
  at its normal 16px / 1.45. Section top/bottom padding tightened (30px/4px, 26px/4px on mobile) so the
  gap to the neighbouring sections matches the rest of the page.

### Hero atmosphere + FAQ motion (same day)
- **FAQ — smooth open/close**: each `.faq-answer` is wrapped at runtime in `.faq-answer-wrap`
  (`overflow: hidden`) and animated with the Web Animations API — height 0 ⇄ natural height plus a
  short opacity fade over 300ms `cubic-bezier(.4, 0, .2, 1)` in both directions. The summary click is
  intercepted only to drive that animation, so `<details>` still works natively without JS and the
  plus icon keeps its 45° turn. The old one-shot `faq-open` keyframe was removed.
- **Hero background decor**: added a decorative `.hero-decor` layer inside the home hero only
  (`aria-hidden`, `pointer-events: none`, clipped to the section): two soft drifting blobs, two
  twinkling sparkles, a slowly rotating `+` and `×`, a floating ring and a pulsing dot. All motion is
  CSS-only and stops under `prefers-reduced-motion`.
- **Hero mini pills replaced**: the commented-out code/coding chips were removed from the markup and
  their dead `.art-floating-shape` / `shape-code|book|brackets|pen|cert` / `.art-card-small` /
  `.art-spark` styles deleted. In their place sit three floating circular glass badges around the card
  (graduation cap, verified check, sparkle), each on its own gentle float cycle.
- **TSG card detail**: added a green verified seal on the card corner, a pulsing "Live + Recorded"
  status chip, a subtitle label, a course-progress bar and a slow diagonal sheen sweep, with the card
  layered above the orbit ring and badges.
- **TSG card — skeleton bars removed**: the three grey placeholder lines (`.art-lines`) looked like
  content still loading, which reads as broken UI. Their markup and CSS are deleted; in their place are
  three course-fact rows (Recorded lessons 24 · Hands-on projects 06 · Quizzes & tasks 12), each an
  icon chip plus an accent value on a soft gradient pill, entering once with a staggered fade
  (one-shot, and disabled under `prefers-reduced-motion`).

## Unreleased — 2026-10-22 (reviews, legal pages, auth redesign v2, header/footer polish)

### Role-specific dashboard overview
- Replaced the shared dashboard placeholder with database-backed Student and Admin overviews.
- Student overview is scoped to the signed-in account and shows its active course grants, lesson
  progress, own payments, released course quizzes, quiz history, and unread recipient notifications.
- Admin overview shows live student/course/payment/quiz counts and recent payment and quiz activity.
- Added responsive cards, status labels, progress indicators, and empty states; later phases will
  add course editing, payment decisions, access grants, and quiz administration.
- Login and registration guidance now makes the required email-verification step clearer. Local
  XAMPP OTP delivery still requires mail sender/transport configuration.
- Added authenticated SMTP delivery for verification and password-reset OTPs with TLS/SSL,
  credential-backed authentication, delivery-result feedback, and secret-safe failure logs.
- A successful new student registration also sends an alert to `MAIL_ADMIN_NOTIFICATION_ADDRESS`; failure of
  this admin alert does not block account verification or expose SMTP secrets.
- Added `.env.example` with database and SMTP configuration keys; real OTP delivery requires
  replacing its SMTP placeholders with approved provider settings in the private `.env`.
- Removed the hard-coded home-page review fallback; only approved database reviews render.

### Database-backed reviews (home page)
- The consolidated `database/migrations/001_initial_schema.sql` creates the `reviews` table:
  `reviewer_type` (student/client), `full_name`, `context_label`, `rating` (1–5, CHECK constraint),
  `content`, `status` (pending/approved/rejected, default `pending`), `ip_address`, `created_at`.
  Seeded with the 3 real approved client testimonials already published on the site.
- `HomeController::index()` loads up to 6 approved reviews (newest first); when there are no
  approved database reviews, the page shows an empty state instead of substitute review data.
- New route `POST /reviews` → `HomeController::storeReview()`:
  - CSRF protected (`Security::verifyCsrf`), same pattern as auth forms.
  - Server-side validation: reviewer type, name 2–150 chars, optional context ≤120 chars,
    rating 1–5, review content 15–1500 chars.
  - Flood guard: max 5 submissions per IP per 10 minutes.
  - Inserts as `pending` (moderation first), then redirects to `/?review=submitted#reviews`
    which shows a green success banner. Errors re-render the page with the message and
    previously entered values preserved.
  - Approving a review is a one-line SQL update: `UPDATE reviews SET status='approved' WHERE id=…;`
- Review submission form below the cards: student/client segmented toggle (swaps the context
  field placeholder via JS), name, optional course/project, interactive star rating picker
  (CSS-only radio trick), review textarea, submit button.
- Section heading changed to a dual point of view: eyebrow "STUDENT & CLIENT REVIEWS",
  heading "What our students and clients say".
- Review cards render from the database with dynamic stars per rating, auto-generated initials
  avatars, and a Client (blue) / Student (green) type pill.
- Layout: review cards run in a seamless right-to-left infinite marquee (pauses on hover,
  edge fade masks, static scrollable row for reduced-motion users). Below it, a two-column
  row: unique "Why your review matters" text on the left (sticky-scrolls with the form like
  the FAQ heading, `top: 106px`), the form card on the right.

### Login/register page redesign (v2 — image removed)
- Removed the Cloudinary-hosted photo and every Cloudinary reference from the codebase.
- New split layout: white form side (centered heading, plain rounded-rect inputs, password
  eye toggles, Remember me + Forgot password row, full-width Log in button) and a CSS-only
  indigo panel with decorative circles, headline copy and a pure-CSS "Student dashboard"
  mockup card (stats, chart bars, live-class note).
- Mobile (≤900px): indigo panel stacks on top with the brand, form below.
- Dark-appearance toggle and its styles/JS removed (not part of the new reference design).
- Form foot now shows "Copyright © YYYY Tech SG Studio (SMC-Pvt.) Ltd." + Privacy Policy link.
- All form field names, CSRF fields, actions and validation attributes unchanged — UI only.

### Legal pages
- New routes `GET /terms`, `GET /privacy`, `GET /refund-policy` with matching
  `PublicPageController` methods and placeholder views (`app/Views/pages/terms.php`,
  `privacy.php`, `refund-policy.php`) — real content to be supplied later.
- Legal links added to the footer bottom bar; Privacy Policy also linked from the auth page foot.

### Header
- New dark utility strip above the navbar: "An initiative of Tech SG Studio (SMC-PVT.) LTD."
  (company name links to https://techsgstudio.com) + email and office hours on the right
  (hidden below 760px).
- Nav links de-bolded (font-weight 500); hover underline removed.
- Active page indicator: a small blue bar under the current page's nav link, just above the
  navbar's bottom border (works in desktop and mobile menus).
- "Get started" renamed to "Register"; all hover effects on it removed; background set to
  `#2463EB`.

### Footer
- Social icons added to the brand column (Facebook, Instagram, LinkedIn, WhatsApp, YouTube)
  with brand-tinted backgrounds that fill with the solid brand color on hover.
- Brand tagline text changed from gray to white for contrast.
- Existing footer links unchanged.

### Review/form buttons
- Submit review button: native browser border removed, background `#2463EB`,
  hover darkens to `#1D4ED8`.

## 55f05b7 — 2026-10-22 — Redesign auth pages and enrich public UI with real Tech SG content
- First auth split design with Cloudinary image panel, floating glass cards and dark toggle
  (superseded by the v2 redesign above).
- Responsive hamburger menu and mobile navigation.
- "Get started" hover contrast fix.
- Home page: trust chips, animated stats band, why-cards, motivational cards, testimonials,
  sticky-heading FAQ accordion, CTA banner.
- About/Contact/Courses enriched with real Tech SG Studio data (SECP registration, contact
  channels, process, most-popular course badge).
- Added `public/assets/js/main.js` (nav toggle, scroll reveal, counters, password toggles).

## 16c57b2 — 2026-10-21 — Add public pages and polish LMS UI
- Restored LMS styling, navigation and course catalogue; migrated about/contact/courses/home
  pages from the legacy static site into the MVC app.
