<?php
/**
 * views/auth/login.php
 * 교육청/학교 공식 구글 계정 단일 로그인 & 학급 키오스크 접속 화면
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/utils.php';

// 기인증 세션 자동 리다이렉트
if (!empty($_SESSION['user_id'])) {
    header("Location: /views/dashboard/main.php");
    exit;
}
if (!empty($_SESSION['kiosk_class_id'])) {
    header("Location: /views/input/kiosk.php");
    exit;
}

// 에러 메시지 감지 (세션 플래시 또는 GET 파라미터)
$loginError = $_SESSION['login_error'] ?? null;
if (isset($_SESSION['login_error'])) {
    unset($_SESSION['login_error']);
}
$errorType = $_GET['error'] ?? ($loginError['type'] ?? null);
$errorEmail = $_GET['email'] ?? ($loginError['email'] ?? '');

// Google OAuth 2.0 공식 인가 URL 생성
$clientId     = env('GOOGLE_CLIENT_ID', '');
$redirectUri  = env('GOOGLE_REDIRECT_URI', getBaseUrl() . '/google_callback.php');

$googleParams = [
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets',
    'access_type'   => 'offline',
    'prompt'        => 'select_account'
];
$googleAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($googleParams);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>로그인 - 쌤글라스</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-600: #64748b;
            --gray-800: #1e293b;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif;
            background-color: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .login-card {
            background: #ffffff;
            padding: 44px 36px 36px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
            width: 100%;
            max-width: 440px;
            border: 1px solid var(--gray-200);
            text-align: center;
            position: relative;
        }
        .brand-icon {
            font-size: 2.8rem;
            margin-bottom: 8px;
            display: inline-block;
        }
        h1 {
            margin: 0 0 6px;
            color: var(--gray-800);
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .subtitle {
            color: var(--gray-600);
            font-size: 0.95rem;
            margin-bottom: 28px;
        }

        /* 인라인 에러 배너 */
        .alert-card-danger {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 20px;
            text-align: left;
            color: #991b1b;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            font-size: 0.88rem;
            line-height: 1.5;
        }
        .alert-card-danger .alert-icon {
            font-size: 1.3rem;
            line-height: 1;
        }
        .alert-card-danger strong {
            display: block;
            margin-bottom: 2px;
            font-size: 0.92rem;
            color: #7f1d1d;
        }
        .alert-card-danger p {
            margin: 0;
            color: #991b1b;
        }

        /* 교육청 공식 구글 로그인 버튼 */
        .btn-google-edu {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            padding: 15px 20px;
            background: #ffffff;
            color: #1f2937;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            text-decoration: none;
            font-size: 1.02rem;
            font-weight: 700;
            transition: all 0.2s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            cursor: pointer;
        }
        .btn-google-edu:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-1px);
        }
        .google-icon {
            width: 22px;
            height: 22px;
        }

        /* 교육청 공식 계정 안내 배지 */
        .edu-badge-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 14px 16px;
            margin-top: 24px;
            text-align: left;
            font-size: 0.86rem;
            color: #1e40af;
            line-height: 1.55;
        }
        .edu-badge-box b {
            display: block;
            margin-bottom: 4px;
            font-size: 0.88rem;
        }
        .domain-tags {
            margin-top: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .tag {
            background: #dbeafe;
            color: #1e3a8a;
            padding: 2px 7px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* 키오스크 토글 아코디언 */
        .kiosk-section {
            margin-top: 28px;
            border-top: 1px dashed var(--gray-200);
            padding-top: 20px;
        }
        .btn-kiosk-toggle {
            background: none;
            border: none;
            color: var(--gray-600);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            padding: 6px 12px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .btn-kiosk-toggle:hover {
            color: var(--gray-800);
            background: var(--gray-100);
        }
        .kiosk-form-box {
            display: none;
            margin-top: 16px;
            background: var(--gray-50);
            padding: 18px 20px;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            text-align: left;
        }
        .kiosk-form-box label {
            display: block;
            font-size: 0.86rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 6px;
        }
        .kiosk-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.95rem;
            margin-bottom: 12px;
        }
        .btn-kiosk-submit {
            width: 100%;
            padding: 10px;
            background: var(--gray-800);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-kiosk-submit:hover {
            background: #0f172a;
        }

        /* 팝업 모달 스타일 */
        .auth-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
            animation: fadeIn 0.2s ease-out;
        }
        .auth-modal {
            background: #ffffff;
            max-width: 460px;
            width: 100%;
            padding: 36px 30px 28px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            text-align: center;
            border: 1px solid #e2e8f0;
            animation: popIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .modal-icon {
            font-size: 2.8rem;
            margin-bottom: 12px;
        }
        .modal-title {
            color: #1e293b;
            font-size: 1.35rem;
            font-weight: 800;
            margin: 0 0 10px;
            letter-spacing: -0.01em;
        }
        .modal-desc {
            color: #64748b;
            font-size: 0.92rem;
            line-height: 1.6;
            margin: 0 0 16px;
        }
        .modal-email-box {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.93rem;
            margin-bottom: 16px;
            word-break: break-all;
        }
        .modal-guide-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            text-align: left;
            margin-bottom: 22px;
            font-size: 0.86rem;
            color: #334155;
        }
        .modal-guide-box b {
            display: block;
            margin-bottom: 4px;
        }
        .modal-guide-box ul {
            margin: 4px 0 0;
            padding-left: 18px;
            line-height: 1.5;
        }
        .btn-modal-primary {
            display: block;
            width: 100%;
            background: #2563eb;
            color: white;
            text-decoration: none;
            padding: 13px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.98rem;
            transition: background 0.2s;
            cursor: pointer;
            border: none;
            box-sizing: border-box;
        }
        .btn-modal-primary:hover {
            background: #1d4ed8;
        }
        .btn-modal-close {
            background: none;
            border: none;
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 8px 12px;
            margin-top: 6px;
            cursor: pointer;
            border-radius: 6px;
            transition: color 0.2s;
        }
        .btn-modal-close:hover {
            color: #1e293b;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes popIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>

    <!-- 비인가 계정 차단 시 팝업 모달 -->
    <?php if ($errorType === 'unauthorized_domain'): ?>
    <div class="auth-modal-overlay" id="authErrorModal" onclick="if(event.target === this) closeAuthModal();">
        <div class="auth-modal">
            <div class="modal-icon">🛡️</div>
            <h2 class="modal-title">교육청/학교 공식 계정이 아닙니다</h2>
            <?php if (!empty($errorEmail)): ?>
            <div class="modal-email-box">시도하신 계정: <?= htmlspecialchars($errorEmail, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <p class="modal-desc">
                교육부 「개인정보 보호 기본지침」에 따라 학생 평가 자료 보안을 위해 <b>시·도교육청 및 초·중·고 공식 구글 워크스페이스 계정</b>으로만 서비스 이용이 가능합니다.
            </p>
            <div class="modal-guide-box">
                <b>📌 허용되는 공식 도메인 예시:</b>
                <ul>
                    <li>시·도교육청 계정 (예: <code>*.go.kr</code>, <code>*.o-e.go.kr</code>)</li>
                    <li>초·중·고 공식 계정 (예: <code>*.sen.es.kr</code>, <code>*.ms.kr</code>, <code>*.hs.kr</code>)</li>
                </ul>
            </div>
            <a href="<?= htmlspecialchars($googleAuthUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-modal-primary">
                공식 계정으로 다시 로그인
            </a>
            <button type="button" class="btn-modal-close" onclick="closeAuthModal()">닫기</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="login-card">
        <span class="brand-icon">👓</span>
        <h1>쌤글라스</h1>
        <div class="subtitle">AI 기반 지능형 학급 평가 통합 플랫폼</div>

        <!-- 비인가 계정 로그인 실패 시 인라인 알림 카드 -->
        <?php if ($errorType === 'unauthorized_domain'): ?>
        <div class="alert-card-danger">
            <span class="alert-icon">⚠️</span>
            <div>
                <strong>공식 교육청 계정 로그인이 필요합니다</strong>
                <p>개인 계정(<?= htmlspecialchars($errorEmail, ENT_QUOTES, 'UTF-8') ?>)은 사용이 제한됩니다. 시·도교육청 공식 계정으로 로그인해 주세요.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- 1. 교사용 구글 공식 계정 원스톱 로그인 -->
        <a href="<?= htmlspecialchars($googleAuthUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-google-edu">
            <svg class="google-icon" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
            </svg>
            교육청 공식 계정으로 시작하기
        </a>

        <!-- 공식 계정 안내 배지 -->
        <div class="edu-badge-box">
            <b>🛡️ 개인정보 보호 공식 워크스페이스 전용</b>
            학생 평가 자료 보안을 위해 시·도교육청 Google Workspace 공식 계정으로만 이용하실 수 있습니다.
            <div class="domain-tags">
                <span class="tag">*.go.kr</span>
                <span class="tag">*.o-e.go.kr</span>
                <span class="tag">*.sen.es.kr</span>
                <span class="tag">*.ms.kr</span>
                <span class="tag">*.hs.kr</span>
            </div>
        </div>

        <!-- 2. 학급 키오스크(입력기) 전용 접속 섹션 -->
        <div class="kiosk-section">
            <button type="button" class="btn-kiosk-toggle" onclick="toggleKioskBox()">
                🏫 학급 키오스크(입력기)로 접속하기 ▾
            </button>

            <div class="kiosk-form-box" id="kioskBox">
                <form action="/api/auth/login.php" method="POST">
                    <label for="kioskId">키오스크 계정 ID</label>
                    <input type="text" name="username" id="kioskId" class="kiosk-input" placeholder="예: test-1 또는 test-a" required>

                    <label for="kioskPw">학급 접속 비밀번호</label>
                    <input type="password" name="password" id="kioskPw" class="kiosk-input" placeholder="학급 설정 비밀번호" required>

                    <button type="submit" class="btn-kiosk-submit">키오스크 로그인</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleKioskBox() {
            const box = document.getElementById('kioskBox');
            box.style.display = (box.style.display === 'block') ? 'none' : 'block';
        }

        function closeAuthModal() {
            const modal = document.getElementById('authErrorModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAuthModal();
            }
        });
    </script>
</body>
</html>
