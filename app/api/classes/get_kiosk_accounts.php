<?php
/**
 * api/classes/get_kiosk_accounts.php
 * 특정 학급의 키오스크 입력기 계정 목록 및 비밀번호 설정 여부 조회
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();
$classId = (int)($_GET['class_id'] ?? 0);

if (!$classId) {
    jsonError('학급 ID가 누락되었습니다.');
}

try {
    // 학급 정보 및 비밀번호 설정 여부 확인
    $classStmt = $pdo->prepare("
        SELECT id, class_name, class_password 
        FROM classes 
        WHERE id = :class_id AND user_id = :user_id AND is_active = 1
    ");
    $classStmt->execute([
        'class_id' => $classId,
        'user_id'  => $userId
    ]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        jsonError('학급 정보를 찾을 수 없습니다.');
    }

    $stmt = $pdo->prepare("
        SELECT id, class_id, kiosk_id
        FROM kiosk_accounts
        WHERE class_id = :class_id
        ORDER BY id ASC
    ");
    $stmt->execute(['class_id' => $classId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess([
        'class_id'     => (int)$class['id'],
        'class_name'   => $class['class_name'],
        'has_password' => !empty($class['class_password']),
        'accounts'     => $accounts
    ]);
} catch (Exception $e) {
    jsonError('키오스크 계정 조회 오류: ' . $e->getMessage());
}
