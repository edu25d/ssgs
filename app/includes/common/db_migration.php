<?php
/**
 * includes/common/db_migration.php
 * 무중단 자가 치유(Self-Healing) DB 스키마 마이그레이션 헬퍼
 * 
 * 쌤글라스 시스템 전반에서 필요한 신규 컬럼 부재 시
 * 웹 요청 시점에 자동 감지하여 무중단으로 생성합니다.
 */

require_once __DIR__ . '/privacy_helper.php';

if (!function_exists('ensureMultiPageSchema')) {
    function ensureMultiPageSchema(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }

        // 세션 캐싱으로 불필요한 반복 쿼리 방지 (Zero-Overhead)
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['multipage_schema_checked'])) {
            $checked = true;
            return;
        }

        try {
            // templates.pages_data 컬럼 존재 여부 검사
            $pdo->query("SELECT pages_data FROM templates LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `templates` ADD COLUMN `pages_data` TEXT NULL AFTER `image_path`");
            } catch (Exception $alterEx) {
                @error_log("[DB Migration] templates.pages_data ALTER failed: " . $alterEx->getMessage());
            }
        }

        try {
            // templates.privacy_mask_data 컬럼 존재 여부 검사
            $pdo->query("SELECT privacy_mask_data FROM templates LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `templates` ADD COLUMN `privacy_mask_data` TEXT NULL AFTER `roi_data`");
            } catch (Exception $alterEx) {
                @error_log("[DB Migration] templates.privacy_mask_data ALTER failed: " . $alterEx->getMessage());
            }
        }

        // Phase 2: users 테이블 구글 전용 모던 스키마 자가 치유
        ensureUsersSchema($pdo);

        // Phase 3: 제로-지식 학생 프라이버시 스키마 자가 치유
        ensurePhase3PrivacySchema($pdo);

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['multipage_schema_checked'] = true;
        }
        $checked = true;
    }
}

if (!function_exists('ensureUsersSchema')) {
    function ensureUsersSchema(PDO $pdo): void {
        // 1. email 컬럼 부재 시 추가
        try {
            $pdo->query("SELECT email FROM users LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `email` VARCHAR(100) NULL AFTER `name`");
                $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `uniq_user_email` (`email`)");
            } catch (Exception $alterEx) {
                @error_log("[DB Migration] users.email ALTER failed: " . $alterEx->getMessage());
            }
        }

        // 2. google_id 컬럼 부재 시 추가
        try {
            $pdo->query("SELECT google_id FROM users LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `google_id` VARCHAR(100) NULL AFTER `email`");
            } catch (Exception $alterEx) {
                @error_log("[DB Migration] users.google_id ALTER failed: " . $alterEx->getMessage());
            }
        }

        // 3. kiosk_rank 컬럼 부재 시 추가 (아이디 중복 시 키오스크 접미사 교차 생성용)
        try {
            $pdo->query("SELECT kiosk_rank FROM users LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `kiosk_rank` INT NOT NULL DEFAULT 0 AFTER `google_id`");
            } catch (Exception $alterEx) {
                @error_log("[DB Migration] users.kiosk_rank ALTER failed: " . $alterEx->getMessage());
            }
        }

        // 4. password_hash 컬럼 NULL 허용으로 완화
        try {
            $pdo->exec("ALTER TABLE `users` MODIFY `password_hash` VARCHAR(255) NULL");
        } catch (Exception $alterEx) {
            // 이미 수정되었거나 무시 가능
        }

        // 5. username의 UNIQUE 제약조건 해제 (동일 이메일 아이디 허용)
        try {
            $idxStmt = $pdo->query("SHOW INDEX FROM `users` WHERE Key_name = 'username'");
            if ($idxStmt && $idxStmt->fetch()) {
                $pdo->exec("ALTER TABLE `users` DROP INDEX `username`");
                $pdo->exec("ALTER TABLE `users` ADD INDEX `idx_username` (`username`)");
            }
        } catch (Exception $idxEx) {
            // 이미 인덱스 정리됨
        }

        // 6. school_name 컬럼 영구 삭제 (Zero-Knowledge 원칙)
        try {
            $pdo->query("SELECT school_name FROM users LIMIT 1");
            // 존재하면 삭제
            $pdo->exec("ALTER TABLE `users` DROP COLUMN `school_name`");
        } catch (Exception $e) {
            // 이미 컬럼이 없으면 정상 통과
        }

        // 7. classes.class_password 컬럼 확인 및 추가 (학급별 키오스크 비밀번호)
        try {
            $pdo->query("SELECT class_password FROM classes LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `classes` ADD COLUMN `class_password` VARCHAR(255) NULL AFTER `kiosk_code`");
            } catch (Exception $alterEx) {
                @error_log("[DB Migration] classes.class_password ALTER failed: " . $alterEx->getMessage());
            }
        }
    }
}

if (!function_exists('ensurePhase3PrivacySchema')) {
    /**
     * Phase 3: 학생 개인정보 제로-지식(Zero-Knowledge) 스키마 자가 치유
     */
    function ensurePhase3PrivacySchema(PDO $pdo): void {
        // 1. classes.class_salt 컬럼 확인 및 추가
        try {
            $pdo->query("SELECT class_salt FROM classes LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `classes` ADD COLUMN `class_salt` VARCHAR(32) NOT NULL DEFAULT '' AFTER `class_name`");
            } catch (Exception $alterEx) {
                @error_log("[DB Migration] classes.class_salt ALTER failed: " . $alterEx->getMessage());
            }
        }

        // 2. classes 테이블의 빈 솔트 자동 생성
        try {
            $emptySaltStmt = $pdo->query("SELECT id FROM classes WHERE class_salt = '' OR class_salt IS NULL");
            if ($emptySaltStmt) {
                $classesNeedSalt = $emptySaltStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($classesNeedSalt)) {
                    $upSalt = $pdo->prepare("UPDATE classes SET class_salt = :salt WHERE id = :id");
                    foreach ($classesNeedSalt as $cId) {
                        $upSalt->execute(['salt' => generateClassSalt(), 'id' => (int)$cId]);
                    }
                }
            }
        } catch (Exception $saltEx) {
            @error_log("[DB Migration] classes salt filling failed: " . $saltEx->getMessage());
        }

        // 3. students.student_hash 컬럼 확인 및 추가
        try {
            $pdo->query("SELECT student_hash FROM students LIMIT 1");
        } catch (Exception $e) {
            try {
                $pdo->exec("ALTER TABLE `students` ADD COLUMN `student_hash` VARCHAR(32) NULL AFTER `class_id`");
                
                // 기존 데이터가 있는 경우 name을 이용해 student_hash 역생성 채우기
                try {
                    $hasName = $pdo->query("SELECT name FROM students LIMIT 1");
                    if ($hasName) {
                        $migrStmt = $pdo->query("
                            SELECT s.id, s.class_id, s.name, c.class_salt 
                            FROM students s 
                            JOIN classes c ON s.class_id = c.id 
                            WHERE s.student_hash IS NULL OR s.student_hash = ''
                        ");
                        if ($migrStmt) {
                            $upHash = $pdo->prepare("UPDATE students SET student_hash = :hash WHERE id = :id");
                            while ($row = $migrStmt->fetch(PDO::FETCH_ASSOC)) {
                                $h = makeStudentHash((string)$row['class_salt'], (string)$row['name']);
                                $upHash->execute(['hash' => $h, 'id' => (int)$row['id']]);
                            }
                        }
                    }
                } catch (Exception $popEx) {
                    // 무시
                }

                // NOT NULL 제약조건 및 UNIQUE 인덱스 적용
                $pdo->exec("ALTER TABLE `students` MODIFY `student_hash` VARCHAR(32) NOT NULL");
                $pdo->exec("ALTER TABLE `students` ADD UNIQUE KEY `unique_student_hash_per_class` (`class_id`, `student_hash`)");

            } catch (Exception $alterEx) {
                @error_log("[DB Migration] students.student_hash ALTER failed: " . $alterEx->getMessage());
            }
        }

        // 4. students.name 컬럼 NULL 허용으로 완화 (Zero-Knowledge)
        try {
            $pdo->exec("ALTER TABLE `students` MODIFY `name` VARCHAR(50) NULL DEFAULT NULL");
        } catch (Exception $nameEx) {
            // 이미 수정되었거나 name 컬럼이 없음
        }
    }
}
