<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;

final class HomeController
{
    public function index(): void
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

        View::render('home/index', [
            'title' => 'Tech SG Academy | Practical IT Learning',
            'courses' => $courses,
            'baseUrl' => rtrim((require BASE_PATH . '/config/app.php')['url'], '/'),
        ]);
    }
}
