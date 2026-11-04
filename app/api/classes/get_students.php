<?php
/**
 * api/classes/get_students.php
 * 특정 학급의 등록된 학생 목록 조회 API (교사 전용)
 * 
 * 구글 시트(SSOT) 연동 시 시트에서 최신 실명 명단을 실시간 조회하고,
 * DB의 가명 student_hash와 안전하게 매핑하여 교사 브라우저에 반환합니다.
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId  = requireTeacherAuth();
$classId = (int)($_GET['class_id'] ?? 0);

if (!$classId) {
    jsonError('학급 식별자가 누락되었습니다.');
}

// 교사 소유 학급인지 검증
$classStmt = $pdo->prepare("SELECT id, class_name, sheet_id, class_salt FROM classes WHERE id = :cid AND user_id = :uid AND is_active = 1");
$classStmt->execute(['cid' => $classId, 'uid' => $userId]);
$class = $classStmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    jsonError('해당 학급을 찾을 수 없거나 접근 권한이 없습니다.', 403);
}

$classSalt = !empty($class['class_salt']) ? $class['class_salt'] : getOrCreateClassSalt($pdo, $classId);

// DB 활성 학생 가명 레코드 조회
$stmt = $pdo->prepare("
    SELECT id, student_hash, pin, is_initial_pin
    FROM students
    WHERE class_id = :cid AND is_active = 1
    ORDER BY id ASC
");
$stmt->execute(['cid' => $classId]);
$dbStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hashMap = [];
foreach ($dbStudents as $ds) {
    $hashMap[$ds['student_hash']] = $ds;
}

$students = [];

// 구글 시트에서 실명 명단 조회 (SSOT)
if (!empty($class['sheet_id'])) {
    $token = getValidAccessToken($pdo, $userId);
    if ($token) {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/" . urlencode($class['sheet_id']) . "/values/A5:B55";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $sheetData = json_decode($response, true);
            $values = $sheetData['values'] ?? [];

            $idx = 1;
            foreach ($values as $row) {
                $name = trim($row[0] ?? '');
                $pin  = trim($row[1] ?? '0000');
                if (empty($name)) continue;

                $hash = makeStudentHash($classSalt, $name);
                $dbRow = $hashMap[$hash] ?? null;

                $students[] = [
                    'id'             => $dbRow ? (int)$dbRow['id'] : null,
                    'order_index'    => $idx,
                    'name'           => $name,
                    'student_hash'   => $hash,
                    'pin'            => $dbRow ? $dbRow['pin'] : $pin,
                    'is_initial_pin' => $dbRow ? (int)$dbRow['is_initial_pin'] : 1
                ];
                $idx++;
            }
        }
    }
}

// 시트 명단이 없는 경우 DB 기반 폴백
if (empty($students) && !empty($dbStudents)) {
    $idx = 1;
    foreach ($dbStudents as $ds) {
        $students[] = [
            'id'             => (int)$ds['id'],
            'order_index'    => $idx,
            'name'           => "학생 {$idx}",
            'student_hash'   => $ds['student_hash'],
            'pin'            => $ds['pin'],
            'is_initial_pin' => (int)$ds['is_initial_pin']
        ];
        $idx++;
    }
}

jsonSuccess([
    'class_id'      => $class['id'],
    'class_name'    => $class['class_name'],
    'class_salt'    => $classSalt,
    'sheet_id'      => $class['sheet_id'],
    'student_count' => count($students),
    'students'      => $students
]);
