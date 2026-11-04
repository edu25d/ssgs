<?php
/**
 * api/auth/verify_student_pin.php
 * 학급 키오스크 또는 학생 모드에서 학생 PIN 검증 및 세션 생성
 * (Phase 3 제로-지식: student_id 또는 student_hash 기반 인증)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('잘못된 요청 방식입니다.', 405);
}

$input       = getJsonInput();
$studentId   = isset($input['student_id']) ? (int)$input['student_id'] : null;
$studentHash = trim($input['student_hash'] ?? '');
$studentName = trim($input['student_name'] ?? '학생');
$pin         = trim($input['pin'] ?? '');

$classId = (int)($_SESSION['kiosk_class_id'] ?? ($_SESSION['class_id'] ?? 0));
if (!$classId) {
    jsonError('유효하지 않은 학급 세션입니다.', 403);
}

if (empty($pin)) {
    jsonError('비밀번호를 입력해주세요.');
}

if (!$studentId && empty($studentHash)) {
    jsonError('학생 식별 정보(student_id 또는 student_hash)가 누락되었습니다.');
}

if ($studentId) {
    $stmt = $pdo->prepare("
        SELECT id, student_hash, pin, is_initial_pin, class_id
        FROM students 
        WHERE class_id = :cid AND id = :sid AND is_active = 1
    ");
    $stmt->execute(['cid' => $classId, 'sid' => $studentId]);
} else {
    $stmt = $pdo->prepare("
        SELECT id, student_hash, pin, is_initial_pin, class_id
        FROM students 
        WHERE class_id = :cid AND student_hash = :hash AND is_active = 1
    ");
    $stmt->execute(['cid' => $classId, 'hash' => $studentHash]);
}

$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    jsonError('학생 정보를 찾을 수 없습니다.');
}

if ($student['pin'] !== $pin) {
    jsonError('비밀번호가 일치하지 않습니다.');
}

// 학생 세션 등록 (메모리 실명은 전달된 값 사용, DB 미저장)
$_SESSION['student_id']           = (int)$student['id'];
$_SESSION['student_hash']         = $student['student_hash'];
$_SESSION['student_name']         = $studentName;
$_SESSION['class_id']             = (int)$student['class_id'];
$_SESSION['is_student_logged_in'] = true;
$_SESSION['role']                 = 'student';

jsonSuccess([
    'message'      => '인증 성공',
    'student_id'   => (int)$student['id'],
    'student_hash' => $student['student_hash'],
    'student_name' => $studentName,
    'is_initial'   => (int)$student['is_initial_pin'] === 1
]);
