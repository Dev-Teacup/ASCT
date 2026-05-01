CREATE DATABASE IF NOT EXISTS `asct`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `asct`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `user_passkeys`;
DROP TABLE IF EXISTS `login_email_challenges`;
DROP TABLE IF EXISTS `student_signup_email_challenges`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
  `status` ENUM('pending','active') NOT NULL DEFAULT 'active',
  `requested_student_id` VARCHAR(50) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_requested_student_id_unique` (`requested_student_id`),
  KEY `users_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_passkeys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `credential_id` VARBINARY(1024) NOT NULL,
  `public_key_cose` BLOB NOT NULL,
  `sign_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `label` VARCHAR(120) NOT NULL DEFAULT 'Passkey',
  `last_used_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_passkeys_credential_unique` (`credential_id`),
  KEY `user_passkeys_user_id_index` (`user_id`),
  CONSTRAINT `user_passkeys_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_email_challenges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `challenge_token_hash` CHAR(64) NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `resend_available_at` DATETIME NOT NULL,
  `consumed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login_email_challenges_token_unique` (`challenge_token_hash`),
  KEY `login_email_challenges_user_id_index` (`user_id`),
  CONSTRAINT `login_email_challenges_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_signup_email_challenges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `challenge_token_hash` CHAR(64) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `resend_available_at` DATETIME NOT NULL,
  `consumed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_signup_email_challenges_token_unique` (`challenge_token_hash`),
  KEY `student_signup_email_challenges_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` INT UNSIGNED NULL,
  `actor_name` VARCHAR(120) NOT NULL,
  `actor_email` VARCHAR(190) NOT NULL,
  `actor_role` VARCHAR(30) NOT NULL,
  `action` VARCHAR(80) NOT NULL,
  `target_type` VARCHAR(50) NOT NULL,
  `target_id` INT UNSIGNED NULL,
  `target_label` VARCHAR(255) NOT NULL,
  `metadata` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `audit_logs_actor_user_id_index` (`actor_user_id`),
  KEY `audit_logs_created_at_index` (`created_at`),
  KEY `audit_logs_action_index` (`action`),
  CONSTRAINT `audit_logs_actor_user_id_foreign`
    FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `student_id` VARCHAR(50) NOT NULL,
  `first_name` VARCHAR(80) NOT NULL,
  `last_name` VARCHAR(80) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(40) NOT NULL,
  `address` VARCHAR(255) NULL,
  `course` VARCHAR(120) NOT NULL,
  `year_level` TINYINT UNSIGNED NOT NULL,
  `birthdate` DATE NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_student_id_unique` (`student_id`),
  UNIQUE KEY `students_email_unique` (`email`),
  KEY `students_user_id_index` (`user_id`),
  CONSTRAINT `students_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `status`, `requested_student_id`) VALUES
(1, 'ASCT Registrar', 'admin@asct.edu.ph', '$2y$10$8VZ/.efbf9Xo2VCTerIh8.HMJiowiduDE1HZ5N2pGLih969QZwuse', 'admin', 'active', NULL),
(2, 'Faculty Adviser', 'teacher@asct.edu.ph', '$2y$10$5lkiPhgeYMuzL94bk.HwUOxDQW0ywZJpRxEXFxm0yEjNNE8wTzUU6', 'teacher', 'active', NULL),
(3, 'Student User', 'student@asct.edu.ph', '$2y$10$sxr78SrEeQdffn3y4FWL6.AFBcoJFZUciDCG8muDAwB7Zp/F4/qay', 'student', 'active', NULL),
(4, 'David Park', 'david.park@asct.edu.ph', '$2y$10$3lIGo5UC9oJPoGIcVM1dxO/bDXAHjntjw3rWfXfyEyfeR1QB.1bX2', 'teacher', 'active', NULL),
(5, 'Sarah Mitchell', 'sarah.m@asct.edu.ph', '$2y$10$34OVn11ZYLBKw.F6sPYMF.4e/xi4KZ44TeTVUt/R6yAjvgclcif1a', 'admin', 'active', NULL);

INSERT INTO `students` (`id`, `user_id`, `student_id`, `first_name`, `last_name`, `email`, `phone`, `address`, `course`, `year_level`, `birthdate`, `status`, `deleted_at`) VALUES
(1, 3, 'ASCT-2024-001', 'Student', 'User', 'student@asct.edu.ph', '555-0101', 'Baler, Aurora', 'Computer Science', 3, '2002-05-15', 'active', NULL),
(2, NULL, 'ASCT-2024-002', 'Sarah', 'Chen', 'sarah.chen@asct.edu.ph', '555-0102', 'Baler, Aurora', 'Engineering', 2, '2003-08-22', 'active', NULL),
(3, NULL, 'ASCT-2024-003', 'Marcus', 'Williams', 'marcus.w@asct.edu.ph', '555-0103', 'Maria Aurora, Aurora', 'Business Administration', 4, '2001-01-10', 'active', NULL),
(4, NULL, 'ASCT-2024-004', 'Aisha', 'Patel', 'aisha.p@asct.edu.ph', '555-0104', 'San Luis, Aurora', 'Computer Science', 1, '2004-11-30', 'active', NULL),
(5, NULL, 'ASCT-2024-005', 'Tyler', 'Brooks', 'tyler.b@asct.edu.ph', '555-0105', 'Dipaculao, Aurora', 'Engineering', 3, '2002-07-08', 'inactive', '2024-03-15 10:30:00'),
(6, NULL, 'ASCT-2024-006', 'Luna', 'Vasquez', 'luna.v@asct.edu.ph', '555-0106', 'Dingalan, Aurora', 'Business Administration', 2, '2003-04-18', 'active', NULL),
(7, NULL, 'ASCT-2024-007', 'Derek', 'Kim', 'derek.k@asct.edu.ph', '555-0107', 'Baler, Aurora', 'Computer Science', 4, '2001-09-25', 'active', NULL),
(8, NULL, 'ASCT-2024-008', 'Priya', 'Sharma', 'priya.s@asct.edu.ph', '555-0108', 'Maria Aurora, Aurora', 'Engineering', 1, '2004-02-14', 'active', NULL),
(9, NULL, 'ASCT-2024-009', 'Ryan', 'O''Connor', 'ryan.o@asct.edu.ph', '555-0109', 'San Luis, Aurora', 'Business Administration', 3, '2002-12-05', 'inactive', '2024-01-20 14:45:00'),
(10, NULL, 'ASCT-2024-010', 'Mia', 'Thompson', 'mia.t@asct.edu.ph', '555-0110', 'Dipaculao, Aurora', 'Computer Science', 2, '2003-06-20', 'active', NULL),
(11, NULL, 'ASCT-2024-011', 'Carlos', 'Reyes', 'carlos.r@asct.edu.ph', '555-0111', 'Dingalan, Aurora', 'Engineering', 4, '2001-03-12', 'active', NULL),
(12, NULL, 'ASCT-2024-012', 'Zara', 'Ahmed', 'zara.a@asct.edu.ph', '555-0112', 'Baler, Aurora', 'Business Administration', 1, '2004-10-08', 'active', NULL);

ALTER TABLE `users` AUTO_INCREMENT = 6;
ALTER TABLE `students` AUTO_INCREMENT = 13;
