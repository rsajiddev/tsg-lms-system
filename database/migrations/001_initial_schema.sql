-- TSG Academy LMS initial relational schema (MySQL 8 / InnoDB / utf8mb4).
-- Import into a newly created tsg_academy_lms database. Review policy decisions before production.
SET
       NAMES utf8mb4;

SET
       FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       full_name VARCHAR(150) NOT NULL,
       email VARCHAR(190) NOT NULL UNIQUE,
       password_hash VARCHAR(255) NOT NULL,
       role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
       email_verified_at DATETIME NULL,
       status ENUM('active', 'pending', 'suspended') NOT NULL DEFAULT 'pending',
       last_login_at DATETIME NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       INDEX idx_users_role_status(role, status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_otps (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       user_id BIGINT UNSIGNED NOT NULL,
       purpose ENUM('verify_email', 'reset_password') NOT NULL,
       otp_hash VARCHAR(255) NOT NULL,
       expires_at DATETIME NOT NULL,
       consumed_at DATETIME NULL,
       attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_otps_user FOREIGN KEY(user_id) REFERENCES users(id),
       INDEX idx_otp_lookup(user_id, purpose, expires_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       title VARCHAR(200) NOT NULL,
       slug VARCHAR(220) NOT NULL UNIQUE,
       category VARCHAR(100) NULL,
       short_description VARCHAR(500) NULL,
       description TEXT NULL,
       duration_label VARCHAR(100) NULL,
       level_label VARCHAR(50) NULL,
       image_path VARCHAR(500) NULL,
       price DECIMAL(10, 2) NOT NULL DEFAULT 0,
       currency CHAR(3) NOT NULL DEFAULT 'PKR',
       visibility ENUM('draft', 'published', 'hidden', 'archived') NOT NULL DEFAULT 'draft',
       created_by BIGINT UNSIGNED NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       CONSTRAINT fk_courses_creator FOREIGN KEY(created_by) REFERENCES users(id),
       INDEX idx_courses_visibility(visibility)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_pricing_options (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       course_id BIGINT UNSIGNED NOT NULL,
       option_code ENUM('recorded_only', 'live_recorded') NOT NULL,
       display_label VARCHAR(100) NOT NULL,
       price DECIMAL(10, 2) NOT NULL,
       currency CHAR(3) NOT NULL DEFAULT 'PKR',
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       CONSTRAINT fk_course_pricing_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
       UNIQUE KEY uq_course_pricing_option(course_id, option_code),
       INDEX idx_course_pricing_course(course_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_modules (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       course_id BIGINT UNSIGNED NOT NULL,
       title VARCHAR(200) NOT NULL,
       sort_order INT UNSIGNED NOT NULL DEFAULT 0,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_modules_course FOREIGN KEY(course_id) REFERENCES courses(id),
       INDEX idx_modules_course(course_id, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lessons (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       module_id BIGINT UNSIGNED NOT NULL,
       title VARCHAR(200) NOT NULL,
       body MEDIUMTEXT NULL,
       resource_path VARCHAR(500) NULL,
       video_url VARCHAR(600) NULL,
       video_provider ENUM('none', 'file', 'youtube', 'vimeo', 'embed') NOT NULL DEFAULT 'none',
       video_duration_seconds INT UNSIGNED NULL,
       meeting_url VARCHAR(600) NULL,
       meeting_platform ENUM('google_meet', 'zoom') NULL,
       meeting_at DATETIME NULL,
       sort_order INT UNSIGNED NOT NULL DEFAULT 0,
       status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_lessons_module FOREIGN KEY(module_id) REFERENCES course_modules(id),
       INDEX idx_lessons_module(module_id, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Keep existing installations compatible: lessons gain the recorded-video layer.
SET
       @add_lesson_video_url = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lessons ADD COLUMN video_url VARCHAR(600) NULL AFTER resource_path',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lessons'
                     AND COLUMN_NAME = 'video_url'
       );

PREPARE add_lesson_video_url_stmt
FROM
       @add_lesson_video_url;

EXECUTE add_lesson_video_url_stmt;

DEALLOCATE PREPARE add_lesson_video_url_stmt;

SET
       @add_lesson_video_provider = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lessons ADD COLUMN video_provider ENUM(''none'', ''file'', ''youtube'', ''vimeo'', ''embed'') NOT NULL DEFAULT ''none'' AFTER video_url',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lessons'
                     AND COLUMN_NAME = 'video_provider'
       );

PREPARE add_lesson_video_provider_stmt
FROM
       @add_lesson_video_provider;

EXECUTE add_lesson_video_provider_stmt;

DEALLOCATE PREPARE add_lesson_video_provider_stmt;

SET
       @add_lesson_video_duration = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lessons ADD COLUMN video_duration_seconds INT UNSIGNED NULL AFTER video_provider',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lessons'
                     AND COLUMN_NAME = 'video_duration_seconds'
       );

PREPARE add_lesson_video_duration_stmt
FROM
       @add_lesson_video_duration;

EXECUTE add_lesson_video_duration_stmt;

DEALLOCATE PREPARE add_lesson_video_duration_stmt;

-- Backfill the provider for rows created before the column existed.
UPDATE
       lessons
SET
       video_provider = CASE
              WHEN video_url IS NULL
              OR TRIM(video_url) = '' THEN 'none'
              WHEN video_url LIKE '%youtube.com%'
              OR video_url LIKE '%youtu.be%' THEN 'youtube'
              WHEN video_url LIKE '%vimeo.com%' THEN 'vimeo'
              ELSE 'file'
       END
WHERE
       video_provider = 'none'
       OR video_provider IS NULL;

-- Live classes ride the same lesson row as the recording. The link is a plain external URL -
-- the Academy creates the meeting in Zoom or Google Meet and pastes it here - and meeting_at is
-- stored as the UTC instant the admin typed, because every timestamp in this schema is UTC and a
-- browser-side timezone guess would make two students read two different class times.
SET
       @add_lesson_meeting_url = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lessons ADD COLUMN meeting_url VARCHAR(600) NULL AFTER video_duration_seconds',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lessons'
                     AND COLUMN_NAME = 'meeting_url'
       );

PREPARE add_lesson_meeting_url_stmt
FROM
       @add_lesson_meeting_url;

EXECUTE add_lesson_meeting_url_stmt;

DEALLOCATE PREPARE add_lesson_meeting_url_stmt;

SET
       @add_lesson_meeting_platform = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lessons ADD COLUMN meeting_platform ENUM(''google_meet'', ''zoom'') NULL AFTER meeting_url',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lessons'
                     AND COLUMN_NAME = 'meeting_platform'
       );

PREPARE add_lesson_meeting_platform_stmt
FROM
       @add_lesson_meeting_platform;

EXECUTE add_lesson_meeting_platform_stmt;

DEALLOCATE PREPARE add_lesson_meeting_platform_stmt;

SET
       @add_lesson_meeting_at = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lessons ADD COLUMN meeting_at DATETIME NULL AFTER meeting_url',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lessons'
                     AND COLUMN_NAME = 'meeting_at'
       );

PREPARE add_lesson_meeting_at_stmt
FROM
       @add_lesson_meeting_at;

EXECUTE add_lesson_meeting_at_stmt;

DEALLOCATE PREPARE add_lesson_meeting_at_stmt;

UPDATE
       lessons
SET
       meeting_platform = CASE
              WHEN meeting_url LIKE '%meet.google.com%' THEN 'google_meet'
              WHEN meeting_url LIKE '%zoom.us%' THEN 'zoom'
              ELSE meeting_platform
       END
WHERE
       meeting_platform IS NULL
       AND meeting_url IS NOT NULL
       AND meeting_url <> '';

CREATE TABLE IF NOT EXISTS student_course_access (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       course_id BIGINT UNSIGNED NOT NULL,
       status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
       plan_code ENUM('recorded_only', 'live_recorded') NOT NULL DEFAULT 'recorded_only',
       granted_by BIGINT UNSIGNED NOT NULL,
       granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       expires_at DATETIME NULL,
       revoked_by BIGINT UNSIGNED NULL,
       revoked_at DATETIME NULL,
       UNIQUE KEY uq_student_course(student_id, course_id),
       CONSTRAINT fk_access_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_access_course FOREIGN KEY(course_id) REFERENCES courses(id),
       CONSTRAINT fk_access_granted_by FOREIGN KEY(granted_by) REFERENCES users(id),
       CONSTRAINT fk_access_revoked_by FOREIGN KEY(revoked_by) REFERENCES users(id),
       INDEX idx_access_status(student_id, status),
       INDEX idx_access_plan(student_id, plan_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Keep existing installations compatible: course access expires relative to its grant/approval timestamp.
SET
       @add_access_expiry = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE student_course_access ADD COLUMN expires_at DATETIME NULL AFTER granted_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'student_course_access'
                     AND COLUMN_NAME = 'expires_at'
       );

PREPARE add_access_expiry_stmt
FROM
       @add_access_expiry;

EXECUTE add_access_expiry_stmt;

DEALLOCATE PREPARE add_access_expiry_stmt;

-- Backfill valid historical grants from their original grant date; unknown free-text durations remain visible for admin correction.
UPDATE
       student_course_access a
       JOIN courses c ON c.id = a.course_id
SET
       a.expires_at = CASE
              WHEN LOWER(TRIM(c.duration_label)) REGEXP '^[1-9][0-9]{0,2}[[:space:]]*days?$' THEN TIMESTAMPADD(
                     DAY,
                     CAST(
                            REGEXP_SUBSTR(TRIM(c.duration_label), '^[0-9]+') AS UNSIGNED
                     ),
                     a.granted_at
              )
              WHEN LOWER(TRIM(c.duration_label)) REGEXP '^[1-9][0-9]{0,2}[[:space:]]*weeks?$' THEN TIMESTAMPADD(
                     WEEK,
                     CAST(
                            REGEXP_SUBSTR(TRIM(c.duration_label), '^[0-9]+') AS UNSIGNED
                     ),
                     a.granted_at
              )
              WHEN LOWER(TRIM(c.duration_label)) REGEXP '^[1-9][0-9]{0,2}[[:space:]]*months?$' THEN TIMESTAMPADD(
                     MONTH,
                     CAST(
                            REGEXP_SUBSTR(TRIM(c.duration_label), '^[0-9]+') AS UNSIGNED
                     ),
                     a.granted_at
              )
              WHEN LOWER(TRIM(c.duration_label)) REGEXP '^[1-9][0-9]{0,2}[[:space:]]*years?$' THEN TIMESTAMPADD(
                     YEAR,
                     CAST(
                            REGEXP_SUBSTR(TRIM(c.duration_label), '^[0-9]+') AS UNSIGNED
                     ),
                     a.granted_at
              )
              ELSE a.granted_at
       END
WHERE
       a.expires_at IS NULL;

SET
       @add_access_expiry_index = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE student_course_access ADD INDEX idx_access_expiry(student_id,status,expires_at)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'student_course_access'
                     AND INDEX_NAME = 'idx_access_expiry'
       );

PREPARE add_access_expiry_index_stmt
FROM
       @add_access_expiry_index;

EXECUTE add_access_expiry_index_stmt;

DEALLOCATE PREPARE add_access_expiry_index_stmt;

-- The delivery plan the student bought used to live only in the payment audit blob, which no
-- page could read. recorded_only is the default on purpose: a plan this file cannot work out
-- must not hand out a live class link.
SET
       @add_access_plan_code = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE student_course_access ADD COLUMN plan_code ENUM(''recorded_only'', ''live_recorded'') NOT NULL DEFAULT ''recorded_only'' AFTER status',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'student_course_access'
                     AND COLUMN_NAME = 'plan_code'
       );

PREPARE add_access_plan_code_stmt
FROM
       @add_access_plan_code;

EXECUTE add_access_plan_code_stmt;

DEALLOCATE PREPARE add_access_plan_code_stmt;

SET
       @add_access_plan_index = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE student_course_access ADD INDEX idx_access_plan(student_id,plan_code)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'student_course_access'
                     AND INDEX_NAME = 'idx_access_plan'
       );

PREPARE add_access_plan_index_stmt
FROM
       @add_access_plan_index;

EXECUTE add_access_plan_index_stmt;

DEALLOCATE PREPARE add_access_plan_index_stmt;

CREATE TABLE IF NOT EXISTS payments (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       course_id BIGINT UNSIGNED NOT NULL,
       plan_code ENUM('recorded_only', 'live_recorded') NULL,
       amount DECIMAL(10, 2) NOT NULL,
       currency CHAR(3) NOT NULL DEFAULT 'PKR',
       transaction_reference VARCHAR(190) NOT NULL,
       payment_proof_path VARCHAR(500) NULL,
       receipt_number VARCHAR(48) NULL,
       receipt_issued_at DATETIME NULL,
       status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
       reviewed_by BIGINT UNSIGNED NULL,
       reviewed_at DATETIME NULL,
       review_note VARCHAR(1000) NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_payments_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_payments_course FOREIGN KEY(course_id) REFERENCES courses(id),
       CONSTRAINT fk_payments_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id),
       INDEX idx_payment_review(status, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Keep student-uploaded transaction proof separate from academy-issued receipts.
SET
       @rename_payment_receipt_path = (
              SELECT
                     IF(
                            SUM(COLUMN_NAME = 'receipt_path') = 1
                            AND SUM(COLUMN_NAME = 'payment_proof_path') = 0,
                            'ALTER TABLE payments CHANGE COLUMN receipt_path payment_proof_path VARCHAR(500) NULL',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'payments'
       );

PREPARE rename_payment_receipt_path_stmt
FROM
       @rename_payment_receipt_path;

EXECUTE rename_payment_receipt_path_stmt;

DEALLOCATE PREPARE rename_payment_receipt_path_stmt;

SET
       @add_payment_proof_path = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE payments ADD COLUMN payment_proof_path VARCHAR(500) NULL AFTER transaction_reference',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'payments'
                     AND COLUMN_NAME = 'payment_proof_path'
       );

PREPARE add_payment_proof_path_stmt
FROM
       @add_payment_proof_path;

EXECUTE add_payment_proof_path_stmt;

DEALLOCATE PREPARE add_payment_proof_path_stmt;

SET
       @add_payment_receipt_number = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE payments ADD COLUMN receipt_number VARCHAR(48) NULL AFTER payment_proof_path',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'payments'
                     AND COLUMN_NAME = 'receipt_number'
       );

PREPARE add_payment_receipt_number_stmt
FROM
       @add_payment_receipt_number;

EXECUTE add_payment_receipt_number_stmt;

DEALLOCATE PREPARE add_payment_receipt_number_stmt;

SET
       @add_payment_receipt_issued_at = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE payments ADD COLUMN receipt_issued_at DATETIME NULL AFTER receipt_number',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'payments'
                     AND COLUMN_NAME = 'receipt_issued_at'
       );

PREPARE add_payment_receipt_issued_at_stmt
FROM
       @add_payment_receipt_issued_at;

EXECUTE add_payment_receipt_issued_at_stmt;

DEALLOCATE PREPARE add_payment_receipt_issued_at_stmt;

UPDATE
       payments
SET
       receipt_number = CONCAT(
              'TSG-RCP-',
              YEAR(COALESCE(reviewed_at, created_at)),
              '-',
              LPAD(id, 6, '0')
       ),
       receipt_issued_at = COALESCE(reviewed_at, created_at)
WHERE
       status = 'approved'
       AND receipt_number IS NULL;

-- Keep existing installations compatible: the checkout option a student picked is a fact about the
-- purchase, so it belongs on the payment row instead of only inside the audit blob.
SET
       @add_payment_plan_code = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE payments ADD COLUMN plan_code ENUM(''recorded_only'', ''live_recorded'') NULL AFTER course_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'payments'
                     AND COLUMN_NAME = 'plan_code'
       );

PREPARE add_payment_plan_code_stmt
FROM
       @add_payment_plan_code;

EXECUTE add_payment_plan_code_stmt;

DEALLOCATE PREPARE add_payment_plan_code_stmt;

CREATE TABLE IF NOT EXISTS quizzes (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       course_id BIGINT UNSIGNED NOT NULL,
       week_number SMALLINT UNSIGNED NOT NULL,
       title VARCHAR(200) NOT NULL,
       instructions TEXT NULL,
       status ENUM('draft', 'scheduled', 'released', 'closed') NOT NULL DEFAULT 'draft',
       time_limit_seconds INT UNSIGNED NOT NULL DEFAULT 1200,
       question_count SMALLINT UNSIGNED NOT NULL DEFAULT 75,
       pass_mark_percent DECIMAL(5, 2) NOT NULL DEFAULT 50.00,
       attempt_limit TINYINT UNSIGNED NOT NULL DEFAULT 1,
       unlock_at DATETIME NULL,
       close_at DATETIME NULL,
       created_by BIGINT UNSIGNED NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uq_quiz_course_week(course_id, week_number),
       CONSTRAINT fk_quiz_course FOREIGN KEY(course_id) REFERENCES courses(id),
       CONSTRAINT fk_quiz_creator FOREIGN KEY(created_by) REFERENCES users(id),
       INDEX idx_quiz_release(status, unlock_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_questions (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       quiz_id BIGINT UNSIGNED NOT NULL,
       question_text TEXT NOT NULL,
       marks DECIMAL(7, 2) NOT NULL DEFAULT 1,
       sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_question_quiz FOREIGN KEY(quiz_id) REFERENCES quizzes(id),
       INDEX idx_question_quiz(quiz_id, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_options (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       question_id BIGINT UNSIGNED NOT NULL,
       option_text TEXT NOT NULL,
       is_correct TINYINT(1) NOT NULL DEFAULT 0,
       sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
       CONSTRAINT fk_option_question FOREIGN KEY(question_id) REFERENCES quiz_questions(id),
       INDEX idx_options_question(question_id, sort_order)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempts (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       quiz_id BIGINT UNSIGNED NOT NULL,
       student_id BIGINT UNSIGNED NOT NULL,
       started_at DATETIME NOT NULL,
       deadline_at DATETIME NOT NULL,
       submitted_at DATETIME NULL,
       status ENUM(
              'in_progress',
              'submitted',
              'auto_submitted',
              'timed_out'
       ) NOT NULL DEFAULT 'in_progress',
       score DECIMAL(9, 2) NULL,
       total_marks DECIMAL(9, 2) NULL,
       percentage DECIMAL(5, 2) NULL,
       correct_count SMALLINT UNSIGNED NULL,
       result ENUM('pass', 'fail') NULL,
       submission_reason VARCHAR(40) NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_attempt_quiz FOREIGN KEY(quiz_id) REFERENCES quizzes(id),
       CONSTRAINT fk_attempt_student FOREIGN KEY(student_id) REFERENCES users(id),
       INDEX idx_attempt_student(student_id, created_at),
       INDEX idx_attempt_deadline(status, deadline_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_answers (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       attempt_id BIGINT UNSIGNED NOT NULL,
       question_id BIGINT UNSIGNED NOT NULL,
       selected_option_id BIGINT UNSIGNED NULL,
       awarded_marks DECIMAL(7, 2) NOT NULL DEFAULT 0,
       UNIQUE KEY uq_attempt_question(attempt_id, question_id),
       CONSTRAINT fk_answer_attempt FOREIGN KEY(attempt_id) REFERENCES quiz_attempts(id),
       CONSTRAINT fk_answer_question FOREIGN KEY(question_id) REFERENCES quiz_questions(id),
       CONSTRAINT fk_answer_option FOREIGN KEY(selected_option_id) REFERENCES quiz_options(id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Additive learning-phase fields. Keep this project on its single import file.
SET
       @add_quiz_type = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE quizzes ADD COLUMN quiz_type ENUM(''practice'',''lesson'',''module'',''final'') NOT NULL DEFAULT ''lesson'' AFTER instructions',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quizzes'
                     AND COLUMN_NAME = 'quiz_type'
       );

PREPARE add_quiz_type_stmt
FROM
       @add_quiz_type;

EXECUTE add_quiz_type_stmt;

DEALLOCATE PREPARE add_quiz_type_stmt;

SET
       @add_quiz_practice_topic = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE quizzes ADD COLUMN practice_topic ENUM(''general'',''php_mysql'') NOT NULL DEFAULT ''general'' AFTER quiz_type',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quizzes'
                     AND COLUMN_NAME = 'practice_topic'
       );

PREPARE add_quiz_practice_topic_stmt
FROM
       @add_quiz_practice_topic;

EXECUTE add_quiz_practice_topic_stmt;

DEALLOCATE PREPARE add_quiz_practice_topic_stmt;

SET
       @add_question_explanation = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE quiz_questions ADD COLUMN explanation TEXT NULL AFTER question_text',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quiz_questions'
                     AND COLUMN_NAME = 'explanation'
       );

PREPARE add_question_explanation_stmt
FROM
       @add_question_explanation;

EXECUTE add_question_explanation_stmt;

DEALLOCATE PREPARE add_question_explanation_stmt;

CREATE TABLE IF NOT EXISTS quiz_retake_approvals (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       quiz_id BIGINT UNSIGNED NOT NULL,
       student_id BIGINT UNSIGNED NOT NULL,
       request_status ENUM(
              'pending',
              'approved',
              'rejected'
       ) NOT NULL DEFAULT 'pending',
       student_note VARCHAR(1000) NULL,
       student_requested_at DATETIME NULL,
       admin_note VARCHAR(1000) NULL,
       approved_by BIGINT UNSIGNED NULL,
       approved_at DATETIME NULL,
       used_at DATETIME NULL,
       UNIQUE KEY uq_quiz_retake_student(quiz_id, student_id),
       CONSTRAINT fk_retake_quiz FOREIGN KEY(quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
       CONSTRAINT fk_retake_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE,
       CONSTRAINT fk_retake_admin FOREIGN KEY(approved_by) REFERENCES users(id),
       INDEX idx_quiz_retake_available(student_id, used_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Final-test retake workflow: students raise a request, an admin approves or rejects it, and only one extra attempt is unlocked.
SET
       @add_retake_request_status = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE quiz_retake_approvals ADD COLUMN request_status ENUM(''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''pending'' AFTER student_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quiz_retake_approvals'
                     AND COLUMN_NAME = 'request_status'
       );

PREPARE add_retake_request_status_stmt
FROM
       @add_retake_request_status;

EXECUTE add_retake_request_status_stmt;

DEALLOCATE PREPARE add_retake_request_status_stmt;

SET
       @add_retake_student_note = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE quiz_retake_approvals ADD COLUMN student_note VARCHAR(1000) NULL AFTER request_status',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quiz_retake_approvals'
                     AND COLUMN_NAME = 'student_note'
       );

PREPARE add_retake_student_note_stmt
FROM
       @add_retake_student_note;

EXECUTE add_retake_student_note_stmt;

DEALLOCATE PREPARE add_retake_student_note_stmt;

SET
       @add_retake_student_requested_at = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE quiz_retake_approvals ADD COLUMN student_requested_at DATETIME NULL AFTER student_note',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quiz_retake_approvals'
                     AND COLUMN_NAME = 'student_requested_at'
       );

PREPARE add_retake_student_requested_at_stmt
FROM
       @add_retake_student_requested_at;

EXECUTE add_retake_student_requested_at_stmt;

DEALLOCATE PREPARE add_retake_student_requested_at_stmt;

SET
       @add_retake_admin_note = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE quiz_retake_approvals ADD COLUMN admin_note VARCHAR(1000) NULL AFTER student_requested_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quiz_retake_approvals'
                     AND COLUMN_NAME = 'admin_note'
       );

PREPARE add_retake_admin_note_stmt
FROM
       @add_retake_admin_note;

EXECUTE add_retake_admin_note_stmt;

DEALLOCATE PREPARE add_retake_admin_note_stmt;

-- Approver fields only populate once an admin decides, so relax the original NOT NULL definition for existing installs.
SET
       @retake_approver_nullable = (
              SELECT
                     IF(
                            IS_NULLABLE = 'NO',
                            'ALTER TABLE quiz_retake_approvals MODIFY COLUMN approved_by BIGINT UNSIGNED NULL',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quiz_retake_approvals'
                     AND COLUMN_NAME = 'approved_by'
       );

PREPARE retake_approver_nullable_stmt
FROM
       @retake_approver_nullable;

EXECUTE retake_approver_nullable_stmt;

DEALLOCATE PREPARE retake_approver_nullable_stmt;

SET
       @retake_approved_at_nullable = (
              SELECT
                     IF(
                            IS_NULLABLE = 'NO',
                            'ALTER TABLE quiz_retake_approvals MODIFY COLUMN approved_at DATETIME NULL',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'quiz_retake_approvals'
                     AND COLUMN_NAME = 'approved_at'
       );

PREPARE retake_approved_at_nullable_stmt
FROM
       @retake_approved_at_nullable;

EXECUTE retake_approved_at_nullable_stmt;

DEALLOCATE PREPARE retake_approved_at_nullable_stmt;

-- Snapshot randomized question and option order once per attempt so refreshes
-- preserve order and submitted answers remain bound to the original question IDs.
CREATE TABLE IF NOT EXISTS quiz_attempt_questions (
       attempt_id BIGINT UNSIGNED NOT NULL,
       question_id BIGINT UNSIGNED NOT NULL,
       sort_order SMALLINT UNSIGNED NOT NULL,
       PRIMARY KEY(attempt_id, question_id),
       UNIQUE KEY uq_attempt_question_order(attempt_id, sort_order),
       CONSTRAINT fk_attempt_question_attempt FOREIGN KEY(attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
       CONSTRAINT fk_attempt_question_question FOREIGN KEY(question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempt_options (
       attempt_id BIGINT UNSIGNED NOT NULL,
       question_id BIGINT UNSIGNED NOT NULL,
       option_id BIGINT UNSIGNED NOT NULL,
       sort_order TINYINT UNSIGNED NOT NULL,
       PRIMARY KEY(attempt_id, question_id, option_id),
       UNIQUE KEY uq_attempt_option_order(attempt_id, question_id, sort_order),
       CONSTRAINT fk_attempt_option_attempt FOREIGN KEY(attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
       CONSTRAINT fk_attempt_option_question FOREIGN KEY(question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
       CONSTRAINT fk_attempt_option_option FOREIGN KEY(option_id) REFERENCES quiz_options(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       course_id BIGINT UNSIGNED NOT NULL,
       week_number SMALLINT UNSIGNED NULL,
       title VARCHAR(200) NOT NULL,
       instructions TEXT NULL,
       due_at DATETIME NULL,
       max_marks DECIMAL(8, 2) NOT NULL DEFAULT 100,
       status ENUM('draft', 'published', 'closed') NOT NULL DEFAULT 'draft',
       created_by BIGINT UNSIGNED NOT NULL,
       CONSTRAINT fk_assignment_course FOREIGN KEY(course_id) REFERENCES courses(id),
       CONSTRAINT fk_assignment_creator FOREIGN KEY(created_by) REFERENCES users(id),
       INDEX idx_assignment_course(course_id, status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET
       @add_assignment_type = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignments ADD COLUMN assignment_type ENUM(''exercise'',''mini_project'') NOT NULL DEFAULT ''exercise'' AFTER course_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignments'
                     AND COLUMN_NAME = 'assignment_type'
       );

PREPARE add_assignment_type_stmt
FROM
       @add_assignment_type;

EXECUTE add_assignment_type_stmt;

DEALLOCATE PREPARE add_assignment_type_stmt;

SET
       @add_assignment_module = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignments ADD COLUMN module_id BIGINT UNSIGNED NULL AFTER assignment_type',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignments'
                     AND COLUMN_NAME = 'module_id'
       );

PREPARE add_assignment_module_stmt
FROM
       @add_assignment_module;

EXECUTE add_assignment_module_stmt;

DEALLOCATE PREPARE add_assignment_module_stmt;

SET
       @add_assignment_topic = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignments ADD COLUMN assignment_topic ENUM(''general'',''php_mysql'') NOT NULL DEFAULT ''general'' AFTER module_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignments'
                     AND COLUMN_NAME = 'assignment_topic'
       );

PREPARE add_assignment_topic_stmt
FROM
       @add_assignment_topic;

EXECUTE add_assignment_topic_stmt;

DEALLOCATE PREPARE add_assignment_topic_stmt;

CREATE TABLE IF NOT EXISTS assignment_submissions (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       assignment_id BIGINT UNSIGNED NOT NULL,
       student_id BIGINT UNSIGNED NOT NULL,
       submission_text MEDIUMTEXT NULL,
       file_path VARCHAR(500) NULL,
       submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       status ENUM('submitted', 'reviewed', 'resubmit_requested') NOT NULL DEFAULT 'submitted',
       marks_awarded DECIMAL(8, 2) NULL,
       feedback TEXT NULL,
       reviewed_by BIGINT UNSIGNED NULL,
       reviewed_at DATETIME NULL,
       CONSTRAINT fk_submission_assignment FOREIGN KEY(assignment_id) REFERENCES assignments(id),
       CONSTRAINT fk_submission_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_submission_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id),
       INDEX idx_submission_student(student_id, submitted_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET
       @add_assignment_cutoff = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignments ADD COLUMN admin_cutoff_at DATETIME NULL AFTER due_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignments'
                     AND COLUMN_NAME = 'admin_cutoff_at'
       );

PREPARE add_assignment_cutoff_stmt
FROM
       @add_assignment_cutoff;

EXECUTE add_assignment_cutoff_stmt;

DEALLOCATE PREPARE add_assignment_cutoff_stmt;

SET
       @add_assignment_late_deduction = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignments ADD COLUMN late_deduction_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER admin_cutoff_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignments'
                     AND COLUMN_NAME = 'late_deduction_percent'
       );

PREPARE add_assignment_late_deduction_stmt
FROM
       @add_assignment_late_deduction;

EXECUTE add_assignment_late_deduction_stmt;

DEALLOCATE PREPARE add_assignment_late_deduction_stmt;

SET
       @add_submission_github_url = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignment_submissions ADD COLUMN github_url VARCHAR(500) NULL AFTER submission_text',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignment_submissions'
                     AND COLUMN_NAME = 'github_url'
       );

PREPARE add_submission_github_url_stmt
FROM
       @add_submission_github_url;

EXECUTE add_submission_github_url_stmt;

DEALLOCATE PREPARE add_submission_github_url_stmt;

SET
       @add_submission_late_flag = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignment_submissions ADD COLUMN submitted_late TINYINT(1) NOT NULL DEFAULT 0 AFTER file_path',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignment_submissions'
                     AND COLUMN_NAME = 'submitted_late'
       );

PREPARE add_submission_late_flag_stmt
FROM
       @add_submission_late_flag;

EXECUTE add_submission_late_flag_stmt;

DEALLOCATE PREPARE add_submission_late_flag_stmt;

SET
       @add_rubric_technical = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignment_submissions ADD COLUMN rubric_technical DECIMAL(3,2) NULL AFTER feedback',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignment_submissions'
                     AND COLUMN_NAME = 'rubric_technical'
       );

PREPARE add_rubric_technical_stmt
FROM
       @add_rubric_technical;

EXECUTE add_rubric_technical_stmt;

DEALLOCATE PREPARE add_rubric_technical_stmt;

SET
       @add_rubric_problem_solving = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignment_submissions ADD COLUMN rubric_problem_solving DECIMAL(3,2) NULL AFTER rubric_technical',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignment_submissions'
                     AND COLUMN_NAME = 'rubric_problem_solving'
       );

PREPARE add_rubric_problem_solving_stmt
FROM
       @add_rubric_problem_solving;

EXECUTE add_rubric_problem_solving_stmt;

DEALLOCATE PREPARE add_rubric_problem_solving_stmt;

SET
       @add_rubric_code_quality = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignment_submissions ADD COLUMN rubric_code_quality DECIMAL(3,2) NULL AFTER rubric_problem_solving',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignment_submissions'
                     AND COLUMN_NAME = 'rubric_code_quality'
       );

PREPARE add_rubric_code_quality_stmt
FROM
       @add_rubric_code_quality;

EXECUTE add_rubric_code_quality_stmt;

DEALLOCATE PREPARE add_rubric_code_quality_stmt;

SET
       @add_rubric_timeliness = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignment_submissions ADD COLUMN rubric_timeliness DECIMAL(3,2) NULL AFTER rubric_code_quality',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignment_submissions'
                     AND COLUMN_NAME = 'rubric_timeliness'
       );

PREPARE add_rubric_timeliness_stmt
FROM
       @add_rubric_timeliness;

EXECUTE add_rubric_timeliness_stmt;

DEALLOCATE PREPARE add_rubric_timeliness_stmt;

SET
       @add_rubric_completeness = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE assignment_submissions ADD COLUMN rubric_completeness DECIMAL(3,2) NULL AFTER rubric_timeliness',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'assignment_submissions'
                     AND COLUMN_NAME = 'rubric_completeness'
       );

PREPARE add_rubric_completeness_stmt
FROM
       @add_rubric_completeness;

EXECUTE add_rubric_completeness_stmt;

DEALLOCATE PREPARE add_rubric_completeness_stmt;

CREATE TABLE IF NOT EXISTS lesson_progress (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       lesson_id BIGINT UNSIGNED NOT NULL,
       watched_seconds INT UNSIGNED NOT NULL DEFAULT 0,
       last_position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
       completed_at DATETIME NULL,
       updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uq_student_lesson(student_id, lesson_id),
       CONSTRAINT fk_progress_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_progress_lesson FOREIGN KEY(lesson_id) REFERENCES lessons(id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Keep existing installations compatible: watch time is recorded before a lesson can complete.
SET
       @add_progress_watched_seconds = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lesson_progress ADD COLUMN watched_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER lesson_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lesson_progress'
                     AND COLUMN_NAME = 'watched_seconds'
       );

PREPARE add_progress_watched_seconds_stmt
FROM
       @add_progress_watched_seconds;

EXECUTE add_progress_watched_seconds_stmt;

DEALLOCATE PREPARE add_progress_watched_seconds_stmt;

SET
       @add_progress_last_position = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lesson_progress ADD COLUMN last_position_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER watched_seconds',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lesson_progress'
                     AND COLUMN_NAME = 'last_position_seconds'
       );

PREPARE add_progress_last_position_stmt
FROM
       @add_progress_last_position;

EXECUTE add_progress_last_position_stmt;

DEALLOCATE PREPARE add_progress_last_position_stmt;

SET
       @add_progress_updated_at = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE lesson_progress ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER completed_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'lesson_progress'
                     AND COLUMN_NAME = 'updated_at'
       );

PREPARE add_progress_updated_at_stmt
FROM
       @add_progress_updated_at;

EXECUTE add_progress_updated_at_stmt;

DEALLOCATE PREPARE add_progress_updated_at_stmt;

CREATE TABLE IF NOT EXISTS notifications (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       created_by BIGINT UNSIGNED NOT NULL,
       title VARCHAR(200) NOT NULL,
       body TEXT NOT NULL,
       audience ENUM('all_students', 'selected_students') NOT NULL DEFAULT 'all_students',
       published_at DATETIME NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_notification_creator FOREIGN KEY(created_by) REFERENCES users(id),
       INDEX idx_notification_published(published_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_recipients (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       notification_id BIGINT UNSIGNED NOT NULL,
       student_id BIGINT UNSIGNED NOT NULL,
       read_at DATETIME NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       UNIQUE KEY uq_notification_student(notification_id, student_id),
       CONSTRAINT fk_recipient_notification FOREIGN KEY(notification_id) REFERENCES notifications(id),
       CONSTRAINT fk_recipient_student FOREIGN KEY(student_id) REFERENCES users(id),
       INDEX idx_recipient_unread(student_id, read_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_replies (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       notification_id BIGINT UNSIGNED NOT NULL,
       student_id BIGINT UNSIGNED NOT NULL,
       recipient_student_id BIGINT UNSIGNED NOT NULL,
       message TEXT NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_reply_notification FOREIGN KEY(notification_id) REFERENCES notifications(id),
       CONSTRAINT fk_reply_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_reply_recipient FOREIGN KEY(recipient_student_id) REFERENCES users(id),
       INDEX idx_reply_notification(notification_id, created_at),
       INDEX idx_reply_thread(
              notification_id,
              recipient_student_id,
              created_at
       )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET
       @reply_recipient_column_exists = (
              SELECT
                     COUNT(*)
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'notification_replies'
                     AND COLUMN_NAME = 'recipient_student_id'
       );

SET
       @reply_schema_sql = IF(
              @reply_recipient_column_exists = 0,
              'ALTER TABLE notification_replies ADD COLUMN recipient_student_id BIGINT UNSIGNED NULL AFTER student_id',
              'SELECT 1'
       );

PREPARE reply_schema_stmt
FROM
       @reply_schema_sql;

EXECUTE reply_schema_stmt;

DEALLOCATE PREPARE reply_schema_stmt;

UPDATE
       notification_replies
SET
       recipient_student_id = student_id
WHERE
       recipient_student_id IS NULL;

ALTER TABLE
       notification_replies
MODIFY
       recipient_student_id BIGINT UNSIGNED NOT NULL;

SET
       @reply_thread_index_exists = (
              SELECT
                     COUNT(*)
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'notification_replies'
                     AND INDEX_NAME = 'idx_reply_thread'
       );

SET
       @reply_index_sql = IF(
              @reply_thread_index_exists = 0,
              'CREATE INDEX idx_reply_thread ON notification_replies(notification_id,recipient_student_id,created_at)',
              'SELECT 1'
       );

PREPARE reply_index_stmt
FROM
       @reply_index_sql;

EXECUTE reply_index_stmt;

DEALLOCATE PREPARE reply_index_stmt;

SET
       @reply_recipient_fk_exists = (
              SELECT
                     COUNT(*)
              FROM
                     information_schema.TABLE_CONSTRAINTS
              WHERE
                     CONSTRAINT_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'notification_replies'
                     AND CONSTRAINT_NAME = 'fk_reply_recipient'
       );

SET
       @reply_fk_sql = IF(
              @reply_recipient_fk_exists = 0,
              'ALTER TABLE notification_replies ADD CONSTRAINT fk_reply_recipient FOREIGN KEY(recipient_student_id) REFERENCES users(id)',
              'SELECT 1'
       );

PREPARE reply_fk_stmt
FROM
       @reply_fk_sql;

EXECUTE reply_fk_stmt;

DEALLOCATE PREPARE reply_fk_stmt;

CREATE TABLE IF NOT EXISTS audit_logs (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       actor_user_id BIGINT UNSIGNED NULL,
       action VARCHAR(100) NOT NULL,
       entity_type VARCHAR(100) NOT NULL,
       entity_id BIGINT UNSIGNED NULL,
       ip_address VARCHAR(45) NULL,
       user_agent VARCHAR(500) NULL,
       metadata JSON NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_audit_actor FOREIGN KEY(actor_user_id) REFERENCES users(id),
       INDEX idx_audit_action(action, created_at),
       INDEX idx_audit_entity(entity_type, entity_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Recover the plan for purchases made before payments.plan_code existed. The submission audit row
-- is the only place the checkout option was ever written down, so it is read back rather than
-- guessed, and a payment the audit cannot explain stays NULL instead of inventing a live-class
-- purchase. It runs here because this file creates the audit table after the payment section.
UPDATE
       payments p
       JOIN audit_logs a ON a.entity_type = 'payment'
       AND a.entity_id = p.id
       AND a.action = 'payment.submitted'
SET
       p.plan_code = CASE
              WHEN JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.option_code')) = 'live_recorded' THEN 'live_recorded'
              WHEN JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.option_code')) = 'recorded_only' THEN 'recorded_only'
              ELSE p.plan_code
       END
WHERE
       p.plan_code IS NULL
       AND a.metadata IS NOT NULL;

-- An enrolment inherits the plan of the newest approved payment for that student and course, which
-- is the same rule PaymentController applies when it grants or renews access, so a student who
-- upgraded from Recorded Only to Live + Recorded keeps the upgrade through a re-import.
UPDATE
       student_course_access sca
       JOIN (
              SELECT
                     chosen.student_id,
                     chosen.course_id,
                     chosen.plan_code
              FROM
                     payments chosen
                     JOIN (
                            SELECT
                                   student_id,
                                   course_id,
                                   MAX(id) AS latest_payment_id
                            FROM
                                   payments
                            WHERE
                                   status = 'approved'
                            GROUP BY
                                   student_id,
                                   course_id
                     ) newest ON newest.latest_payment_id = chosen.id
       ) inherited ON inherited.student_id = sca.student_id
       AND inherited.course_id = sca.course_id
SET
       sca.plan_code = COALESCE(inherited.plan_code, 'recorded_only')
WHERE
       sca.plan_code <> COALESCE(inherited.plan_code, 'recorded_only');

-- Minimal sign-in audit: never store entered email, password, OTP, or credential material.
CREATE TABLE IF NOT EXISTS login_events (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       user_id BIGINT UNSIGNED NULL,
       event_type ENUM('success', 'failed', 'blocked') NOT NULL,
       ip_address VARCHAR(45) NULL,
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_login_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE
       SET
              NULL,
              INDEX idx_login_event_user_time(user_id, created_at),
              INDEX idx_login_event_type_time(event_type, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       action VARCHAR(40) NOT NULL,
       subject_hash CHAR(64) NOT NULL,
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       INDEX idx_rate_limit_lookup(action, subject_hash, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
       setting_key VARCHAR(100) PRIMARY KEY,
       setting_value VARCHAR(255) NOT NULL,
       updated_by BIGINT UNSIGNED NULL,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       CONSTRAINT fk_app_settings_updater FOREIGN KEY(updated_by) REFERENCES users(id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('otp_lifetime_seconds', '600'),
       ('otp_max_attempts', '5'),
       ('login_max_attempts', '5'),
       ('login_lockout_minutes', '15'),
       ('register_max_attempts', '5'),
       ('register_window_minutes', '60'),
       ('password_reset_max_attempts', '3'),
       ('password_reset_window_minutes', '15'),
       ('otp_resends_per_window', '3'),
       ('otp_resend_window_minutes', '15'),
       ('otp_resend_cooldown_seconds', '60'),
       ('payment_account_title', ''),
       ('payment_bank_name', ''),
       ('payment_account_number', ''),
       ('payment_iban', ''),
       ('payment_wallet_name', ''),
       ('payment_wallet_number', ''),
       ('payment_instructions', '') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
       (setting_key);

-- One institution may publish several transfer destinations for student checkout.
CREATE TABLE IF NOT EXISTS payment_accounts (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       account_title VARCHAR(150) NOT NULL DEFAULT '',
       bank_name VARCHAR(100) NOT NULL DEFAULT '',
       account_number VARCHAR(80) NOT NULL DEFAULT '',
       iban VARCHAR(80) NOT NULL DEFAULT '',
       wallet_name VARCHAR(100) NOT NULL DEFAULT '',
       wallet_number VARCHAR(80) NOT NULL DEFAULT '',
       instructions VARCHAR(255) NOT NULL DEFAULT '',
       is_active TINYINT(1) NOT NULL DEFAULT 1,
       sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       INDEX idx_payment_account_display(is_active, sort_order, id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Preserve the original single-account settings when upgrading an existing installation.
INSERT INTO
       payment_accounts(
              account_title,
              bank_name,
              account_number,
              iban,
              wallet_name,
              wallet_number,
              instructions
       )
SELECT
       COALESCE(
              MAX(
                     CASE
                            WHEN setting_key = 'payment_account_title' THEN setting_value
                     END
              ),
              ''
       ),
       COALESCE(
              MAX(
                     CASE
                            WHEN setting_key = 'payment_bank_name' THEN setting_value
                     END
              ),
              ''
       ),
       COALESCE(
              MAX(
                     CASE
                            WHEN setting_key = 'payment_account_number' THEN setting_value
                     END
              ),
              ''
       ),
       COALESCE(
              MAX(
                     CASE
                            WHEN setting_key = 'payment_iban' THEN setting_value
                     END
              ),
              ''
       ),
       COALESCE(
              MAX(
                     CASE
                            WHEN setting_key = 'payment_wallet_name' THEN setting_value
                     END
              ),
              ''
       ),
       COALESCE(
              MAX(
                     CASE
                            WHEN setting_key = 'payment_wallet_number' THEN setting_value
                     END
              ),
              ''
       ),
       COALESCE(
              MAX(
                     CASE
                            WHEN setting_key = 'payment_instructions' THEN setting_value
                     END
              ),
              ''
       )
FROM
       app_settings
HAVING
       (
              COALESCE(
                     MAX(
                            CASE
                                   WHEN setting_key = 'payment_account_title' THEN setting_value
                            END
                     ),
                     ''
              ) <> ''
              OR COALESCE(
                     MAX(
                            CASE
                                   WHEN setting_key = 'payment_bank_name' THEN setting_value
                            END
                     ),
                     ''
              ) <> ''
              OR COALESCE(
                     MAX(
                            CASE
                                   WHEN setting_key = 'payment_account_number' THEN setting_value
                            END
                     ),
                     ''
              ) <> ''
              OR COALESCE(
                     MAX(
                            CASE
                                   WHEN setting_key = 'payment_wallet_name' THEN setting_value
                            END
                     ),
                     ''
              ) <> ''
              OR COALESCE(
                     MAX(
                            CASE
                                   WHEN setting_key = 'payment_wallet_number' THEN setting_value
                            END
                     ),
                     ''
              ) <> ''
       )
       AND NOT EXISTS (
              SELECT
                     1
              FROM
                     payment_accounts
       );

CREATE TABLE IF NOT EXISTS reviews (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       reviewer_type ENUM('student', 'client') NOT NULL,
       full_name VARCHAR(150) NOT NULL,
       context_label VARCHAR(120) NULL,
       rating TINYINT UNSIGNED NOT NULL,
       content TEXT NOT NULL,
       status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
       ip_address VARCHAR(45) NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT chk_reviews_rating CHECK(
              rating BETWEEN 1
              AND 5
       ),
       INDEX idx_reviews_listing(status, created_at),
       INDEX idx_reviews_recent_ip(ip_address, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       sender_name VARCHAR(150) NOT NULL,
       sender_email VARCHAR(190) NOT NULL,
       phone VARCHAR(40) NOT NULL DEFAULT '',
       subject VARCHAR(150) NOT NULL,
       message TEXT NOT NULL,
       submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       INDEX idx_contact_messages_submitted(submitted_at, id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Seed the published Academy catalogue and its two delivery-mode prices.
INSERT INTO
       courses (
              title,
              slug,
              category,
              short_description,
              duration_label,
              level_label,
              price,
              currency,
              visibility,
              created_by
       )
SELECT
       seed.title,
       seed.slug,
       seed.category,
       seed.short_description,
       seed.duration_label,
       seed.level_label,
       seed.recorded_price,
       'PKR',
       'published',
       NULL
FROM
       (
              SELECT
                     'Full Stack Web Development' AS title,
                     'full-stack-web-development' AS slug,
                     'Web Development' AS category,
                     'Master end-to-end web development by building production-ready applications. You will design responsive front-ends with HTML, CSS and JavaScript, then power them with server-side PHP and MySQL — from database schema to deployment.' AS short_description,
                     '3 Months' AS duration_label,
                     'Beginner' AS level_label,
                     5000.00 AS recorded_price
              UNION
              ALL
              SELECT
                     'Digital Marketing Essentials',
                     'digital-marketing-essentials',
                     'Digital Marketing',
                     'A hands-on programme covering search engine optimisation, social media strategy, paid advertising and analytics. Learn to plan, launch and measure campaigns that drive real traffic and conversions for any business.',
                     '2 Months',
                     'Beginner',
                     3000.00
              UNION
              ALL
              SELECT
                     'Graphic Design Basics',
                     'graphic-design-basics',
                     'Graphic Design',
                     'Build a strong foundation in visual design, typography and layout. Work professionally in Canva and Adobe Photoshop to create logos, social media graphics, marketing collateral and brand identities that stand out.',
                     '6 Weeks',
                     'Beginner',
                     2500.00
       ) AS seed
WHERE
       NOT EXISTS (
              SELECT
                     1
              FROM
                     courses existing
              WHERE
                     existing.slug = seed.slug
       );

INSERT INTO
       course_pricing_options (
              course_id,
              option_code,
              display_label,
              price,
              currency
       )
SELECT
       c.id,
       p.option_code,
       p.display_label,
       p.price,
       'PKR'
FROM
       (
              SELECT
                     'full-stack-web-development' AS slug,
                     'recorded_only' AS option_code,
                     'Recorded Only' AS display_label,
                     5000.00 AS price
              UNION
              ALL
              SELECT
                     'full-stack-web-development',
                     'live_recorded',
                     'Live + Recorded',
                     10000.00
              UNION
              ALL
              SELECT
                     'digital-marketing-essentials',
                     'recorded_only',
                     'Recorded Only',
                     3000.00
              UNION
              ALL
              SELECT
                     'digital-marketing-essentials',
                     'live_recorded',
                     'Live + Recorded',
                     7000.00
              UNION
              ALL
              SELECT
                     'graphic-design-basics',
                     'recorded_only',
                     'Recorded Only',
                     2500.00
              UNION
              ALL
              SELECT
                     'graphic-design-basics',
                     'live_recorded',
                     'Live + Recorded',
                     6000.00
       ) AS p
       JOIN courses c ON c.slug = p.slug
WHERE
       NOT EXISTS (
              SELECT
                     1
              FROM
                     course_pricing_options existing
              WHERE
                     existing.course_id = c.id
                     AND existing.option_code = p.option_code
       );

-- Import the three client reviews recorded as already published in docs/CHANGELOG.md.
INSERT INTO
       reviews (
              reviewer_type,
              full_name,
              context_label,
              rating,
              content,
              status
       )
SELECT
       seed.reviewer_type,
       seed.full_name,
       seed.context_label,
       seed.rating,
       seed.content,
       'approved'
FROM
       (
              SELECT
                     'client' AS reviewer_type,
                     'Muhammad J. Zafar' AS full_name,
                     'Website Development Client' AS context_label,
                     5 AS rating,
                     'Exceptional services by Tech SG Studio. Love their work and especially the commitment to time and quality was outstanding.' AS content
              UNION
              ALL
              SELECT
                     'client',
                     'Fatima',
                     'Website Development Client',
                     5,
                     'We took web development services from Tech SG Studio and were amazed by the professional services received. They have a good engagement strategy and the response time is very quick.'
              UNION
              ALL
              SELECT
                     'client',
                     'Sarah Khan',
                     'Online Store Owner',
                     5,
                     'They built my e-commerce store with a complete admin panel. The team was very cooperative and delivered right on time.'
       ) AS seed
WHERE
       NOT EXISTS (
              SELECT
                     1
              FROM
                     reviews existing
              WHERE
                     existing.full_name = seed.full_name
       );

-- Add course cover-image support to existing databases when this canonical file is re-imported.
SET
       @add_course_image_path = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE courses ADD COLUMN image_path VARCHAR(500) NULL AFTER level_label',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'courses'
                     AND COLUMN_NAME = 'image_path'
       );

PREPARE add_course_image_path_stmt
FROM
       @add_course_image_path;

EXECUTE add_course_image_path_stmt;

DEALLOCATE PREPARE add_course_image_path_stmt;

-- Browser-based coding practice. Student code is never executed by the PHP server.
CREATE TABLE IF NOT EXISTS coding_challenges (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       course_id BIGINT UNSIGNED NOT NULL,
       title VARCHAR(200) NOT NULL,
       instructions TEXT NOT NULL,
       starter_html MEDIUMTEXT NOT NULL,
       starter_css MEDIUMTEXT NOT NULL,
       starter_js MEDIUMTEXT NOT NULL,
       tests_json LONGTEXT NOT NULL,
       status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
       created_by BIGINT UNSIGNED NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       CONSTRAINT fk_coding_challenges_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
       CONSTRAINT fk_coding_challenges_creator FOREIGN KEY(created_by) REFERENCES users(id),
       INDEX idx_coding_challenges_course(course_id, status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coding_challenge_attempts (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       challenge_id BIGINT UNSIGNED NOT NULL,
       student_id BIGINT UNSIGNED NOT NULL,
       html_code MEDIUMTEXT NOT NULL,
       css_code MEDIUMTEXT NOT NULL,
       js_code MEDIUMTEXT NOT NULL,
       reported_status ENUM('pass', 'fail') NOT NULL,
       test_results LONGTEXT NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_coding_attempt_challenge FOREIGN KEY(challenge_id) REFERENCES coding_challenges(id) ON DELETE CASCADE,
       CONSTRAINT fk_coding_attempt_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE,
       INDEX idx_coding_attempt_student(challenge_id, student_id, id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Certificate management and public verification. One request row per student and course;
-- the academy-issued public identifier stays NULL until an admin approves the request.
CREATE TABLE IF NOT EXISTS certificates (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       certificate_id VARCHAR(64) NULL,
       student_id BIGINT UNSIGNED NOT NULL,
       course_id BIGINT UNSIGNED NOT NULL,
       status ENUM('requested', 'issued', 'rejected', 'revoked') NOT NULL DEFAULT 'requested',
       student_remarks VARCHAR(1000) NULL,
       admin_remarks VARCHAR(1000) NULL,
       file_path VARCHAR(500) NULL,
       issued_by BIGINT UNSIGNED NULL,
       reviewed_by BIGINT UNSIGNED NULL,
       revoked_by BIGINT UNSIGNED NULL,
       requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       issued_at DATETIME NULL,
       expires_at DATETIME NULL,
       revoked_at DATETIME NULL,
       revoked_reason VARCHAR(1000) NULL,
       notified_at DATETIME NULL,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uq_certificate_student_course(student_id, course_id),
       -- The public ID is an account reference, not a document reference: a student with two
       -- certificates has them both under one number, so this is a lookup index, not a constraint.
       INDEX idx_certificate_public_id(certificate_id),
       CONSTRAINT fk_certificate_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_certificate_course FOREIGN KEY(course_id) REFERENCES courses(id),
       CONSTRAINT fk_certificate_issuer FOREIGN KEY(issued_by) REFERENCES users(id),
       CONSTRAINT fk_certificate_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id),
       CONSTRAINT fk_certificate_revoked_by FOREIGN KEY(revoked_by) REFERENCES users(id),
       INDEX idx_certificate_status(status, requested_at),
       INDEX idx_certificate_student(student_id, status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Certificate defaults are seeded once and never overwritten by a re-import, so admin edits survive.
-- The old certificate_id_prefix setting is gone for good: a certificate carries its owner's account
-- ID, and that scheme is seeded with the account-reference section at the end of this file.
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('certificate_available_days', '15') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
(setting_key);

-- Student attendance. A successful student login opens one daily row in the pending state; the
-- student must confirm presence before mark_deadline_at, otherwise the row is closed as absent.
CREATE TABLE IF NOT EXISTS attendance_records (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       course_id BIGINT UNSIGNED NULL,
       lesson_id BIGINT UNSIGNED NULL,
       attendance_date DATE NOT NULL,
       status ENUM('pending', 'present', 'absent') NOT NULL DEFAULT 'pending',
       first_login_at DATETIME NULL,
       mark_deadline_at DATETIME NOT NULL,
       marked_at DATETIME NULL,
       closed_at DATETIME NULL,
       login_ip VARCHAR(45) NULL,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uq_attendance_student_date(student_id, attendance_date),
       CONSTRAINT fk_attendance_student FOREIGN KEY(student_id) REFERENCES users(id),
       INDEX idx_attendance_date(attendance_date, status),
       INDEX idx_attendance_student_date(student_id, attendance_date),
       INDEX idx_attendance_pending(mark_deadline_at, status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Attendance is tied to the scheduled live lesson. A legacy daily row can be associated with its
-- live lesson during the backfill below; rows without a matching live lesson remain legacy-only.
SET
       @add_attendance_course_id = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE attendance_records ADD COLUMN course_id BIGINT UNSIGNED NULL AFTER student_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'attendance_records'
                     AND COLUMN_NAME = 'course_id'
       );

PREPARE add_attendance_course_id_stmt
FROM
       @add_attendance_course_id;

EXECUTE add_attendance_course_id_stmt;

DEALLOCATE PREPARE add_attendance_course_id_stmt;

SET
       @add_attendance_lesson_id = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE attendance_records ADD COLUMN lesson_id BIGINT UNSIGNED NULL AFTER course_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'attendance_records'
                     AND COLUMN_NAME = 'lesson_id'
       );

PREPARE add_attendance_lesson_id_stmt
FROM
       @add_attendance_lesson_id;

EXECUTE add_attendance_lesson_id_stmt;

DEALLOCATE PREPARE add_attendance_lesson_id_stmt;

SET
       @make_attendance_first_login_nullable = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE attendance_records MODIFY first_login_at DATETIME NULL',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'attendance_records'
                     AND COLUMN_NAME = 'first_login_at'
                     AND IS_NULLABLE = 'NO'
       );

PREPARE make_attendance_first_login_nullable_stmt
FROM
       @make_attendance_first_login_nullable;

EXECUTE make_attendance_first_login_nullable_stmt;

DEALLOCATE PREPARE make_attendance_first_login_nullable_stmt;

SET
       @add_attendance_course_date_index = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE attendance_records ADD INDEX idx_attendance_course_date(course_id,attendance_date)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'attendance_records'
                     AND INDEX_NAME = 'idx_attendance_course_date'
       );

PREPARE add_attendance_course_date_index_stmt
FROM
       @add_attendance_course_date_index;

EXECUTE add_attendance_course_date_index_stmt;

DEALLOCATE PREPARE add_attendance_course_date_index_stmt;

-- Reuse an old daily mark only when its date matches a scheduled live class the student could attend.
UPDATE
       attendance_records ar
       JOIN lessons l ON DATE(l.meeting_at) = ar.attendance_date
       AND l.meeting_url IS NOT NULL
       AND l.meeting_url <> ''
       JOIN course_modules cm ON cm.id = l.module_id
       JOIN student_course_access sca ON sca.student_id = ar.student_id
       AND sca.course_id = cm.course_id
SET
       ar.course_id = cm.course_id,
       ar.lesson_id = l.id
WHERE
       ar.course_id IS NULL
       AND sca.plan_code = 'live_recorded'
       AND sca.granted_at <= l.meeting_at
       AND (
              sca.expires_at IS NULL
              OR sca.expires_at >= l.meeting_at
       );

-- Attendance grace window is seeded once so an admin change survives a re-import.
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('attendance_grace_minutes', '5') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
(setting_key);

-- Share of a lesson video that must be watched before the lesson may be marked complete.
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('lesson_watch_completion_percent', '90') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
(setting_key);

-- Per-student, per-course grade matrix. Written by App\Services\CourseGrading and read by the
-- student portals, the admin roster and certificate eligibility. Recompute upserts this row.
CREATE TABLE IF NOT EXISTS course_grades (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       course_id BIGINT UNSIGNED NOT NULL,
       video_pct DECIMAL(5, 2) NOT NULL DEFAULT 0,
       quiz_pct DECIMAL(5, 2) NOT NULL DEFAULT 0,
       exercise_pct DECIMAL(5, 2) NOT NULL DEFAULT 0,
       project_pct DECIMAL(5, 2) NOT NULL DEFAULT 0,
       final_pct DECIMAL(5, 2) NOT NULL DEFAULT 0,
       overall_pct DECIMAL(5, 2) NOT NULL DEFAULT 0,
       letter_grade VARCHAR(2) NOT NULL DEFAULT 'F',
       components_mask SMALLINT UNSIGNED NOT NULL DEFAULT 0,
       computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uq_course_grade_student_course(student_id, course_id),
       CONSTRAINT fk_course_grade_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_course_grade_course FOREIGN KEY(course_id) REFERENCES courses(id),
       INDEX idx_course_grade_course(course_id, overall_pct)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Widen access status so a finished course can read Completed and an issued certificate Certified.
-- The check looks for the newest value; when it is already present the ALTER is skipped, so a
-- re-import never warns and active/revoked rows are untouched.
SET
       @widen_access_status = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE student_course_access MODIFY status ENUM(''active'',''revoked'',''completed'',''certified'') NOT NULL DEFAULT ''active''',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'student_course_access'
                     AND COLUMN_NAME = 'status'
                     AND COLUMN_TYPE LIKE '%certified%'
       );

PREPARE widen_access_status_stmt
FROM
       @widen_access_status;

EXECUTE widen_access_status_stmt;

DEALLOCATE PREPARE widen_access_status_stmt;

-- Snapshot the grade and attendance that justified a certificate request, so an admin sees the
-- same numbers the student was held to even if learning activity changes afterwards.
SET
       @add_certificate_snapshot = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE certificates ADD COLUMN overall_percent DECIMAL(5,2) NULL AFTER admin_remarks, ADD COLUMN letter_grade VARCHAR(2) NULL AFTER overall_percent, ADD COLUMN attendance_percent DECIMAL(5,2) NULL AFTER letter_grade',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'certificates'
                     AND COLUMN_NAME = 'overall_percent'
       );

PREPARE add_certificate_snapshot_stmt
FROM
       @add_certificate_snapshot;

EXECUTE add_certificate_snapshot_stmt;

DEALLOCATE PREPARE add_certificate_snapshot_stmt;

-- Grading weights and certificate thresholds. Seeded once and never overwritten by a re-import,
-- so admin adjustments survive. Weights are percentages of the overall course score.
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('grade_weight_video', '30'),
       ('grade_weight_quiz', '28'),
       ('grade_weight_exercise', '14'),
       ('grade_weight_project', '14'),
       ('grade_weight_final', '14'),
       ('certificate_min_grade_percent', '60'),
       ('certificate_min_attendance_percent', '75') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
(setting_key);

-- Move the old default to the company-approved 60% pass mark without replacing an admin override.
UPDATE
       app_settings
SET
       setting_value = '60'
WHERE
       setting_key = 'certificate_min_grade_percent'
       AND setting_value = '50';

-- Automated in-app notifications need to say what they are about, so the reader can group
-- them per workspace area and the sidebar can count unread items by category. Added in one
-- guarded ALTER so a re-import of an existing database only runs it once.
SET
       @add_notification_metadata = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE notifications ADD COLUMN category VARCHAR(40) NOT NULL DEFAULT ''announcement'' AFTER created_by, ADD COLUMN source_type VARCHAR(40) NULL AFTER audience, ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER source_type, ADD COLUMN link VARCHAR(190) NULL AFTER source_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'notifications'
                     AND COLUMN_NAME = 'category'
       );

PREPARE add_notification_metadata_stmt
FROM
       @add_notification_metadata;

EXECUTE add_notification_metadata_stmt;

DEALLOCATE PREPARE add_notification_metadata_stmt;

SET
       @add_notification_category_index = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'CREATE INDEX idx_notification_category ON notifications(category, published_at)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'notifications'
                     AND INDEX_NAME = 'idx_notification_category'
       );

PREPARE add_notification_category_index_stmt
FROM
       @add_notification_category_index;

EXECUTE add_notification_category_index_stmt;

DEALLOCATE PREPARE add_notification_category_index_stmt;

SET
       @add_notification_source_index = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'CREATE INDEX idx_notification_source ON notifications(source_type, source_id)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'notifications'
                     AND INDEX_NAME = 'idx_notification_source'
       );

PREPARE add_notification_source_index_stmt
FROM
       @add_notification_source_index;

EXECUTE add_notification_source_index_stmt;

DEALLOCATE PREPARE add_notification_source_index_stmt;

-- An address change is confirmed with a one-time code sent to the new address, so the OTP
-- purpose list gains change_email and each code can remember the address it was issued for.
SET
       @add_otp_change_email_purpose = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE email_otps MODIFY COLUMN purpose ENUM(''verify_email'', ''reset_password'', ''change_email'') NOT NULL',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'email_otps'
                     AND COLUMN_NAME = 'purpose'
                     AND COLUMN_TYPE LIKE '%change_email%'
       );

PREPARE add_otp_change_email_purpose_stmt
FROM
       @add_otp_change_email_purpose;

EXECUTE add_otp_change_email_purpose_stmt;

DEALLOCATE PREPARE add_otp_change_email_purpose_stmt;

SET
       @add_otp_target_email = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE email_otps ADD COLUMN target_email VARCHAR(190) NULL AFTER purpose',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'email_otps'
                     AND COLUMN_NAME = 'target_email'
       );

PREPARE add_otp_target_email_stmt
FROM
       @add_otp_target_email;

EXECUTE add_otp_target_email_stmt;

DEALLOCATE PREPARE add_otp_target_email_stmt;

-- Every automated email is recorded here so delivery problems are visible in the admin
-- workspace instead of only in the PHP error log.
CREATE TABLE IF NOT EXISTS email_logs (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       template_key VARCHAR(64) NOT NULL,
       recipient_email VARCHAR(190) NOT NULL,
       subject VARCHAR(200) NULL,
       status ENUM('sent', 'failed') NOT NULL,
       error_message VARCHAR(255) NULL,
       related_user_id BIGINT UNSIGNED NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_email_logs_user FOREIGN KEY(related_user_id) REFERENCES users(id),
       INDEX idx_email_logs_created(created_at, id),
       INDEX idx_email_logs_status(status, created_at),
       INDEX idx_email_logs_template(template_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Account references: the public ID an account is quoted by.
--
-- That ID used to be derived from the primary key (TSG-STU-00004), so it could never be
-- edited and could never be the number a student verifies a certificate with. It is now a
-- stored column. Students are numbered from a scheme the admin controls on their own
-- settings page, and a certificate is issued under its owner's ID, so one number opens
-- both the account and the certificate.
-- ---------------------------------------------------------------------------
SET
       @add_user_account_reference = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE users ADD COLUMN account_reference VARCHAR(64) NULL AFTER role',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'users'
                     AND COLUMN_NAME = 'account_reference'
       );

PREPARE add_user_account_reference_stmt
FROM
       @add_user_account_reference;

EXECUTE add_user_account_reference_stmt;

DEALLOCATE PREPARE add_user_account_reference_stmt;

SET
       @add_user_reference_index = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE users ADD UNIQUE KEY uq_users_account_reference(account_reference)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'users'
                     AND INDEX_NAME = 'uq_users_account_reference'
       );

PREPARE add_user_reference_index_stmt
FROM
       @add_user_reference_index;

EXECUTE add_user_reference_index_stmt;

DEALLOCATE PREPARE add_user_reference_index_stmt;

-- A student who finishes a second course gets a second certificate under the same ID, so
-- the public ID can no longer be unique on its own. It stays indexed: it is what the
-- public verification page searches on.
SET
       @drop_certificate_public_unique = (
              SELECT
                     IF(
                            COUNT(*) > 0,
                            'ALTER TABLE certificates DROP INDEX uq_certificate_public_id',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'certificates'
                     AND INDEX_NAME = 'uq_certificate_public_id'
       );

PREPARE drop_certificate_public_unique_stmt
FROM
       @drop_certificate_public_unique;

EXECUTE drop_certificate_public_unique_stmt;

DEALLOCATE PREPARE drop_certificate_public_unique_stmt;

SET
       @add_certificate_public_index = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE certificates ADD INDEX idx_certificate_public_id(certificate_id)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.STATISTICS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'certificates'
                     AND INDEX_NAME = 'idx_certificate_public_id'
       );

PREPARE add_certificate_public_index_stmt
FROM
       @add_certificate_public_index;

EXECUTE add_certificate_public_index_stmt;

DEALLOCATE PREPARE add_certificate_public_index_stmt;

-- The scheme the admin edits on their settings page. The prefix is the branding, the
-- number is where the next student to register will start.
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('student_id_prefix', 'TSG-CER'),
       ('student_id_next_number', '1') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
(setting_key);

-- Give every account that has no ID yet a stored one. Accounts that already carry an ID are
-- never touched, so re-running this cannot renumber a student out of their own certificate.
-- The casts to UNSIGNED are load-bearing: COALESCE and NULLIF merge MariaDB's integer literals
-- into DECIMAL, and a DECIMAL sequence would print as "TSG-CER-1.0000000000000000".
-- Both backfills start clear of the numbers already handed out, and both use UPDATE IGNORE: a
-- partial earlier import leaves rows with no ID next to rows that have one, and numbering from
-- 1 in that state used to stop the whole import on '#1062 Duplicate entry'. A row that still
-- collides with a hand-typed ID is left for AccountIdentity::ensure() instead of failing the file.
SET
       @student_id_prefix = COALESCE(
              NULLIF(
                     (
                            SELECT
                                   setting_value
                            FROM
                                   app_settings
                            WHERE
                                   setting_key = 'student_id_prefix'
                     ),
                     ''
              ),
              'TSG-CER'
       );

SET
       @student_id_base = CAST(
              GREATEST(
                     COALESCE(
                            NULLIF(
                                   (
                                          SELECT
                                                 CAST(setting_value AS UNSIGNED)
                                          FROM
                                                 app_settings
                                          WHERE
                                                 setting_key = 'student_id_next_number'
                                   ),
                                   0
                            ),
                            1
                     ),
                     COALESCE(
                            (
                                   SELECT
                                          MAX(
                                                 CAST(
                                                        SUBSTRING_INDEX(account_reference, '-', -1) AS UNSIGNED
                                                 )
                                          )
                                   FROM
                                          users
                                   WHERE
                                          role = 'student'
                                          AND account_reference IS NOT NULL
                            ),
                            0
                     ) + 1
              ) AS UNSIGNED
       );

UPDATE
       IGNORE users u
       JOIN (
              SELECT
                     id,
                     ROW_NUMBER() OVER (
                            ORDER BY
                                   id
                     ) AS sequence_step
              FROM
                     users
              WHERE
                     account_reference IS NULL
                     AND role = 'student'
       ) numbered ON numbered.id = u.id
SET
       u.account_reference = CONCAT(
              @student_id_prefix,
              '-',
              LPAD(
                     CAST(
                            @student_id_base + numbered.sequence_step - 1 AS UNSIGNED
                     ),
                     GREATEST(
                            3,
                            CHAR_LENGTH(
                                   CAST(
                                          CAST(
                                                 @student_id_base + numbered.sequence_step - 1 AS UNSIGNED
                                          ) AS CHAR
                                   )
                            )
                     ),
                     '0'
              )
       );

-- Admins keep the format they were already quoted by in receipts and emails. Their numbers are
-- not driven by a setting, so the highest ID already printed on an admin account is the floor.
SET
       @admin_id_base = CAST(
              COALESCE(
                     (
                            SELECT
                                   MAX(
                                          CAST(
                                                 SUBSTRING_INDEX(account_reference, '-', -1) AS UNSIGNED
                                          )
                                   )
                            FROM
                                   users
                            WHERE
                                   role = 'admin'
                                   AND account_reference IS NOT NULL
                     ),
                     0
              ) + 1 AS UNSIGNED
       );

UPDATE
       IGNORE users u
       JOIN (
              SELECT
                     id,
                     ROW_NUMBER() OVER (
                            ORDER BY
                                   id
                     ) AS sequence_step
              FROM
                     users
              WHERE
                     account_reference IS NULL
                     AND role = 'admin'
       ) numbered ON numbered.id = u.id
SET
       u.account_reference = CONCAT(
              'TSG-ADM-',
              LPAD(
                     CAST(
                            @admin_id_base + numbered.sequence_step - 1 AS UNSIGNED
                     ),
                     5,
                     '0'
              )
       );

-- The counter has to move past whatever this backfill just handed out, otherwise the next
-- registration would collide with an ID that is already taken.
SET
       @highest_student_sequence = CAST(
              COALESCE(
                     (
                            SELECT
                                   MAX(
                                          CAST(
                                                 SUBSTRING_INDEX(account_reference, '-', -1) AS UNSIGNED
                                          )
                                   )
                            FROM
                                   users
                            WHERE
                                   role = 'student'
                                   AND account_reference IS NOT NULL
                     ),
                     0
              ) AS UNSIGNED
       );

UPDATE
       app_settings
SET
       setting_value = CAST(
              CAST(
                     GREATEST(
                            CAST(setting_value AS UNSIGNED),
                            @highest_student_sequence + 1
                     ) AS UNSIGNED
              ) AS CHAR
       )
WHERE
       setting_key = 'student_id_next_number';

-- Nothing reads the per-certificate prefix any longer, so the row is retired rather than left
-- behind looking like a setting that still does something. Certificates already issued keep the
-- number printed on them in certificates.certificate_id, so no document is affected by this.
DELETE FROM
       app_settings
WHERE
       setting_key = 'certificate_id_prefix';

SET
       FOREIGN_KEY_CHECKS = 1;

-- Private chat is intentionally separate from Academy announcements. A notification is read-only
-- news; a chat thread is one direct student-to-Academy line.
--   student_seen_at / admin_seen_at: when each side last opened the thread, which is what the unread
--   count and the desk's waiting list measure against.
--   student_cleared_at / admin_cleared_at: when each side cleared the chat for themselves. Messages
--   written at or before that stamp are hidden from that side only, so clearing a chat can never
--   destroy the copy the other side still reads, and the next message brings the thread back.
CREATE TABLE IF NOT EXISTS chat_threads (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       student_seen_at DATETIME NULL,
       admin_seen_at DATETIME NULL,
       student_cleared_at DATETIME NULL,
       admin_cleared_at DATETIME NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       UNIQUE KEY uq_chat_thread_student(student_id),
       CONSTRAINT fk_chat_thread_student FOREIGN KEY(student_id) REFERENCES users(id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       thread_id BIGINT UNSIGNED NOT NULL,
       sender_id BIGINT UNSIGNED NOT NULL,
       message TEXT NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_chat_message_thread FOREIGN KEY(thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
       CONSTRAINT fk_chat_message_sender FOREIGN KEY(sender_id) REFERENCES users(id),
       INDEX idx_chat_messages_thread(thread_id, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

/* A database that already holds conversations is not rebuilt for two columns, so the clearing stamps
 are added to it the same guarded way the rest of this schema uses. */
SET
       @add_thread_student_cleared_at = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE chat_threads ADD COLUMN student_cleared_at DATETIME NULL AFTER admin_seen_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'chat_threads'
                     AND COLUMN_NAME = 'student_cleared_at'
       );

PREPARE add_thread_student_cleared_at_stmt
FROM
       @add_thread_student_cleared_at;

EXECUTE add_thread_student_cleared_at_stmt;

DEALLOCATE PREPARE add_thread_student_cleared_at_stmt;

SET
       @add_thread_admin_cleared_at = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE chat_threads ADD COLUMN admin_cleared_at DATETIME NULL AFTER student_cleared_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'chat_threads'
                     AND COLUMN_NAME = 'admin_cleared_at'
       );

PREPARE add_thread_admin_cleared_at_stmt
FROM
       @add_thread_admin_cleared_at;

EXECUTE add_thread_admin_cleared_at_stmt;

DEALLOCATE PREPARE add_thread_admin_cleared_at_stmt;

CREATE TABLE IF NOT EXISTS dashboard_tasks (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       user_id BIGINT UNSIGNED NOT NULL,
       title VARCHAR(240) NOT NULL,
       is_done TINYINT(1) NOT NULL DEFAULT 0,
       reminder_at DATETIME NULL,
       reminder_sent_at DATETIME NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       CONSTRAINT fk_dashboard_task_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
       INDEX idx_dashboard_task_reminder(is_done, reminder_at, reminder_sent_at),
       INDEX idx_dashboard_task_owner(user_id, is_done, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A report an admin sends to a student is a promise about a period, not about the moment the
-- student opens it, so the period boundaries are stored on the share itself. Only the metadata
-- lives here: the PDF is rebuilt from these bounds when the student downloads it, which keeps
-- the document scoped to that student's own records and leaves no file to clean up afterwards.
CREATE TABLE IF NOT EXISTS report_shares (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       report_type ENUM('students', 'attendance', 'quizzes', 'assignments') NOT NULL,
       period_key ENUM('weekly', 'monthly') NOT NULL,
       period_start DATETIME NOT NULL,
       period_end DATETIME NOT NULL,
       sent_by BIGINT UNSIGNED NOT NULL,
       created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_report_share_student FOREIGN KEY(student_id) REFERENCES users(id),
       CONSTRAINT fk_report_share_sender FOREIGN KEY(sent_by) REFERENCES users(id),
       INDEX idx_report_share_student(student_id, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Video protection: one active session per account, and a log of unusual viewing.
--
-- A session is identified by a hash of its PHP session id, never by the id itself, so a
-- leaked database row cannot be used to forge a cookie. The platform records what it can
-- actually see - which machines are signed in as this account, and when the player is
-- pushed towards capture (fullscreen, picture-in-picture, download) - because a DOM
-- watermark and a session registry are deterrents and traces, not DRM.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       user_id BIGINT UNSIGNED NOT NULL,
       session_hash CHAR(64) NOT NULL,
       ip_address VARCHAR(45) NULL,
       user_agent_hash CHAR(64) NULL,
       created_at DATETIME NOT NULL,
       last_seen_at DATETIME NOT NULL,
       revoked_at DATETIME NULL,
       revoked_reason ENUM(
              'logout',
              'password_change',
              'superseded',
              'admin_revoked',
              'idle'
       ) NULL,
       UNIQUE KEY uq_user_session_hash(session_hash),
       CONSTRAINT fk_user_session_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
       INDEX idx_user_session_active(user_id, revoked_at, last_seen_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Player-side protection events for one lesson and one student. Watch time is NOT decided
-- from these rows: LessonMedia keeps sole authority over completion, so a blocked download
-- attempt can never silently change a student's grade. A sign-in anomaly belongs to the
-- account rather than to a recording, so the lesson reference is nullable and the desk prints
-- those rows as "Account session" instead of inventing a lesson for them.
CREATE TABLE IF NOT EXISTS lesson_view_events (
       id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       student_id BIGINT UNSIGNED NOT NULL,
       lesson_id BIGINT UNSIGNED NULL,
       event_type ENUM(
              'session_cap',
              'concurrent_ip',
              'fullscreen_blocked',
              'pip_blocked',
              'download_blocked',
              'seek_ahead',
              'context_menu'
       ) NOT NULL,
       ip_address VARCHAR(45) NULL,
       detail VARCHAR(255) NULL,
       created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
       CONSTRAINT fk_view_event_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE,
       CONSTRAINT fk_view_event_lesson FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
       INDEX idx_view_event_created(created_at, id),
       INDEX idx_view_event_student(student_id, created_at),
       INDEX idx_view_event_lesson(lesson_id, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Seeded once and never overwritten by a re-import, so an admin's own values survive.
-- session_max_active is the literal company rule (one active session) at its default of 1;
-- it is a setting rather than a constant because a student with a phone and a laptop is a
-- real classroom fact, and the Academy has to be able to answer it without a code change.
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('session_max_active', '1'),
       ('video_watermark_enabled', '1'),
       ('lesson_view_retention_days', '30') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
       (setting_key);

-- ---------------------------------------------------------------------------
-- Certificate ID format and signed-file format (v3.1 update).
--
-- A certificate keeps being issued under its owner's account ID, so one permanent Student ID
-- still opens every certificate behind it. The account ID gains an optional year segment the
-- admin can switch on from the Certificate settings page: when on, a newly minted ID reads
-- PREFIX-YYYY-SERIAL (TSG-CER-2026-001); when off it reads the original PREFIX-SERIAL. The
-- setting is seeded OFF and forward-only, so no existing student ID or issued certificate is
-- ever rewritten by importing this file.
--
-- The signed document an admin attaches is no longer JPG-only: the stored extension is recorded
-- so the download and verification pages can hand back the same format that was uploaded.
-- ---------------------------------------------------------------------------
SET
       @add_certificate_file_type = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE certificates ADD COLUMN file_type VARCHAR(8) NULL AFTER file_path',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'certificates'
                     AND COLUMN_NAME = 'file_type'
       );

PREPARE add_certificate_file_type_stmt
FROM
       @add_certificate_file_type;

EXECUTE add_certificate_file_type_stmt;

DEALLOCATE PREPARE add_certificate_file_type_stmt;

-- Certificate revocation is retained in the register and invalidated by the status on every reader.
SET
       @add_certificate_revocation = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE certificates MODIFY status ENUM(''requested'',''issued'',''rejected'',''revoked'') NOT NULL DEFAULT ''requested'', ADD COLUMN revoked_by BIGINT UNSIGNED NULL AFTER reviewed_by, ADD COLUMN revoked_at DATETIME NULL AFTER expires_at, ADD COLUMN revoked_reason VARCHAR(1000) NULL AFTER revoked_at',
                            'SELECT 1'
                     )
              FROM
                     information_schema.COLUMNS
              WHERE
                     TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'certificates'
                     AND COLUMN_NAME = 'revoked_at'
       );

PREPARE add_certificate_revocation_stmt
FROM
       @add_certificate_revocation;

EXECUTE add_certificate_revocation_stmt;

DEALLOCATE PREPARE add_certificate_revocation_stmt;

SET
       @add_certificate_revoked_by_fk = (
              SELECT
                     IF(
                            COUNT(*) = 0,
                            'ALTER TABLE certificates ADD CONSTRAINT fk_certificate_revoked_by FOREIGN KEY(revoked_by) REFERENCES users(id)',
                            'SELECT 1'
                     )
              FROM
                     information_schema.TABLE_CONSTRAINTS
              WHERE
                     CONSTRAINT_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'certificates'
                     AND CONSTRAINT_NAME = 'fk_certificate_revoked_by'
       );

PREPARE add_certificate_revoked_by_fk_stmt
FROM
       @add_certificate_revoked_by_fk;

EXECUTE add_certificate_revoked_by_fk_stmt;

DEALLOCATE PREPARE add_certificate_revoked_by_fk_stmt;

-- Seeded once and never overwritten by a re-import, so an admin's chosen format survives.
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('student_id_include_year', '0') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
(setting_key);

-- ---------------------------------------------------------------------------
-- Completion video lock (proposal 5.7).
--
-- The proposal asked that a student's lesson videos disappear as soon as the course is complete.
-- The platform's standing behaviour instead keeps recordings available until the access period
-- (expires_at) ends, so a finished student can revise. This switch lets an Academy choose the
-- completion lock rather than forcing it on every install, so the recordings-access promise is a
-- decision and not a code change. Seeded OFF - the behaviour every install has always had - and
-- forward-only: importing this file never hides a video from anybody who can watch one today.
-- ---------------------------------------------------------------------------
INSERT INTO
       app_settings(setting_key, setting_value)
VALUES
       ('video_lock_on_completion', '0') ON DUPLICATE KEY
UPDATE
       setting_key =
VALUES
(setting_key);