-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- 생성 시간: 26-10-28 19:17
-- 서버 버전: 8.0.46
-- PHP 버전: 8.2.35

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 데이터베이스: `jinsu2`
--

-- --------------------------------------------------------

--
-- 테이블 구조 `assignments`
--

CREATE TABLE `assignments` (
  `id` int NOT NULL,
  `template_id` int NOT NULL COMMENT '연동된 템플릿 ID',
  `class_id` int NOT NULL COMMENT '배정된 학급 ID',
  `exam_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assignment_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '개별/학급 접속용 고유 코드',
  `status` enum('waiting','in_progress','completed','deleted') COLLATE utf8mb4_unicode_ci DEFAULT 'waiting',
  `show_answer` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0: 모범답안 숨김(기본), 1: 학생에게 모범답안 공개',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sheet_gid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 테이블 구조 `classes`
--

CREATE TABLE `classes` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `class_name` varchar(100) NOT NULL,
  `kiosk_code` varchar(50) DEFAULT NULL,
  `class_password` varchar(255) DEFAULT NULL COMMENT '학급 입력기 계정(star-1 등) 전용 비밀번호 (해시)',
  `drive_folder_id` varchar(255) NOT NULL,
  `sheet_id` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 테이블 구조 `exam_results`
--

CREATE TABLE `exam_results` (
  `id` int NOT NULL,
  `assignment_id` int NOT NULL COMMENT '배정(assignments) ID',
  `student_id` int DEFAULT NULL,
  `total_score` int DEFAULT '0' COMMENT '총점',
  `is_reviewed` tinyint(1) DEFAULT '0' COMMENT '주관식 검수 완료 여부 (0: 대기, 1: 완료)',
  `raw_results` json DEFAULT NULL COMMENT '문항별 채점 상세 및 OCR 데이터',
  `submitted_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 테이블 구조 `kiosks`
--

CREATE TABLE `kiosks` (
  `id` int NOT NULL,
  `class_id` int NOT NULL,
  `kiosk_code` varchar(50) NOT NULL,
  `device_name` varchar(50) DEFAULT '기본 입력기',
  `is_active` tinyint DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 테이블 구조 `kiosk_accounts`
--

CREATE TABLE `kiosk_accounts` (
  `id` int NOT NULL,
  `class_id` int NOT NULL,
  `kiosk_id` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 테이블 구조 `students`
--

CREATE TABLE `students` (
  `id` int NOT NULL,
  `class_id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `pin` varchar(10) NOT NULL,
  `is_initial_pin` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `order_index` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 테이블 구조 `templates`
--

CREATE TABLE `templates` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `pages_data` text,
  `roi_data` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- 테이블 구조 `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(50) NOT NULL,
  `school_name` varchar(100) NOT NULL,
  `google_access_token` text,
  `google_refresh_token` text,
  `google_root_folder_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `google_folder_name` varchar(255) DEFAULT '쌤글라스'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- 덤프된 테이블의 인덱스
--

--
-- 테이블의 인덱스 `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `assignment_code` (`assignment_code`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `class_id` (`class_id`);

--
-- 테이블의 인덱스 `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- 테이블의 인덱스 `exam_results`
--
ALTER TABLE `exam_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `fk_exam_results_student` (`student_id`);

--
-- 테이블의 인덱스 `kiosks`
--
ALTER TABLE `kiosks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kiosk_code` (`kiosk_code`),
  ADD KEY `class_id` (`class_id`);

--
-- 테이블의 인덱스 `kiosk_accounts`
--
ALTER TABLE `kiosk_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `kiosk_id` (`kiosk_id`),
  ADD KEY `class_id` (`class_id`);

--
-- 테이블의 인덱스 `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_per_class` (`class_id`,`name`);

--
-- 테이블의 인덱스 `templates`
--
ALTER TABLE `templates`
  ADD PRIMARY KEY (`id`);

--
-- 테이블의 인덱스 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- 덤프된 테이블의 AUTO_INCREMENT
--

--
-- 테이블의 AUTO_INCREMENT `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 테이블의 AUTO_INCREMENT `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 테이블의 AUTO_INCREMENT `exam_results`
--
ALTER TABLE `exam_results`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 테이블의 AUTO_INCREMENT `kiosks`
--
ALTER TABLE `kiosks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 테이블의 AUTO_INCREMENT `kiosk_accounts`
--
ALTER TABLE `kiosk_accounts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 테이블의 AUTO_INCREMENT `students`
--
ALTER TABLE `students`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 테이블의 AUTO_INCREMENT `templates`
--
ALTER TABLE `templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 테이블의 AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- 덤프된 테이블의 제약사항
--

--
-- 테이블의 제약사항 `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- 테이블의 제약사항 `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- 테이블의 제약사항 `exam_results`
--
ALTER TABLE `exam_results`
  ADD CONSTRAINT `exam_results_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_exam_results_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE SET NULL;

--
-- 테이블의 제약사항 `kiosks`
--
ALTER TABLE `kiosks`
  ADD CONSTRAINT `kiosks_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- 테이블의 제약사항 `kiosk_accounts`
--
ALTER TABLE `kiosk_accounts`
  ADD CONSTRAINT `kiosk_accounts_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- 테이블의 제약사항 `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
