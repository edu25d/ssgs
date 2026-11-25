<?php
/**
 * api/google/sync_diag.php — 임시 진단 (테스트 후 삭제)
 */
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

header('Content-Type: application/json; charset=utf-8');

$userId = requireTeacherAuth();

try {
    $accessToken = getValidAccessToken($pdo, $userId);

    // 최근 assignment 조회
    $stmt = $pdo->prepare("
        SELECT a.id AS assign_id, t.title AS exam_name,
               c.drive_folder_id, u.google_root_folder_id
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        JOIN classes c ON a.class_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE c.user_id = :uid ORDER BY a.id DESC LIMIT 1
    ");
    $stmt->execute(['uid' => $userId]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);

    $classFolderId = !empty($info['drive_folder_id'])
        ? $info['drive_folder_id']
        : ($info['google_root_folder_id'] ?? null);

    $assignId = (int)($info['assign_id'] ?? 0);

    // originals 디렉토리 파일 수 확인
    $origDir  = APP_ROOT . '/public/uploads/originals/' . $assignId;
    $origFiles = is_dir($origDir) ? glob($origDir . '/*.jpg') : [];

    // 'sheet' 폴더 현재 상태
    $sheetFolderId = $classFolderId
        ? getDriveFolderIdByName($accessToken, $classFolderId, 'sheet')
        : null;

    // google_drive_helper.php 내 함수 존재 여부
    $helperFuncs = [
        'getOrCreateDriveFolder'  => function_exists('getOrCreateDriveFolder'),
        'getDriveFolderIdByName'  => function_exists('getDriveFolderIdByName'),
        'uploadDriveFile'         => function_exists('uploadDriveFile'),
        'writeExamScoresToSheet'  => function_exists('writeExamScoresToSheet'),
    ];

    // sync_sheet.php 파일 마지막 수정 시각 (서버 기준)
    $syncSheetPath = APP_ROOT . '/api/google/sync_sheet.php';
    $syncMtime = file_exists($syncSheetPath)
        ? date('Y-m-d H:i:s', filemtime($syncSheetPath))
        : 'not found';

    // sync_sheet.php 내 Drive 백업 코드 존재 여부 확인
    $syncContent  = file_exists($syncSheetPath) ? file_get_contents($syncSheetPath) : '';
    $hasDriveCode = strpos($syncContent, 'getOrCreateDriveFolder') !== false;
    $hasOrigCode  = strpos($syncContent, 'originals') !== false;

    echo json_encode([
        'assign_id'            => $assignId,
        'classFolderId'        => $classFolderId,
        'originals_dir_exists' => is_dir($origDir),
        'originals_file_count' => count($origFiles),
        'sheet_folder_id_now'  => $sheetFolderId,
        'helper_functions'     => $helperFuncs,
        'sync_sheet_mtime'     => $syncMtime,
        'sync_sheet_has_drive_code'    => $hasDriveCode,
        'sync_sheet_has_originals_code'=> $hasOrigCode,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['exception' => $e->getMessage()]);
}
