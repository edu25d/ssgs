<?php
/**
 * api/classes/sync_students.php
 * 웹 대시보드 간편 등록기 및 구글 시트 명단 동기화 통합 엔드포인트
 * (Phase 3 제로-지식 학생 프라이버시: 실명 DB 배제 & student_hash 저장)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$data = getJsonInput();
$classId  = (int)($data['class_id'] ?? 0);
$sheetId  = $data['sheet_id'] ?? null;
$students = $data['students'] ?? [];

if (empty($students)) {
    jsonError('학생 명단 데이터가 비어 있습니다.');
}

try {
    // 1. 학급 정보 식별
    if ($classId > 0) {
        $stmt = $pdo->prepare("SELECT id, user_id, class_name, sheet_id, class_salt FROM classes WHERE id = :id AND is_active = 1");
        $stmt->execute(['id' => $classId]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($sheetId)) {
        $stmt = $pdo->prepare("SELECT id, user_id, class_name, sheet_id, class_salt FROM classes WHERE sheet_id = :sheet_id AND is_active = 1");
        $stmt->execute(['sheet_id' => $sheetId]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        jsonError('학급 식별자(class_id 또는 sheet_id)가 필요합니다.');
    }

    if (!$class) {
        jsonError('해당 학급을 서버에서 찾을 수 없습니다.', 404);
    }

    $targetClassId = (int)$class['id'];
    $ownerUserId   = (int)$class['user_id'];
    $targetSheetId = $class['sheet_id'];

    // 학급 고유 솔트 확보 (부재 시 자동 발급)
    $classSalt = !empty($class['class_salt']) ? $class['class_salt'] : getOrCreateClassSalt($pdo, $targetClassId);

    // 2. 동명이인(중복 이름) 엄격 검증
    $cleanList = [];
    $seenNames = [];
    foreach ($students as $s) {
        $name = trim($s['name'] ?? '');
        $pin  = trim($s['pin'] ?? ($s['password'] ?? '0000'));
        if (empty($name)) continue;

        if (in_array($name, $seenNames, true)) {
            jsonError("중복된 학생 이름('{$name}')이 존재합니다. 이름 뒤에 번호나 알파벳을 붙여 구분해주세요.");
        }
        $seenNames[] = $name;
        $cleanList[] = [
            'name' => $name,
            'pin'  => !empty($pin) ? $pin : '0000'
        ];
    }

    if (empty($cleanList)) {
        jsonError('유효한 학생 이름이 없습니다.');
    }

    $pdo->beginTransaction();

    // 3. 해당 학급의 모든 기존 학생 일시 비활성화
    $deactivate = $pdo->prepare("UPDATE students SET is_active = 0 WHERE class_id = :class_id");
    $deactivate->execute(['class_id' => $targetClassId]);

    // Phase 3: student_hash 기반 학생 매칭 및 저장 (실명 평문 DB 저장 100% 배제)
    $checkStmt  = $pdo->prepare("SELECT id, is_initial_pin FROM students WHERE class_id = :class_id AND student_hash = :hash");
    $updateStmt = $pdo->prepare("UPDATE students SET is_active = 1 WHERE id = :id");
    $insertStmt = $pdo->prepare("
        INSERT INTO students (class_id, student_hash, pin, is_initial_pin, is_active) 
        VALUES (:class_id, :hash, :pin, 1, 1)
    ");
    $updatePinStmt = $pdo->prepare("UPDATE students SET pin = :pin WHERE id = :id AND is_initial_pin = 1");

    foreach ($cleanList as $item) {
        $name = $item['name'];
        $pin  = $item['pin'];

        // 실명 대신 32자리 솔트 해시 계산
        $studentHash = makeStudentHash($classSalt, $name);

        $checkStmt->execute(['class_id' => $targetClassId, 'hash' => $studentHash]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $updateStmt->execute(['id' => $existing['id']]);
            // 초기 비밀번호 상태인 경우에만 갱신
            if ((int)$existing['is_initial_pin'] === 1) {
                $updatePinStmt->execute(['pin' => $pin, 'id' => $existing['id']]);
            }
        } else {
            $insertStmt->execute([
                'class_id' => $targetClassId,
                'hash'     => $studentHash,
                'pin'      => $pin
            ]);
        }
    }

    $pdo->commit();

    // 4. 구글 시트가 연동되어 있는 경우 교사 구글 시트에 원본 명단 동기화 (SSOT 원칙)
    $sheetSyncNote = '';
    if (!empty($targetSheetId)) {
        $accessToken = getValidAccessToken($pdo, $ownerUserId);
        if ($accessToken) {
            // Google Sheets API: A5:B 영역에 이름과 PIN 일괄 기록 (4행 헤더 보호, 5행부터 학생 명단 시작)
            $rows = [];
            foreach ($cleanList as $st) {
                $rows[] = [$st['name'], $st['pin']];
            }

            // 기존 명단 잔여분을 깨끗하게 지우기 위해 최대 50행까지 빈칸 패딩
            $paddedRows = $rows;
            while (count($paddedRows) < 50) {
                $paddedRows[] = ['', ''];
            }
            
            $url = "https://sheets.googleapis.com/v4/spreadsheets/" . urlencode($targetSheetId) . "/values/A5:B" . (count($paddedRows) + 4) . "?valueInputOption=USER_ENTERED";
            $payload = json_encode(['values' => $paddedRows]);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            $sheetRes = curl_exec($ch);
            $sheetCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($sheetCode === 200) {
                $sheetSyncNote = ' 및 구글 시트 동기화';
            }
        }
    }

    jsonSuccess([
        'class_id'      => $targetClassId,
        'student_count' => count($cleanList)
    ], count($cleanList) . '명의 학생 명단 등록' . $sheetSyncNote . '이 완료되었습니다. (서버 제로-PII 가명 저장 완료)');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('학생 명단 저장 중 오류: ' . $e->getMessage());
}
