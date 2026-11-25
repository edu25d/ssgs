<?php
/**
 * api/classes/create.php
 * 학급 생성: 구글 드라이브 폴더 생성, 마스터 시트 복사 연동 및 제로-지식 학급 솔트 발급
 * (자가 치유: 루트 폴더 부재 시 자동 복구 생성 탑재)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /views/dashboard/main.php");
    exit;
}

// [중복 클릭 및 동시 생성 방어] 세션 락 (최대 10초)
if (!empty($_SESSION['is_creating_class']) && (time() - $_SESSION['is_creating_class']) < 10) {
    $msg = '이미 학급 생성이 진행 중입니다. 잠시만 기다려 주세요.';
    $isJsonEarly = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    if ($isJsonEarly) {
        jsonError($msg);
    }
    echo "<script>alert('" . addslashes($msg) . "'); history.back();</script>";
    exit;
}
$_SESSION['is_creating_class'] = time();

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

$input = $isJson ? getJsonInput() : $_POST;
$rawNames = trim($input['class_names'] ?? '');
$classNames = empty($rawNames) ? ['우리반'] : array_filter(array_map('trim', explode(',', $rawNames)));

// 1. 유효한 구글 토큰 획득
$token = getValidAccessToken($pdo, $userId);

$userStmt = $pdo->prepare("SELECT google_access_token, google_refresh_token, google_root_folder_id, google_folder_name FROM users WHERE id = :id");
$userStmt->execute(['id' => $userId]);
$userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$token) {
    unset($_SESSION['is_creating_class']);
    $msg = '구글 드라이브 인증 토큰이 만료되었거나 연결되지 않았습니다. 로그아웃 후 교육청 공식 계정으로 다시 로그인해 주세요.';
    if ($isJson) {
        jsonError($msg);
    }
    echo "<script>alert('" . addslashes($msg) . "'); window.location.href='/views/auth/login.php';</script>";
    exit;
}

$rootFolderId = $userRow['google_root_folder_id'] ?? null;
$folderName   = !empty($userRow['google_folder_name']) ? $userRow['google_folder_name'] : '쌤글라스';

// 2. [자가 치유 Self-Healing] 루트 폴더([쌤글라스]) 부재 시 즉시 자동 생성
if (empty($rootFolderId)) {
    $rootFolderId = createDriveFolder($token, $folderName);
    if ($rootFolderId) {
        $up = $pdo->prepare("UPDATE users SET google_root_folder_id = :fid, google_folder_name = :fname WHERE id = :id");
        $up->execute(['fid' => $rootFolderId, 'fname' => $folderName, 'id' => $userId]);
    } else {
        unset($_SESSION['is_creating_class']);
        $msg = '구글 드라이브 루트 폴더([쌤글라스]) 자동 생성에 실패했습니다. Google Cloud Console에서 Google Drive API 활성화 여부 및 드라이브 용량을 확인해 주세요.';
        if ($isJson) {
            jsonError($msg);
        }
        echo "<script>alert('" . addslashes($msg) . "'); history.back();</script>";
        exit;
    }
}

$masterSheetId = env('GOOGLE_MASTER_SHEET_ID', '1lxwqbkb3aRl1-wTL1kl1UKXBKPdn-jx0lfrKK9eIpCI');

// [중복 학급명 방어] 기존 활성 학급 목록 조회
$existStmt = $pdo->prepare("SELECT class_name FROM classes WHERE user_id = :u AND is_active = 1");
$existStmt->execute(['u' => $userId]);
$existingNames = $existStmt->fetchAll(PDO::FETCH_COLUMN);
$existingMap = array_flip(array_map('trim', $existingNames));

$createdClasses = [];
$skippedClasses = [];

foreach ($classNames as $name) {
    $name = trim($name);
    if (empty($name)) continue;

    // 이미 존재하는 학급명이면 중복 생성 건너뜀
    if (isset($existingMap[$name])) {
        $skippedClasses[] = $name;
        continue;
    }

    // 3. 학급 전용 폴더 생성
    $folderId = createDriveFolder($token, $name, $rootFolderId);
    if (!$folderId) {
        unset($_SESSION['is_creating_class']);
        $msg = "학급 [{$name}]의 드라이브 폴더 생성에 실패했습니다. 드라이브 쓰기 권한을 확인하세요.";
        if ($isJson) {
            jsonError($msg);
        }
        echo "<script>alert('" . addslashes($msg) . "'); history.back();</script>";
        exit;
    }

    // 4. 마스터 시트 복사
    $copyResult = copyMasterSheetToFolder($token, $masterSheetId, $folderId, $name);
    $sheetId = $copyResult['id'] ?? null;

    if ($sheetId) {
        // Phase 3: 학급 고유 32자리 난수 솔트 생성
        $classSalt = generateClassSalt();

        $ins = $pdo->prepare("
            INSERT INTO classes (user_id, class_name, class_salt, drive_folder_id, sheet_id) 
            VALUES (:u, :c, :salt, :f, :s)
        ");
        $ins->execute([
            'u'    => $userId,
            'c'    => $name,
            'salt' => $classSalt,
            'f'    => $folderId,
            's'    => $sheetId
        ]);
        $classId = (int)$pdo->lastInsertId();
        $createdClasses[] = ['name' => $name, 'class_id' => $classId];
        $existingMap[$name] = true; // 현재 루프 내 중복도 방어
    } else {
        unset($_SESSION['is_creating_class']);
        $errMsg = "마스터 시트 복사 오류: " . ($copyResult['error']['message'] ?? '권한 또는 마스터 시트 ID 확인 필요');
        if ($isJson) {
            jsonError($errMsg);
        }
        echo "<script>alert('" . addslashes($errMsg) . "'); history.back();</script>";
        exit;
    }
}

// 학급 생성 작업 완료 -> 세션 락 해제
unset($_SESSION['is_creating_class']);

if (empty($createdClasses) && !empty($skippedClasses)) {
    $skipMsg = '입력하신 학급(' . implode(', ', $skippedClasses) . ')은 이미 등록되어 있습니다.';
    if ($isJson) {
        jsonError($skipMsg);
    }
    echo "<script>alert('" . addslashes($skipMsg) . "'); window.location.href='/views/dashboard/main.php';</script>";
    exit;
}

if ($isJson) {
    jsonSuccess([
        'created' => $createdClasses,
        'skipped' => $skippedClasses
    ], '학급이 성공적으로 생성되었습니다.');
}

header("Location: /views/dashboard/main.php");
exit;
