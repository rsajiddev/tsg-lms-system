-- Public course catalogue fields and normalized learning-mode prices.
-- Apply once after 002_auth_rate_limits.sql.
ALTER TABLE courses
    MODIFY created_by BIGINT UNSIGNED NULL,
    ADD COLUMN category VARCHAR(100) NULL AFTER slug,
    ADD COLUMN level_label VARCHAR(50) NULL AFTER duration_label;

CREATE TABLE course_pricing_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NOT NULL,
    option_code ENUM('recorded_only', 'live_recorded') NOT NULL,
    display_label VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PKR',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_course_pricing_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_course_pricing_option (course_id, option_code),
    INDEX idx_course_pricing_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Academy's three courses and prices transcribed from the supplied official-site screenshot.
-- `price` retains the recorded-only base price for compatibility with the initial schema.
INSERT INTO courses
    (title, slug, category, short_description, duration_label, level_label, price, currency, visibility, created_by)
SELECT seed.title, seed.slug, seed.category, seed.short_description, seed.duration_label, seed.level_label,
       seed.recorded_price, 'PKR', 'published', NULL
FROM (
    SELECT 'Full Stack Web Development' AS title, 'full-stack-web-development' AS slug,
           'Web Development' AS category,
           'Learn to build real projects using HTML, CSS, JavaScript, PHP, and MySQL.' AS short_description,
           '3 Months' AS duration_label, 'Beginner' AS level_label, 5000.00 AS recorded_price
    UNION ALL SELECT 'Digital Marketing Essentials', 'digital-marketing-essentials', 'Digital Marketing',
           'Practical training in SEO, social media, and advertising campaigns.', '2 Months', 'Beginner', 3000.00
    UNION ALL SELECT 'Graphic Design Basics', 'graphic-design-basics', 'Graphic Design',
           'Learn to create professional designs using Canva and Photoshop.', '6 Weeks', 'Beginner', 2500.00
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM courses existing WHERE existing.slug = seed.slug);

INSERT INTO course_pricing_options (course_id, option_code, display_label, price, currency)
SELECT c.id, pricing.option_code, pricing.display_label, pricing.price, 'PKR'
FROM (
    SELECT 'full-stack-web-development' AS slug, 'recorded_only' AS option_code, 'Recorded Only' AS display_label, 5000.00 AS price
    UNION ALL SELECT 'full-stack-web-development', 'live_recorded', 'Live + Recorded', 10000.00
    UNION ALL SELECT 'digital-marketing-essentials', 'recorded_only', 'Recorded Only', 3000.00
    UNION ALL SELECT 'digital-marketing-essentials', 'live_recorded', 'Live + Recorded', 7000.00
    UNION ALL SELECT 'graphic-design-basics', 'recorded_only', 'Recorded Only', 2500.00
    UNION ALL SELECT 'graphic-design-basics', 'live_recorded', 'Live + Recorded', 6000.00
) AS pricing
JOIN courses c ON c.slug = pricing.slug
WHERE NOT EXISTS (
    SELECT 1 FROM course_pricing_options existing
    WHERE existing.course_id = c.id AND existing.option_code = pricing.option_code
);
