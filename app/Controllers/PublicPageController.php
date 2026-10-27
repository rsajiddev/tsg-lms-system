<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;
use PDOException;

final class PublicPageController
{
    public function about(): void
    {
        View::render('pages/about', ['title' => 'About Tech SG Academy']);
    }

    public function contact(): void
    {
        View::render('pages/contact', ['title' => 'Contact Tech SG Academy']);
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

        View::render('pages/courses', [
            'title' => 'Courses at Tech SG Academy',
            'courses' => $courses,
            'catalogueAvailable' => $catalogueAvailable,
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
