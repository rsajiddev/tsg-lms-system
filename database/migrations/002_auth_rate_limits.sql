-- Authentication request throttling; apply once after 001_initial_schema.sql.
CREATE TABLE IF NOT EXISTS auth_rate_limits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 action VARCHAR(40) NOT NULL,
 subject_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_rate_limit_lookup(action, subject_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
