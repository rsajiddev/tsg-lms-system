<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\View;
use App\Services\AuthService;
use App\Services\MailService;
use PDOException;
use RuntimeException;

/**
 * Course-bound enrolment checkout.
 *
 * The academy sells through a manual bank transfer, so an account has to exist before a payment
 * row can: payments.student_id is a NOT NULL key into users, and the receipt, the notification,
 * the access grant and the certificate are all quoted against that account. What must not happen
 * is the journey losing the course the visitor came for, which is what the old sign-in detour
 * did — the chosen course was dropped at /login and the student met an empty dashboard.
 *
 * So the course is remembered in the session here, and the account form and the email code share
 * one card. Verifying the code hands the student straight to the payment step for the course they
 * picked. Nothing about the authorisation model changes: a pending account still cannot sign in,
 * and access is still granted only by an administrator approving a payment.
 */
final class EnrolmentController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService(Database::connection(), new MailService());
    }

    /** GET /enrol/{slug} — remember the course and open the account step. */
    public function start(string $slug = ''): void
    {
        $course = $this->findCourse(trim((string) $slug));
        if (!$course) {
            $this->catalogue('That course is not open for enrollment right now. Pick a course from the catalogue and try again.');
            return;
        }

        // A student who is already signed in does not need a second account; they need the payment
        // step for this course. Sending them to the registration card is what produced the loop.
        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser) && ($sessionUser['role'] ?? '') === 'student') {
            $this->remember($course);
            $this->redirect('/payments/new?course_id=' . (int) $course['id']);
            return;
        }

        $this->remember($course);
        $this->checkout('details', $course);
    }

    /** POST /enrol — create the pending account and ask for the emailed code in the same card. */
    public function register(): void
    {
        $course = $this->courseFromSession();
        if (!$course) {
            $this->catalogue('Start again from the catalogue so we know which course you are enrolling in.');
            return;
        }
        if (!$this->csrfValid($course)) return;

        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!hash_equals($password, (string) ($_POST['password_confirmation'] ?? ''))) {
            $this->checkout('details', $course, ['full_name' => $name, 'email' => $email], 'Passwords do not match.');
            return;
        }

        try {
            $result = $this->auth->register($name, $email, $password);
        } catch (RuntimeException $exception) {
            $this->checkout('details', $course, ['full_name' => $name, 'email' => $email], $exception->getMessage());
            return;
        }

        if (!$result['created']) {
            // Deliberately does not say whether an account exists for that address.
            $this->checkout(
                'details',
                $course,
                ['full_name' => $name, 'email' => $email],
                'We could not open a new account with that address. If it already has an account, sign in below or request a code. Otherwise check the spelling and try again.'
            );
            return;
        }

        $this->checkout('otp', $course, ['full_name' => $name, 'email' => $email], $result['mail']
            ? null
            : 'The account is created, but the code could not be delivered to that address. Check the spelling, or use Resend in a moment.', $result['mail']
            ? 'A six-digit code is on its way to ' . $email . '.'
            : null);
    }

    /** POST /enrol/verify — confirm the code, then continue to the payment step for this course. */
    public function verify(): void
    {
        $course = $this->courseFromSession();
        if (!$course) {
            $this->catalogue('Start again from the catalogue so we know which course you are enrolling in.');
            return;
        }
        if (!$this->csrfValid($course)) return;

        $email = trim((string) ($_POST['email'] ?? ''));
        try {
            $this->auth->verifyEmail($email, (string) ($_POST['code'] ?? ''));
        } catch (RuntimeException $exception) {
            $this->checkout('otp', $course, ['email' => $email], $exception->getMessage());
            return;
        }

        // Receiving the code is the proof of ownership, so the account is opened here rather than
        // throwing the student back at /login. That extra sign-in was the last step of the old loop:
        // verified, landed on the login form, and the payment step felt like a fresh detour.
        $account = $this->activeStudent($email);
        if ($account === null) {
            $this->checkout('otp', $course, ['email' => $email], 'Your email is confirmed. Sign in to continue with the payment step.');
            return;
        }
        $this->signIn($account);

        $_SESSION['_flash'] = [
            'type' => 'success',
            'text' => 'Email verified for ' . $email . '. Add your payment details for '
                . (string) $course['title'] . ' to finish the enrollment.',
        ];
        $this->redirect('/payments/new?course_id=' . (int) $course['id']);
    }

    /** POST /enrol/verify/resend — issue a fresh code once the short resend wait has passed. */
    public function resend(): void
    {
        $course = $this->courseFromSession();
        if (!$course) {
            $this->catalogue('Start again from the catalogue so we know which course you are enrolling in.');
            return;
        }
        if (!$this->csrfValid($course)) return;

        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['full_name'] ?? ''));
        try {
            $sent = $this->auth->resendVerification($email);
            $message = $sent
                ? 'A new code is on its way to ' . $email . '.'
                : 'We could not send a code to that address. Check the spelling and try again.';
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        $this->checkout('otp', $course, ['full_name' => $name, 'email' => $email], $message);
    }

    /** POST /enrol/back — return to the details step without losing the name and address typed. */
    public function back(): void
    {
        $course = $this->courseFromSession();
        if (!$course) {
            $this->catalogue('Start again from the catalogue so we know which course you are enrolling in.');
            return;
        }
        if (!$this->csrfValid($course)) return;

        $this->checkout('details', $course, [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
        ], null, 'Correct the address and we will send the code again.');
    }

    /* -------------------------------------------------------------------------- rendering */

    /**
     * @param array<string, string> $values
     */
    private function checkout(string $state, array $course, array $values = [], ?string $error = null, ?string $message = null): void
    {
        $email = trim((string) ($values['email'] ?? ''));
        $resendSeconds = 0;
        if ($state === 'otp' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resendSeconds = $this->auth->resendSecondsRemaining($email);
        }
        View::render('enrol/checkout', [
            'title' => 'Enroll in ' . (string) $course['title'],
            'state' => $state,
            'course' => $course,
            'values' => $values,
            'resendSeconds' => $resendSeconds,
            'otpLifetime' => $this->auth->otpLifetimeSeconds(),
            'error' => $error,
            'message' => $message,
            'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
    }

    /* -------------------------------------------------------------------------- helpers */

    /**
     * A course is only enrollable while it is published and carries at least one priced option.
     * Checking the price here is what keeps the checkout from walking a student all the way to a
     * payment form that has nothing to sell.
     *
     * @return array<string, mixed>|null
     */
    private function findCourse(string $slug): ?array
    {
        if ($slug === '') return null;
        try {
            $statement = Database::connection()->prepare(
                "SELECT c.id, c.title, c.slug, c.category, c.duration_label, c.short_description,
                        COALESCE(p.currency, c.currency) AS currency, p.recorded_price, p.live_price
                 FROM courses c
                 JOIN (
                    SELECT course_id,
                           MAX(CASE WHEN option_code = 'recorded_only' AND price > 0 THEN price END) AS recorded_price,
                           MAX(CASE WHEN option_code = 'live_recorded' AND price > 0 THEN price END) AS live_price,
                           MAX(currency) AS currency
                    FROM course_pricing_options
                    GROUP BY course_id
                 ) p ON p.course_id = c.id
                 WHERE c.slug = ? AND c.visibility = 'published'
                   AND (p.recorded_price IS NOT NULL OR p.live_price IS NOT NULL)
                 LIMIT 1"
            );
            $statement->execute([$slug]);
            $course = $statement->fetch();
            return is_array($course) ? $course : null;
        } catch (PDOException $exception) {
            error_log('[ENROLMENT] Unable to resolve course slug: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * The slug is what gets stored, because it is the thing the visitor was shown and it can be
     * re-checked against the database on every step. The numeric id is kept alongside it only as a
     * convenience for the sign-in handoff, and the payment page authorises it independently.
     */
    private function remember(array $course): void
    {
        $_SESSION['enrol_course_slug'] = (string) $course['slug'];
        $_SESSION['enrol_course_id'] = (int) $course['id'];
        $_SESSION['enrol_course_title'] = (string) $course['title'];
    }

    /**
     * The session copy of the course is the only one any of these steps trust, and it is
     * re-checked against the database on every request: a course unpublished or repriced to zero
     * mid-checkout drops the student back to the catalogue instead of a dead payment form.
     *
     * @return array<string, mixed>|null
     */
    private function courseFromSession(): ?array
    {
        $slug = isset($_SESSION['enrol_course_slug']) ? trim((string) $_SESSION['enrol_course_slug']) : '';
        if ($slug === '') return null;
        $course = $this->findCourse($slug);
        if (!$course) return null;
        // Keep the stored id current in case the row was purged and re-imported between steps.
        $_SESSION['enrol_course_id'] = (int) $course['id'];
        return $course;
    }

    /**
     * Only an account that verification actually activated may be signed in from here: the role is
     * pinned to student, so this path can never hand an admin session to someone who just confirmed
     * an address, and an account that is still pending or was disabled keeps bouncing to /login.
     *
     * @return array{id:int,name:string,email:string,role:string}|null
     */
    private function activeStudent(string $email): ?array
    {
        try {
            $statement = Database::connection()->prepare(
                "SELECT id, full_name, email, role FROM users
                 WHERE email = ? AND role = 'student' AND status = 'active' AND email_verified_at IS NOT NULL LIMIT 1"
            );
            $statement->execute([strtolower(trim($email))]);
            $account = $statement->fetch();
        } catch (PDOException $exception) {
            error_log('[ENROLMENT] The verified account could not be loaded: ' . $exception->getMessage());
            return null;
        }
        if (!is_array($account)) return null;
        return [
            'id' => (int) $account['id'],
            'name' => (string) $account['full_name'],
            'email' => (string) $account['email'],
            'role' => (string) $account['role'],
        ];
    }

    /** Same session shape AuthController::login() builds, including the fixation defence. */
    private function signIn(array $account): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
        $_SESSION['user'] = $account;
    }

    private function catalogue(string $message): void
    {
        $_SESSION['_flash_error'] = $message;
        $this->redirect('/courses');
    }

    private function csrfValid(array $course): bool
    {
        if (Security::verifyCsrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) return true;
        http_response_code(419);
        $this->checkout('details', $course, [], 'This form expired. Fill it in again and try once more.');
        return false;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
