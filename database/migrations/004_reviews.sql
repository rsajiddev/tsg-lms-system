-- Student and client reviews shown on the public home page.
-- Apply once after 003_published_course_catalogue.sql.
CREATE TABLE reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reviewer_type ENUM('student', 'client') NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    context_label VARCHAR(120) NULL,
    rating TINYINT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    INDEX idx_reviews_listing (status, created_at),
    INDEX idx_reviews_recent_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed with the reviews already published on the site so the section is never empty.
INSERT INTO reviews (reviewer_type, full_name, context_label, rating, content, status)
SELECT seed.reviewer_type, seed.full_name, seed.context_label, seed.rating, seed.content, 'approved'
FROM (
    SELECT 'client' AS reviewer_type, 'Muhammad J. Zafar' AS full_name,
           'Website Development Client' AS context_label, 5 AS rating,
           'Exceptional services by Tech SG Studio. Love their work and especially the commitment to time and quality was outstanding.' AS content
    UNION ALL SELECT 'client', 'Fatima', 'Website Development Client', 5,
           'We took web development services from Tech SG Studio and were amazed by the professional services received. They have a good engagement strategy and the response time is very quick.'
    UNION ALL SELECT 'client', 'Sarah Khan', 'Online Store Owner', 5,
           'They built my e-commerce store with a complete admin panel. The team was very cooperative and delivered right on time.'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM reviews existing WHERE existing.full_name = seed.full_name
);
