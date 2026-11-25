<?php
/**
 * api/google/sync_sheet.php
 * 평가 마감 처리: 구글 시트로 최종 점수 동기화, 제로-PII 드라이브 원본 백업 및 로컬 이미지 완전 파기
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

// 일괄 업로드 타임아웃 방지 (3분)
@set_time_limit(180);

$input = getJsonInput();
if (empty($input)) {
    $input = $_REQUEST;
}

$assignId = (int)($input['assign_id'] ?? $input['assignment_id'] ?? 0);

if (!$assignId) {
    jsonError('평가 ID가 누락되었습니다.');
}

try {
    $accessToken = getValidAccessToken($pdo, $userId);
    if (!$accessToken) {
        throw new Exception("구글 연동이 만료되었거나 연결되어 있지 않습니다.");
    }

    $stmt = $pdo->prepare("
        SELECT 
            t.title AS exam_name, 
            c.id AS class_id,
            c.sheet_id, 
            c.class_name, 
            c.class_salt,
            c.drive_folder_id,
            u.google_root_folder_id
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        JOIN classes c ON a.class_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE a.id = :assign_id AND c.user_id = :user_id
    ");
    $stmt->execute([
        'assign_id' => $assignId,
        'user_id'   => $userId
    ]);
    $assignInfo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignInfo || empty($assignInfo['sheet_id'])) {
        throw new Exception("연동된 구글 시트 정보를 찾을 수 없습니다.");
    }

    $examName      = $assignInfo['exam_name'];
    $spreadsheetId = $assignInfo['sheet_id'];
    $classFolderId = !empty($assignInfo['drive_folder_id']) ? $assignInfo['drive_folder_id'] : $assignInfo['google_root_folder_id'];

    if (!$classFolderId) {
        throw new Exception("학급 구글 드라이브 폴더 정보를 찾을 수 없습니다.");
    }

    // 1단계: 구글 드라이브 폴더 계층 확보 ({classFolderId} -> 'sheet' -> {$examName})
    $sheetFolderId = getOrCreateDriveFolder($accessToken, 'sheet', $classFolderId);
    if (!$sheetFolderId) {
        throw new Exception("드라이브 'sheet' 폴더 생성에 실패했습니다.");
    }

    $examFolderId = getOrCreateDriveFolder($accessToken, $examName, $sheetFolderId);
    if (!$examFolderId) {
        throw new Exception("드라이브 시험 폴더 [{$examName}] 생성에 실패했습니다.");
    }

    // Phase 3: 교사 구글 시트에서 학생 실명 명단 조회 (SSOT 연동)
    $classId   = (int)$assignInfo['class_id'];
    $classSalt = !empty($assignInfo['class_salt']) ? $assignInfo['class_salt'] : getOrCreateClassSalt($pdo, $classId);
    $sheetStudentLookup = []; // hash => realName

    $sheetUrl = "https://sheets.googleapis.com/v4/spreadsheets/" . urlencode($spreadsheetId) . "/values/A5:B55";
    $ch = curl_init($sheetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $sheetRes = curl_exec($ch);
    $sheetCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($sheetCode === 200) {
        $sheetJson = json_decode($sheetRes, true);
        $values = $sheetJson['values'] ?? [];
        foreach ($values as $row) {
            $stName = trim($row[0] ?? '');
            if (empty($stName)) continue;
            $stHash = makeStudentHash($classSalt, $stName);
            $sheetStudentLookup[$stHash] = $stName;
        }
    }

    // 2단계: 채점 결과 전체 조회 (가명 student_hash 조회)
    $resStmt = $pdo->prepare("
        SELECT 
            e.id AS result_id, 
            e.student_id,
            s.student_hash,
            e.total_score, 
            e.raw_results
        FROM exam_results e
        LEFT JOIN students s ON e.student_id = s.id
        WHERE e.assignment_id = :assign_id
        ORDER BY e.id ASC
    ");
    $resStmt->execute(['assign_id' => $assignId]);
    $results = $resStmt->fetchAll(PDO::FETCH_ASSOC);

    // 구글 시트 실명 매핑 주입
    foreach ($results as &$r) {
        $sh = $r['student_hash'] ?? '';
        $r['student_name'] = $sheetStudentLookup[$sh] ?? ('학생 ' . ($r['student_id'] ?? $r['result_id']));
    }
    unset($r);

    if (empty($results)) {
        throw new Exception("마감할 채점 결과 데이터가 존재하지 않습니다.");
    }

    // 3단계: 학생별 마스킹 시험지 원본을 제로-PII 가명 파일명으로 드라이브 백업 업로드 (초고속 병렬 처리)
    $origDir = APP_ROOT . '/public/uploads/originals/' . $assignId;
    $uploadQueue = [];
    $studentPageMeta = []; // studentId => [ pNum => queueKey ]

    if (is_dir($origDir)) {
        foreach ($results as $res) {
            $studentId = (int)$res['student_id'];
            $hashKey = substr(hash('sha256', (string)$studentId . '_' . (string)$assignId), 0, 10);

            // 단일 쪽 파일: {studentId}.jpg
            $singlePath = $origDir . '/' . $studentId . '.jpg';
            if (file_exists($singlePath)) {
                $qKey = "{$studentId}_1";
                $uploadQueue[] = [
                    'key'      => $qKey,
                    'path'     => $singlePath,
                    'name'     => sprintf("%s_s_%s.jpg", $examName, $hashKey),
                    'parentId' => $examFolderId
                ];
                $studentPageMeta[$studentId][1] = $qKey;
            }

            // 다쪽 파일: {studentId}_p{pNum}.jpg
            $multiFiles = glob($origDir . '/' . $studentId . '_p*.jpg');
            if (!empty($multiFiles)) {
                foreach ($multiFiles as $mPath) {
                    if (preg_match('/_p(\d+)\.jpg$/', basename($mPath), $m)) {
                        $pNum = (int)$m[1];
                        $qKey = "{$studentId}_{$pNum}";
                        $uploadQueue[] = [
                            'key'      => $qKey,
                            'path'     => $mPath,
                            'name'     => sprintf("%s_s_%s_p%d.jpg", $examName, $hashKey, $pNum),
                            'parentId' => $examFolderId
                        ];
                        $studentPageMeta[$studentId][$pNum] = $qKey;
                    }
                }
            }
        }
    }

    // 드라이브 초고속 병렬 업로드 실행 (속도 5~10배 가속)
    $uploadedIds = !empty($uploadQueue) ? uploadDriveFilesParallel($accessToken, $uploadQueue, 4) : [];

    // 업로드된 드라이브 ID들을 학생 채점 결과 객체 및 DB에 주입
    foreach ($results as &$res) {
        $studentId = (int)$res['student_id'];
        $resultId  = (int)$res['result_id'];
        
        $pageMap = $studentPageMeta[$studentId] ?? [];
        $pageDriveMap = [];
        $primaryDriveFileId = null;

        foreach ($pageMap as $pNum => $qKey) {
            $fId = $uploadedIds[$qKey] ?? null;
            if ($fId) {
                $pageDriveMap[$pNum] = $fId;
                if (!$primaryDriveFileId || $pNum === 1) {
                    $primaryDriveFileId = $fId;
                }
            }
        }

        if ($primaryDriveFileId) {
            $res['drive_file_id'] = $primaryDriveFileId;

            $rawArr = is_string($res['raw_results'] ?? null)
                ? json_decode($res['raw_results'], true)
                : ($res['raw_results'] ?? []);

            if (is_array($rawArr)) {
                foreach ($rawArr as &$item) {
                    $itemPage = (int)($item['page'] ?? 1);
                    $itemDriveId = $pageDriveMap[$itemPage] ?? $primaryDriveFileId;
                    $item['drive_file_id'] = $itemDriveId;
                    $item['image_path']    = 'drive:' . $itemDriveId;
                }
                unset($item);

                $updatedRawJson = json_encode($rawArr, JSON_UNESCAPED_UNICODE);
                $res['raw_results'] = $updatedRawJson;

                // DB의 exam_results 레코드 즉시 갱신 (마감 후 expand_crop.php 지원)
                $upResStmt = $pdo->prepare("UPDATE exam_results SET raw_results = :raw WHERE id = :id");
                $upResStmt->execute(['raw' => $updatedRawJson, 'id' => $resultId]);
            }
        }
    }
    unset($res);

    // 4단계: 구글 시트에 최종 점수 및 드라이브 링크 기록
    $syncResult = writeExamScoresToSheet($accessToken, $spreadsheetId, $examName, $results);
    if (!$syncResult) {
        throw new Exception("구글 시트 데이터 전송에 실패했습니다.");
    }

    $sheetGid = is_array($syncResult) ? ($syncResult['sheet_gid'] ?? null) : null;

    // 5단계: 서버 로컬 디스크 완전 파기 (Zero-Remains 원칙)
    $cropsDir = APP_ROOT . '/public/uploads/crops/' . $assignId;
    if (is_dir($cropsDir)) {
        $files = glob($cropsDir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($cropsDir);
    }

    if (is_dir($origDir)) {
        $files = glob($origDir . '/*');
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($origDir);
    }

    // sheet_gid 컬럼 부재 시 자동 추가 (Self-Healing)
    try {
        $pdo->query("SELECT sheet_gid FROM assignments LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN sheet_gid VARCHAR(64) NULL DEFAULT NULL");
    }

    // 평가 상태 'completed' 및 sheet_gid 저장
    $upStmt = $pdo->prepare("UPDATE assignments SET status = 'completed', sheet_gid = :sheet_gid WHERE id = :id");
    $upStmt->execute([
        'sheet_gid' => $sheetGid !== null ? (string)$sheetGid : null,
        'id'        => $assignId
    ]);

    jsonSuccess([], '평가가 성공적으로 마감되었으며, 학생 시험지 원본이 구글 드라이브에 안전하게 비식별 백업되고 서버 디스크에서 완전 파기되었습니다.');

} catch (Exception $e) {
    jsonError('마감 오류: ' . $e->getMessage());
}
