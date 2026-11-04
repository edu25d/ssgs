<?php
/**
 * api/classes/delete.php
 * 학급 비활성화(삭제) 처리 및 구글 드라이브 폴더 보관 태깅
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

// JSON 요청 또는 Fetch API 요청 감지
$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
       || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'empty');

$jsonBody = getJsonInput();
$input = !empty($jsonBody) ? $jsonBody : $_REQUEST;
$classId = (int)($input['class_id'] ?? 0);

if (!$classId) {
    if ($isJson) {
        jsonError('삭제할 학급 ID가 전달되지 않았습니다.');
    }
    echo "<script>alert('삭제할 학급 ID가 전달되지 않았습니다.'); history.back();</script>";
    exit;
}

try {
    // 0. 삭제 전 학급 정보(이름, 구글 드라이브 폴더ID, 시트ID) 조회
    $infoStmt = $pdo->prepare("SELECT class_name, drive_folder_id, sheet_id FROM classes WHERE id = :id AND user_id = :u");
    $infoStmt->execute(['id' => $classId, 'u' => $userId]);
    $classRow = $infoStmt->fetch(PDO::FETCH_ASSOC);

    // 1. 구글 드라이브 보관 처리: 폴더/시트 이름에 '[삭제됨_YYMMDD]' 태깅 (혼동 방지 및 데이터 보존)
    if ($classRow) {
        $token = getValidAccessToken($pdo, $userId);
        if ($token) {
            $dateTag = date('ymd');
            $archivedName = "[삭제됨_{$dateTag}] " . $classRow['class_name'];

            if (!empty($classRow['drive_folder_id'])) {
                @renameDriveItem($token, $classRow['drive_folder_id'], $archivedName);
            }
            if (!empty($classRow['sheet_id'])) {
                @renameDriveItem($token, $classRow['sheet_id'], $archivedName . " (성적마감)");
            }
        }
    }

    // 2. 해당 학급 비활성화 (is_active = 0)
    $stmt = $pdo->prepare("UPDATE classes SET is_active = 0 WHERE id = :id AND user_id = :u");
    $stmt->execute(['id' => $classId, 'u' => $userId]);

    // 3. 소속 학생 계정 비활성화
    $sStmt = $pdo->prepare("UPDATE students SET is_active = 0 WHERE class_id = :cid");
    $sStmt->execute(['cid' => $classId]);

    // 4. 연관된 키오스크 입력기 계정 정리 (kiosk_accounts 테이블은 is_active 컬럼이 없으므로 DELETE)
    $kStmt = $pdo->prepare("DELETE FROM kiosk_accounts WHERE class_id = :cid");
    $kStmt->execute(['cid' => $classId]);

    if ($isJson) {
        jsonSuccess(['class_id' => $classId], '학급이 삭제되었습니다. 구글 드라이브의 기존 폴더는 보관 상태([삭제됨])로 안전하게 변경되었습니다.');
    }

    header("Location: /views/dashboard/main.php");
    exit;
} catch (Exception $e) {
    if ($isJson) {
        jsonError('삭제 중 오류 발생: ' . $e->getMessage());
    }
    echo "<script>alert('삭제 중 오류: " . addslashes($e->getMessage()) . "'); history.back();</script>";
    exit;
}
