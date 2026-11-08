<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Security;
use App\Core\View;
use App\Services\MailService;
use PDOException;

final class PublicPageController
{
    public function about(): void
    {
        View::render('pages/about', ['title' => 'About Tech SG Academy']);
    }

    public function contact(): void
    {
        // The submit handler redirects back here, so the form state travels in the session and is
        // read once - the same flash pattern the catalogue uses for a bounced enrolment.
        $values = is_array($_SESSION['_contact_old'] ?? null) ? $_SESSION['_contact_old'] : [];
        unset($_SESSION['_contact_old']);
        $sent = isset($_SESSION['_contact_sent']);
        unset($_SESSION['_contact_sent']);
        $error = is_string($_SESSION['_contact_error'] ?? null) ? $_SESSION['_contact_error'] : null;
        unset($_SESSION['_contact_error']);

        View::render('pages/contact', [
            'title' => 'Contact Tech SG Academy',
            'values' => $values,
            'sent' => $sent,
            'error' => $error,
            'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
    }

    /** Receives a public contact form submission and makes it available in the admin inbox. */
    public function submitContact(): void
    {
        $baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        $values = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'subject' => trim((string) ($_POST['subject'] ?? '')),
            'message' => trim((string) ($_POST['message'] ?? '')),
        ];
        $back = static function (?string $error, array $keep) use ($baseUrl): never {
            if ($error !== null) {
                $_SESSION['_contact_error'] = $error;
                $_SESSION['_contact_old'] = $keep;
            } else {
                $_SESSION['_contact_sent'] = true;
            }
            header('Location: ' . $baseUrl . '/contact');
            exit;
        };

        if (!Security::verifyCsrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
            $back('This form expired. Please refresh the page and try again.', $values);
        }
        $error = self::validateContact($values);
        if ($error !== null) {
            $back($error, $values);
        }

        try {
            $connection = Database::connection();
            $limiter = new RateLimiter($connection);
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $limiter->assertAllowed('contact', [$ip], 5, 3600);
            $limiter->record('contact', [$ip]);

            $save = $connection->prepare('INSERT INTO contact_messages (sender_name,sender_email,phone,subject,message,submitted_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
            $save->execute([$values['name'], $values['email'], $values['phone'], $values['subject'], $values['message']]);

            $delivered = (new MailService($connection))->sendContactReceived([
                'name' => $values['name'],
                'email' => $values['email'],
                'phone' => $values['phone'],
                'subject' => $values['subject'],
                'body' => $values['message'],
                'submitted_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$delivered) {
                error_log('[CONTACT] A contact enquiry was accepted but one email failed to deliver.');
            }
            $back(null, []);
        } catch (\Throwable $exception) {
            // A throttle or transport problem must not leak its detail to the visitor; the reason
            // belongs in the error log, and the sender only needs to know to try again shortly.
            error_log('[CONTACT] Unable to process a contact enquiry: ' . $exception->getMessage());
            $back('Your message could not be sent right now. Please try again in a little while.', $values);
        }
    }

    private static function validateContact(array $values): ?string
    {
        $length = mb_strlen($values['name']);
        if ($length < 2 || $length > 150) {
            return 'Please enter your name (2–150 characters).';
        }
        if (mb_strlen($values['email']) > 190 || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'Please enter a valid email address so we can reply to you.';
        }
        if (mb_strlen($values['phone']) > 40) {
            return 'Your phone number must be 40 characters or fewer.';
        }
        $length = mb_strlen($values['subject']);
        if ($length < 2 || $length > 150) {
            return 'Please add a short subject (2–150 characters).';
        }
        $length = mb_strlen($values['message']);
        if ($length < 10 || $length > 2000) {
            return 'Your message must be between 10 and 2000 characters.';
        }
        return null;
    }

    public function terms(): void
    {
        View::render('pages/terms', ['title' => 'Terms & Conditions']);
    }

    public function privacy(): void
    {
        View::render('pages/privacy', ['title' => 'Privacy Policy']);
    }

    public function refundPolicy(): void
    {
        View::render('pages/refund-policy', ['title' => 'Refund Policy']);
    }

    public function courses(): void
    {
        $courses = [];
        $catalogueAvailable = true;
        try {
            $imageColumn = $this->courseImageColumnAvailable() ? 'c.image_path' : 'NULL AS image_path';
            $statement = Database::connection()->query(
                "SELECT c.id, c.title, c.slug, c.category, $imageColumn, c.short_description,
                        c.description, c.duration_label, COALESCE(p.currency, c.currency) AS currency,
                        p.recorded_price, p.live_price
                 FROM courses c
                 LEFT JOIN (
                    SELECT course_id,
                           MAX(CASE WHEN option_code = 'recorded_only' THEN price END) AS recorded_price,
                           MAX(CASE WHEN option_code = 'live_recorded' THEN price END) AS live_price,
                           MAX(currency) AS currency
                    FROM course_pricing_options GROUP BY course_id
                 ) p ON p.course_id = c.id
                 WHERE c.visibility = 'published'
                 ORDER BY c.created_at DESC, c.id DESC"
            );
            $courses = $statement->fetchAll();
        } catch (PDOException | \RuntimeException $exception) {
            error_log('[CATALOGUE] Unable to load published courses: ' . $exception->getMessage());
            $catalogueAvailable = false;
        }

        // A bounced enrolment lands back here with its reason attached. The key has to be cleared on
        // this page like everywhere else that reads it, or the notice would reappear on the next
        // screen the visitor opens.
        $flashError = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_error']);

        View::render('pages/courses', [
            'title' => 'Courses at Tech SG Academy',
            'courses' => $courses,
            'catalogueAvailable' => $catalogueAvailable,
            'flashError' => is_string($flashError) ? $flashError : null,
            'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
    }

    public function courseDetails(string $slug): void
    {
        $baseUrl = rtrim((require BASE_PATH . '/config/app.php')['url'], '/');
        $db = Database::connection();
        $imageColumn = $this->courseImageColumnAvailable() ? 'c.image_path' : 'NULL AS image_path';
        $statement = $db->prepare(
            "SELECT c.id, c.title, c.slug, c.category, $imageColumn, c.short_description,
                    c.description, c.duration_label, c.level_label, c.currency,
                    COALESCE(p.currency, c.currency) AS pricing_currency,
                    p.recorded_price, p.live_price
             FROM courses c
             LEFT JOIN (
                SELECT course_id,
                       MAX(CASE WHEN option_code = 'recorded_only' THEN price END) AS recorded_price,
                       MAX(CASE WHEN option_code = 'live_recorded' THEN price END) AS live_price,
                       MAX(currency) AS currency
                FROM course_pricing_options GROUP BY course_id
             ) p ON p.course_id = c.id
             WHERE c.slug = ? AND c.visibility = 'published'
             LIMIT 1"
        );
        $statement->execute([$slug]);
        $course = $statement->fetch();
        if (!$course) {
            http_response_code(404);
            View::render('errors/404', ['title' => 'Course not found', 'baseUrl' => $baseUrl]);
            return;
        }

        $curriculumStatement = $db->prepare(
            "SELECT cm.id AS module_id, cm.title AS module_title,
                    l.id AS lesson_id, l.title AS lesson_title
             FROM course_modules cm
             LEFT JOIN lessons l ON l.module_id = cm.id AND l.status = 'published'
             WHERE cm.course_id = ?
             ORDER BY cm.sort_order ASC, cm.id ASC, l.sort_order ASC, l.id ASC"
        );
        $curriculumStatement->execute([(int) $course['id']]);
        $modulesById = [];
        foreach ($curriculumStatement->fetchAll() as $row) {
            $moduleId = (int) $row['module_id'];
            if (!isset($modulesById[$moduleId])) {
                $modulesById[$moduleId] = ['title' => (string) $row['module_title'], 'lessons' => []];
            }
            if ($row['lesson_id'] !== null && is_string($row['lesson_title'])) {
                $modulesById[$moduleId]['lessons'][] = $row['lesson_title'];
            }
        }

        View::render('pages/course-details', [
            'title' => (string) $course['title'] . ' | Tech SG Academy',
            'course' => $course,
            'modules' => array_values($modulesById),
            'baseUrl' => $baseUrl,
        ]);
    }

    private function courseImageColumnAvailable(): bool
    {
        try {
            Database::connection()->query('SELECT image_path FROM courses LIMIT 0');
            return true;
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1054) return false;
            throw $exception;
        }
    }
}
