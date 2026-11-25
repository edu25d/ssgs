<?php
/**
 * api/classes/set_kiosk_password.php
 * 특정 학급의 키오스크 단말기 접속/종료 공통 비밀번호 설정 및 변경
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$input = $isJson ? getJsonInput() : $_POST;

$classId  = (int)($input['class_id'] ?? 0);
$password = trim((string)($input['password'] ?? ''));

if (!$classId) {
    jsonError('학급 ID가 누락되었습니다.');
}

if (mb_strlen($password) < 4) {
    jsonError('키오스크 비밀번호는 4자리 이상으로 설정해 주세요.');
}

try {
    // 본인 학급 확인
    $stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE id = :id AND user_id = :uid AND is_active = 1");
    $stmt->execute(['id' => $classId, 'uid' => $userId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        jsonError('학급 정보를 찾을 수 없습니다.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $upStmt = $pdo->prepare("UPDATE classes SET class_password = :pw WHERE id = :id AND user_id = :uid");
    $upStmt->execute([
        'pw'  => $hash,
        'id'  => $classId,
        'uid' => $userId
    ]);

    jsonSuccess([
        'class_id'   => $classId,
        'class_name' => $class['class_name']
    ], '학급 키오스크 비밀번호가 성공적으로 저장되었습니다.');

} catch (Exception $e) {
    jsonError('비밀번호 저장 중 오류가 발생했습니다: ' . $e->getMessage());
}
