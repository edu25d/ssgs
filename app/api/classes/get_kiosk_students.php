<?php
/**
 * api/classes/get_kiosk_students.php
 * 학급 키오스크 화면 전용 실시간 학생 명단 조회 프록시 엔드포인트
 * 
 * 교사의 공식 구글 시트(SSOT)에서 실명을 실시간 메모리 중계(In-Memory Transit)하여
 * 키오스크 브라우저에만 전달하며, 서버 DB/디스크에는 실명을 0% 보존합니다.
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

// 키오스크 세션 검증
$classId = requireKioskAuth();

try {
    $stmt = $pdo->prepare("SELECT id, user_id, class_name, sheet_id, class_salt FROM classes WHERE id = :id AND is_active = 1");
    $stmt->execute(['id' => $classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        jsonError('학급 정보를 찾을 수 없습니다.', 404);
    }

    $ownerUserId = (int)$class['user_id'];
    $sheetId     = $class['sheet_id'];
    $classSalt   = !empty($class['class_salt']) ? $class['class_salt'] : getOrCreateClassSalt($pdo, $classId);

    // DB에 등록된 활성 학생 가명 해시 목록 조회
    $dbStmt = $pdo->prepare("SELECT id, student_hash FROM students WHERE class_id = :cid AND is_active = 1");
    $dbStmt->execute(['cid' => $classId]);
    $dbStudents = $dbStmt->fetchAll(PDO::FETCH_ASSOC);

    $hashMap = []; // hash => student_id
    foreach ($dbStudents as $ds) {
        $hashMap[$ds['student_hash']] = (int)$ds['id'];
    }

    $studentList = [];

    // 1. 구글 시트가 연동되어 있는 경우 구글 시트에서 학생 명단 실시간 조회 (SSOT)
    if (!empty($sheetId)) {
        $token = getValidAccessToken($pdo, $ownerUserId);
        if ($token) {
            $url = "https://sheets.googleapis.com/v4/spreadsheets/" . urlencode($sheetId) . "/values/A5:B55";
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

                foreach ($values as $row) {
                    $name = trim($row[0] ?? '');
                    if (empty($name)) continue;

                    $hash = makeStudentHash($classSalt, $name);
                    $studentId = $hashMap[$hash] ?? null;

                    $studentList[] = [
                        'name'         => $name,
                        'student_hash' => $hash,
                        'student_id'   => $studentId
                    ];
                }
            }
        }
    }

    // 2. 만약 시트 연동 전이거나 시트 데이터가 없는 경우, DB 가명 번호로 폴백
    if (empty($studentList) && !empty($dbStudents)) {
        $idx = 1;
        foreach ($dbStudents as $ds) {
            $studentList[] = [
                'name'         => "학생 {$idx}",
                'student_hash' => $ds['student_hash'],
                'student_id'   => (int)$ds['id']
            ];
            $idx++;
        }
    }

    jsonSuccess([
        'class_id'   => $classId,
        'class_salt' => $classSalt,
        'students'   => $studentList
    ]);

} catch (Exception $e) {
    jsonError('키오스크 학생 명단 조회 실패: ' . $e->getMessage());
}
