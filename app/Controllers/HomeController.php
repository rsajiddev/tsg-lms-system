<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Core\View;

final class HomeController
{
    public function index(): void
    {
        $this->home([], null, (($_GET['review'] ?? '') === 'submitted'));
    }

    public function storeReview(): void
    {
        $values = [
            'reviewer_type' => trim((string) ($_POST['reviewer_type'] ?? '')),
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'context_label' => trim((string) ($_POST['context_label'] ?? '')),
            'rating' => (string) ($_POST['rating'] ?? ''),
            'content' => trim((string) ($_POST['content'] ?? '')),
        ];
        if (!Security::verifyCsrf(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
            $this->home($values, 'This form expired. Refresh the page and try again.');
            return;
        }
        $error = $this->validateReview($values);
        if ($error !== null) {
            $this->home($values, $error);
            return;
        }
        try {
            $connection = Database::connection();
            $recent = $connection->prepare(
                'SELECT COUNT(*) FROM reviews WHERE ip_address = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)'
            );
            $recent->execute([$_SERVER['REMOTE_ADDR'] ?? '']);
            if ((int) $recent->fetchColumn() >= 5) {
                $this->home($values, 'You have submitted several reviews recently. Please try again later.');
                return;
            }
            $statement = $connection->prepare(
                "INSERT INTO reviews (reviewer_type, full_name, context_label, rating, content, status, ip_address)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?)"
            );
            $statement->execute([
                $values['reviewer_type'],
                $values['full_name'],
                $values['context_label'] !== '' ? $values['context_label'] : null,
                (int) $values['rating'],
                $values['content'],
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $this->redirect('/?review=submitted#reviews');
        } catch (\PDOException|\RuntimeException $exception) {
            error_log('[REVIEWS] Unable to save review: ' . $exception->getMessage());
            $this->home($values, 'Your review could not be saved right now. Please try again in a moment.');
        }
    }

    private function validateReview(array $values): ?string
    {
        if (!in_array($values['reviewer_type'], ['student', 'client'], true)) {
            return 'Please choose whether you are a student or a client.';
        }
        $length = mb_strlen($values['full_name']);
        if ($length < 2 || $length > 150) {
            return 'Please enter your name (2–150 characters).';
        }
        if (mb_strlen($values['context_label']) > 120) {
            return 'Course or project must be 120 characters or fewer.';
        }
        if (!ctype_digit($values['rating']) || (int) $values['rating'] < 1 || (int) $values['rating'] > 5) {
            return 'Please choose a rating between 1 and 5 stars.';
        }
        $length = mb_strlen($values['content']);
        if ($length < 15 || $length > 1500) {
            return 'Your review must be between 15 and 1500 characters.';
        }
        return null;
    }

    private function home(array $reviewValues = [], ?string $reviewMessage = null, bool $reviewSubmitted = false): void
    {
        $courses = [];
        try {
            $courses = Database::connection()->query(
                "SELECT c.id, c.title, c.short_description, c.duration_label, c.category,
                        p.recorded_price, p.live_price, COALESCE(p.currency, c.currency) AS currency
                 FROM courses c
                 LEFT JOIN (
                    SELECT course_id,
                           MAX(CASE WHEN option_code = 'recorded_only' THEN price END) AS recorded_price,
                           MAX(CASE WHEN option_code = 'live_recorded' THEN price END) AS live_price,
                           MAX(currency) AS currency
                    FROM course_pricing_options GROUP BY course_id
                 ) p ON p.course_id = c.id
                 WHERE c.visibility = 'published'
                 ORDER BY c.created_at DESC, c.id DESC LIMIT 3"
            )->fetchAll();
        } catch (\PDOException|\RuntimeException $exception) {
            error_log('[HOME] Unable to load published courses: ' . $exception->getMessage());
        }

        $reviews = [];
        try {
            $reviews = Database::connection()->query(
                "SELECT reviewer_type, full_name, context_label, rating, content
                 FROM reviews WHERE status = 'approved'
                 ORDER BY created_at DESC, id DESC LIMIT 6"
            )->fetchAll();
        } catch (\PDOException|\RuntimeException $exception) {
            error_log('[HOME] Unable to load reviews: ' . $exception->getMessage());
        }

        View::render('home/index', [
            'title' => 'Tech SG Academy | Practical IT Learning',
            'courses' => $courses,
            'reviews' => $reviews,
            'reviewValues' => $reviewValues,
            'reviewMessage' => $reviewMessage,
            'reviewSubmitted' => $reviewSubmitted,
            'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((require BASE_PATH . '/config/app.php')['url'], '/') . $path);
        exit;
    }
}
