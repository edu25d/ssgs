<?php
/**
 * api/google/callback.php
 * 구글 OAuth 2.0 공식 교육청 계정 인증 콜백 및 원스톱 자동 가입/로그인 처리
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

// 1. 인가 코드 수신 확인
if (!isset($_GET['code'])) {
    echo "<script>alert('Google 인증 코드를 받지 못했습니다.'); window.location.href='/views/auth/login.php';</script>";
    exit;
}

$code = $_GET['code'];

$clientId     = env('GOOGLE_CLIENT_ID', '');
$clientSecret = env('GOOGLE_CLIENT_SECRET', '');
$redirectUri  = env('GOOGLE_REDIRECT_URI', getBaseUrl() . '/google_callback.php');

// 2. 구글 토큰 교환 요청
$tokenUrl = 'https://oauth2.googleapis.com/token';
$postData = [
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code'
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false
]);
$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);

if (isset($tokenData['error']) || empty($tokenData['access_token'])) {
    $errMsg = $tokenData['error_description'] ?? ($tokenData['error'] ?? '구글 토큰 발급 실패');
    echo "<script>alert('구글 토큰 발급에 실패했습니다: " . addslashes($errMsg) . "'); window.location.href='/views/auth/login.php';</script>";
    exit;
}

$accessToken  = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? null;

// 3. 구글 사용자 프로필(UserInfo) 조회
$userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
$chUser = curl_init($userInfoUrl);
curl_setopt_array($chUser, [
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false
]);
$userResponse = curl_exec($chUser);
curl_close($chUser);

$googleUser = json_decode($userResponse, true);
$email      = trim((string)($googleUser['email'] ?? ''));
$googleSub  = trim((string)($googleUser['sub'] ?? ''));
$rawName    = trim((string)($googleUser['name'] ?? '선생님'));

if (empty($email)) {
    echo "<script>alert('구글 계정의 이메일 정보를 불러올 수 없습니다.'); window.location.href='/views/auth/login.php';</script>";
    exit;
}

// 4. [보안 핵심] 대한민국 시·도교육청 및 초·중·고 공식 도메인 화이트리스트 엄격 검증
$isDevMode = (bool)env('DEV_MODE', false);
$allowedDevEmails = array_filter(array_map('trim', explode(',', (string)env('ALLOWED_DEV_EMAILS', ''))));
$isDevAllowed = $isDevMode || in_array($email, $allowedDevEmails, true);

$isOfficialEdu = (bool)preg_match('/\.(go\.kr|o-e\.go\.kr|es\.kr|ms\.kr|hs\.kr|sc\.kr)$/i', $email);

if (!$isDevAllowed && !$isOfficialEdu) {
    // 인가되지 않은 개인 계정(@gmail.com, @naver.com 등) 접속 시 세션 플래시 및 파라미터와 함께 로그인 화면으로 리다이렉트
    $_SESSION['login_error'] = [
        'type'  => 'unauthorized_domain',
        'email' => $email
    ];
    header("Location: /views/auth/login.php?error=unauthorized_domain&email=" . urlencode($email));
    exit;
}

// 5. 로그인 및 계정 등록 처리
try {
    $currentUserId = $_SESSION['user_id'] ?? null;

    // A. 이미 로그인된 상태에서 드라이브 토큰 갱신 연동을 진행한 경우
    if ($currentUserId) {
        $userId = (int)$currentUserId;
        $userStmt = $pdo->prepare("SELECT id, google_root_folder_id, google_folder_name FROM users WHERE id = :id");
        $userStmt->execute(['id' => $userId]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // B. 비로그인 상태에서 구글 원스톱 로그인으로 진입한 경우
        $findStmt = $pdo->prepare("SELECT id, name, username, google_root_folder_id, google_folder_name FROM users WHERE email = :email LIMIT 1");
        $findStmt->execute(['email' => $email]);
        $userRow = $findStmt->fetch(PDO::FETCH_ASSOC);

        if (!$userRow) {
            // [신규 가입] 이메일 앞자리 추출 및 키오스크 충돌 순번(rank) 계산
            $cleanUsername = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]));
            if (empty($cleanUsername)) $cleanUsername = 'teacher';

            $rankStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
            $rankStmt->execute(['u' => $cleanUsername]);
            $kioskRank = (int)$rankStmt->fetchColumn();

            $insStmt = $pdo->prepare("
                INSERT INTO users (username, name, email, google_id, kiosk_rank, google_folder_name, created_at)
                VALUES (:u, :name, :email, :gid, :rank, '쌤글라스', NOW())
            ");
            $insStmt->execute([
                'u'     => $cleanUsername,
                'name'  => $rawName,
                'email' => $email,
                'gid'   => $googleSub,
                'rank'  => $kioskRank
            ]);
            $userId = (int)$pdo->lastInsertId();

            $userRow = [
                'id'                    => $userId,
                'name'                  => $rawName,
                'username'              => $cleanUsername,
                'google_root_folder_id' => null,
                'google_folder_name'    => '쌤글라스'
            ];
        } else {
            $userId = (int)$userRow['id'];
        }

        // 로그인 세션 발급
        $_SESSION['user_id']      = $userId;
        $_SESSION['teacher_name'] = $userRow['name'] ?? $rawName;
        $_SESSION['username']     = $userRow['username'] ?? '';
    }

    // 6. 구글 드라이브 루트 폴더([쌤글라스]) 자동 확인 및 생성
    $existingFolderId   = $userRow['google_root_folder_id'] ?? null;
    $existingFolderName = !empty($userRow['google_folder_name']) ? $userRow['google_folder_name'] : '쌤글라스';
    $targetFolderId     = null;

    if ($existingFolderId) {
        $chVerify = curl_init("https://www.googleapis.com/drive/v3/files/{$existingFolderId}?fields=id,trashed");
        curl_setopt_array($chVerify, [
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $verifyRes = json_decode(curl_exec($chVerify), true);
        curl_close($chVerify);

        if (!empty($verifyRes['id']) && empty($verifyRes['trashed'])) {
            $targetFolderId = $verifyRes['id'];
        }
    }

    if (!$targetFolderId) {
        $targetFolderId = createDriveFolder($accessToken, $existingFolderName);
    }

    // 7. 토큰 및 폴더 정보 DB 업데이트
    $updateParams = [
        'token'       => $accessToken,
        'folder_id'   => $targetFolderId,
        'folder_name' => $existingFolderName,
        'email'       => $email,
        'gid'         => $googleSub,
        'id'          => $userId
    ];

    if ($refreshToken) {
        $updateSql = "
            UPDATE users 
            SET google_access_token = :token, 
                google_refresh_token = :rtoken, 
                google_root_folder_id = :folder_id, 
                google_folder_name = :folder_name,
                email = :email,
                google_id = :gid
            WHERE id = :id
        ";
        $updateParams['rtoken'] = $refreshToken;
    } else {
        $updateSql = "
            UPDATE users 
            SET google_access_token = :token, 
                google_root_folder_id = :folder_id, 
                google_folder_name = :folder_name,
                email = :email,
                google_id = :gid
            WHERE id = :id
        ";
    }

    $upStmt = $pdo->prepare($updateSql);
    $upStmt->execute($updateParams);

    // 8. 팝업 창에서 재인증된 경우: 부모 창(에디터 등)에 메시지 전송 후 닫기
    $isPopupState = (isset($_GET['state']) && strpos($_GET['state'], 'popup') !== false);
    if ($isPopupState) {
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>로그인 완료</title></head>
        <body style='font-family:-apple-system,BlinkMacSystemFont,sans-serif; text-align:center; padding:50px;'>
            <div style='font-size:36px; margin-bottom:12px;'>✅</div>
            <h3 style='margin:0 0 8px; color:#1e293b;'>로그인이 완료되었습니다</h3>
            <p style='color:#64748b; font-size:14px;'>잠시 후 창이 자동으로 닫히고 저장이 계속됩니다.</p>
            <script>
                if (window.opener) {
                    window.opener.postMessage({ type: 'SSG_AUTH_SUCCESS' }, window.location.origin);
                    setTimeout(function() { window.close(); }, 500);
                } else {
                    window.location.href = '/views/dashboard/main.php?login_success=1';
                }
            </script>
        </body></html>";
        exit;
    }

    // 일반 로그인: 대시보드로 즉시 이동
    header("Location: /views/dashboard/main.php?login_success=1");
    exit;

} catch (Exception $e) {
    echo "<script>alert('로그인 처리 중 오류 발생: " . addslashes($e->getMessage()) . "'); window.location.href='/views/auth/login.php';</script>";
    exit;
}
