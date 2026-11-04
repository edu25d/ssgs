-- ====================================================================
-- 쌤글라스(SsamGlass) Phase 2 모던 스키마 & 데이터 클린 리셋 SQL
-- ====================================================================
-- 주의: 이 스크립트를 실행하면 기존의 모든 테스트 계정, 학급, 평가 데이터가 
--       깨끗하게 비워지고(TRUNCATE), Phase 2 공식 교육청 계정 규격으로 초기화됩니다.
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. 기존 데이터 테이블 초기화
DROP TABLE IF EXISTS `exam_results`;
DROP TABLE IF EXISTS `assignments`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `kiosk_accounts`;
DROP TABLE IF EXISTS `kiosks`;
DROP TABLE IF EXISTS `classes`;
DROP TABLE IF EXISTS `templates`;
DROP TABLE IF EXISTS `users`;

-- --------------------------------------------------------
-- 테이블 구조 `users` (Phase 2 모던 구글 전용 스키마)
-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '이메일 아이디 기반 키오스크 접두사',
  `name` varchar(50) NOT NULL COMMENT '교사 성명 (구글 프로필 연동)',
  `email` varchar(100) NOT NULL COMMENT '교육청 공식 구글 이메일 (SSOT 고유키)',
  `google_id` varchar(100) DEFAULT NULL COMMENT '구글 고유 sub 식별자',
  `kiosk_rank` int NOT NULL DEFAULT '0' COMMENT '아이디 중복 시 키오스크 접미사 교차 생성 순번',
  `password_hash` varchar(255) DEFAULT NULL COMMENT '구글 전용 계정이므로 NULL 허용',
  `google_access_token` text,
  `google_refresh_token` text,
  `google_root_folder_id` varchar(255) DEFAULT NULL,
  `google_folder_name` varchar(255) DEFAULT '쌤글라스',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_email` (`email`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 테이블 구조 `templates` (다쪽 및 인적사항 마스킹 탑재)
-- --------------------------------------------------------
CREATE TABLE `templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `pages_data` text,
  `roi_data` text,
  `privacy_mask_data` text COMMENT 'Upstage 감지 인적사항 단색 마스킹 좌표',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `templates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 테이블 구조 `classes`
-- --------------------------------------------------------
CREATE TABLE `classes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `kiosk_code` varchar(50) DEFAULT NULL,
  `class_password` varchar(255) DEFAULT NULL COMMENT '학급 키오스크 접속 전용 비밀번호 (해시)',
  `drive_folder_id` varchar(255) DEFAULT NULL,
  `sheet_id` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 테이블 구조 `kiosk_accounts` (학급별 고유 키오스크 ID)
-- --------------------------------------------------------
CREATE TABLE `kiosk_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `kiosk_id` varchar(50) NOT NULL COMMENT 'test-1, test-a, test--1 순차 교차 고유 식별자',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kiosk_id` (`kiosk_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `kiosk_accounts_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 테이블 구조 `students`
-- --------------------------------------------------------
CREATE TABLE `students` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class_id` int NOT NULL,
  `pin` varchar(10) NOT NULL,
  `is_initial_pin` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `login_fail_count` int NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `order_index` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 테이블 구조 `assignments`
-- --------------------------------------------------------
CREATE TABLE `assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_id` int NOT NULL,
  `class_id` int NOT NULL,
  `exam_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assignment_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('waiting','in_progress','completed','deleted') COLLATE utf8mb4_unicode_ci DEFAULT 'waiting',
  `show_answer` tinyint(1) NOT NULL DEFAULT '0',
  `sheet_gid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assignment_code` (`assignment_code`),
  KEY `template_id` (`template_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 테이블 구조 `exam_results`
-- --------------------------------------------------------
CREATE TABLE `exam_results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `assignment_id` int NOT NULL,
  `student_id` int DEFAULT NULL,
  `total_score` int DEFAULT '0',
  `is_reviewed` tinyint(1) DEFAULT '0',
  `raw_results` json DEFAULT NULL,
  `submitted_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `assignment_id` (`assignment_id`),
  KEY `fk_exam_results_student` (`student_id`),
  CONSTRAINT `exam_results_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exam_results_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
