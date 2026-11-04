<?php
/**
 * includes/common/privacy_helper.php
 * 쌤글라스(SsamGlass) 제로-지식(Zero-Knowledge) 학생 프라이버시 헬퍼 모듈
 */

if (!function_exists('makeStudentHash')) {
    /**
     * 학급 솔트와 학생 이름을 결합하여 표준 32자리 student_hash 반환
     *
     * @param string $classSalt
     * @param string $studentName
     * @return string
     */
    function makeStudentHash(string $classSalt, string $studentName): string {
        $cleanSalt = trim($classSalt);
        $cleanName = trim($studentName);
        return substr(hash('sha256', $cleanSalt . '_' . $cleanName), 0, 32);
    }
}

if (!function_exists('generateClassSalt')) {
    /**
     * 32자리 영문/숫자 난수 학급 솔트 생성
     *
     * @return string
     */
    function generateClassSalt(): string {
        return bin2hex(random_bytes(16));
    }
}

if (!function_exists('getOrCreateClassSalt')) {
    /**
     * 특정 학급의 class_salt를 조회하고, 부재 시 자동 생성하여 저장
     *
     * @param PDO $pdo
     * @param int $classId
     * @return string
     */
    function getOrCreateClassSalt(PDO $pdo, int $classId): string {
        $stmt = $pdo->prepare("SELECT class_salt FROM classes WHERE id = :id");
        $stmt->execute(['id' => $classId]);
        $salt = (string)$stmt->fetchColumn();

        if (empty($salt)) {
            $salt = generateClassSalt();
            $up = $pdo->prepare("UPDATE classes SET class_salt = :salt WHERE id = :id");
            $up->execute(['salt' => $salt, 'id' => $classId]);
        }

        return $salt;
    }
}
