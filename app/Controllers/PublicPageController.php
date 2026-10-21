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

    public function courses(): void
    {
        $courses = [];
        $catalogueAvailable = true;
        try {
            $statement = Database::connection()->query(
                "SELECT c.id, c.title, c.slug, c.category, c.level_label, c.short_description,
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
        } catch (PDOException|\RuntimeException $exception) {
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
}
