-- TSG Academy LMS initial relational schema (MySQL 8 / InnoDB / utf8mb4).
-- Import into a newly created tsg_academy_lms database. Review policy decisions before production.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(150) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 role ENUM('student','admin') NOT NULL DEFAULT 'student', email_verified_at DATETIME NULL,
 status ENUM('active','pending','suspended') NOT NULL DEFAULT 'pending', last_login_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_users_role_status(role,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_otps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, purpose ENUM('verify_email','reset_password') NOT NULL,
 otp_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME NULL, attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_otps_user FOREIGN KEY(user_id) REFERENCES users(id),
 INDEX idx_otp_lookup(user_id,purpose,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS courses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL, slug VARCHAR(220) NOT NULL UNIQUE,
 short_description VARCHAR(500) NULL, description TEXT NULL, duration_label VARCHAR(100) NULL, price DECIMAL(10,2) NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'PKR', visibility ENUM('draft','published','hidden','archived') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_courses_creator FOREIGN KEY(created_by) REFERENCES users(id), INDEX idx_courses_visibility(visibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_modules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id BIGINT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_modules_course FOREIGN KEY(course_id) REFERENCES courses(id), INDEX idx_modules_course(course_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lessons (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_id BIGINT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, body MEDIUMTEXT NULL,
 resource_path VARCHAR(500) NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0, status ENUM('draft','published') NOT NULL DEFAULT 'draft',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_lessons_module FOREIGN KEY(module_id) REFERENCES course_modules(id), INDEX idx_lessons_module(module_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS student_course_access (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL, course_id BIGINT UNSIGNED NOT NULL,
 status ENUM('active','revoked') NOT NULL DEFAULT 'active', granted_by BIGINT UNSIGNED NOT NULL, granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revoked_by BIGINT UNSIGNED NULL, revoked_at DATETIME NULL, UNIQUE KEY uq_student_course(student_id,course_id),
 CONSTRAINT fk_access_student FOREIGN KEY(student_id) REFERENCES users(id), CONSTRAINT fk_access_course FOREIGN KEY(course_id) REFERENCES courses(id),
 CONSTRAINT fk_access_granted_by FOREIGN KEY(granted_by) REFERENCES users(id), CONSTRAINT fk_access_revoked_by FOREIGN KEY(revoked_by) REFERENCES users(id), INDEX idx_access_status(student_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL, course_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(10,2) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'PKR', transaction_reference VARCHAR(190) NOT NULL,
 receipt_path VARCHAR(500) NULL, status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL, review_note VARCHAR(1000) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_payments_student FOREIGN KEY(student_id) REFERENCES users(id), CONSTRAINT fk_payments_course FOREIGN KEY(course_id) REFERENCES courses(id),
 CONSTRAINT fk_payments_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id), INDEX idx_payment_review(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quizzes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id BIGINT UNSIGNED NOT NULL, week_number SMALLINT UNSIGNED NOT NULL,
 title VARCHAR(200) NOT NULL, instructions TEXT NULL, status ENUM('draft','scheduled','released','closed') NOT NULL DEFAULT 'draft',
 time_limit_seconds INT UNSIGNED NOT NULL DEFAULT 1200, question_count SMALLINT UNSIGNED NOT NULL DEFAULT 75,
 pass_mark_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00, attempt_limit TINYINT UNSIGNED NOT NULL DEFAULT 1,
 unlock_at DATETIME NULL, close_at DATETIME NULL, created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_quiz_course_week(course_id,week_number), CONSTRAINT fk_quiz_course FOREIGN KEY(course_id) REFERENCES courses(id),
 CONSTRAINT fk_quiz_creator FOREIGN KEY(created_by) REFERENCES users(id), INDEX idx_quiz_release(status,unlock_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_questions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quiz_id BIGINT UNSIGNED NOT NULL, question_text TEXT NOT NULL, marks DECIMAL(7,2) NOT NULL DEFAULT 1,
 sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_question_quiz FOREIGN KEY(quiz_id) REFERENCES quizzes(id), INDEX idx_question_quiz(quiz_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_options (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, question_id BIGINT UNSIGNED NOT NULL, option_text TEXT NOT NULL, is_correct TINYINT(1) NOT NULL DEFAULT 0,
 sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0, CONSTRAINT fk_option_question FOREIGN KEY(question_id) REFERENCES quiz_questions(id),
 INDEX idx_options_question(question_id,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, quiz_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL,
 started_at DATETIME NOT NULL, deadline_at DATETIME NOT NULL, submitted_at DATETIME NULL,
 status ENUM('in_progress','submitted','auto_submitted','timed_out') NOT NULL DEFAULT 'in_progress',
 score DECIMAL(9,2) NULL, total_marks DECIMAL(9,2) NULL, percentage DECIMAL(5,2) NULL, correct_count SMALLINT UNSIGNED NULL,
 result ENUM('pass','fail') NULL, submission_reason VARCHAR(40) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_attempt_quiz FOREIGN KEY(quiz_id) REFERENCES quizzes(id), CONSTRAINT fk_attempt_student FOREIGN KEY(student_id) REFERENCES users(id),
 INDEX idx_attempt_student(student_id,created_at), INDEX idx_attempt_deadline(status,deadline_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS quiz_answers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attempt_id BIGINT UNSIGNED NOT NULL, question_id BIGINT UNSIGNED NOT NULL,
 selected_option_id BIGINT UNSIGNED NULL, awarded_marks DECIMAL(7,2) NOT NULL DEFAULT 0, UNIQUE KEY uq_attempt_question(attempt_id,question_id),
 CONSTRAINT fk_answer_attempt FOREIGN KEY(attempt_id) REFERENCES quiz_attempts(id), CONSTRAINT fk_answer_question FOREIGN KEY(question_id) REFERENCES quiz_questions(id),
 CONSTRAINT fk_answer_option FOREIGN KEY(selected_option_id) REFERENCES quiz_options(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, course_id BIGINT UNSIGNED NOT NULL, week_number SMALLINT UNSIGNED NULL,
 title VARCHAR(200) NOT NULL, instructions TEXT NULL, due_at DATETIME NULL, max_marks DECIMAL(8,2) NOT NULL DEFAULT 100,
 status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft', created_by BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_assignment_course FOREIGN KEY(course_id) REFERENCES courses(id), CONSTRAINT fk_assignment_creator FOREIGN KEY(created_by) REFERENCES users(id), INDEX idx_assignment_course(course_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS assignment_submissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, assignment_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL,
 submission_text MEDIUMTEXT NULL, file_path VARCHAR(500) NULL, submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status ENUM('submitted','reviewed','resubmit_requested') NOT NULL DEFAULT 'submitted', marks_awarded DECIMAL(8,2) NULL,
 feedback TEXT NULL, reviewed_by BIGINT UNSIGNED NULL, reviewed_at DATETIME NULL,
 CONSTRAINT fk_submission_assignment FOREIGN KEY(assignment_id) REFERENCES assignments(id), CONSTRAINT fk_submission_student FOREIGN KEY(student_id) REFERENCES users(id),
 CONSTRAINT fk_submission_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id), INDEX idx_submission_student(student_id,submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lesson_progress (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_id BIGINT UNSIGNED NOT NULL, lesson_id BIGINT UNSIGNED NOT NULL,
 completed_at DATETIME NULL, UNIQUE KEY uq_student_lesson(student_id,lesson_id), CONSTRAINT fk_progress_student FOREIGN KEY(student_id) REFERENCES users(id),
 CONSTRAINT fk_progress_lesson FOREIGN KEY(lesson_id) REFERENCES lessons(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_by BIGINT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL,
 body TEXT NOT NULL, audience ENUM('all_students','selected_students') NOT NULL DEFAULT 'all_students', published_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_notification_creator FOREIGN KEY(created_by) REFERENCES users(id), INDEX idx_notification_published(published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS notification_recipients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, notification_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL,
 read_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_notification_student(notification_id,student_id),
 CONSTRAINT fk_recipient_notification FOREIGN KEY(notification_id) REFERENCES notifications(id), CONSTRAINT fk_recipient_student FOREIGN KEY(student_id) REFERENCES users(id), INDEX idx_recipient_unread(student_id,read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS notification_replies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, notification_id BIGINT UNSIGNED NOT NULL, student_id BIGINT UNSIGNED NOT NULL,
 message TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_reply_notification FOREIGN KEY(notification_id) REFERENCES notifications(id), CONSTRAINT fk_reply_student FOREIGN KEY(student_id) REFERENCES users(id), INDEX idx_reply_notification(notification_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, actor_user_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL,
 entity_type VARCHAR(100) NOT NULL, entity_id BIGINT UNSIGNED NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(500) NULL,
 metadata JSON NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_user_id) REFERENCES users(id), INDEX idx_audit_action(action,created_at), INDEX idx_audit_entity(entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;
