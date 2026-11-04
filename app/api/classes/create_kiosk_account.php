<?php
/**
 * api/classes/create_kiosk_account.php
 * 학급 키오스크 계정 순차 자동 생성 (충돌 방지 교차 알고리즘 및 학급 비밀번호 연동)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$isJson = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
       || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
$input = $isJson ? getJsonInput() : $_POST;

$classId  = (int)($input['class_id'] ?? 0);
$password = trim((string)($input['password'] ?? ''));

if (!$classId) {
    jsonError('학급 ID가 누락되었습니다.');
}

try {
    // 1. 해당 교사의 학급인지 확인 및 학급 정보(비밀번호 포함) 조회
    $cStmt = $pdo->prepare("SELECT id, class_name, class_password FROM classes WHERE id = :id AND user_id = :u AND is_active = 1");
    $cStmt->execute(['id' => $classId, 'u' => $userId]);
    $class = $cStmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        jsonError('학급 정보를 찾을 수 없습니다.');
    }

    // [핵심] 학급 키오스크 비밀번호 검증 및 설정
    if (empty($class['class_password'])) {
        if (empty($password)) {
            // 아파치 HTML 에러 가로챔 방지를 위해 HTTP 200 기반 JSON 에러 반환
            jsonError('이 학급의 키오스크 계정에 공통으로 적용될 비밀번호를 먼저 설정해주세요.');
        }
        if (mb_strlen($password) < 4) {
            jsonError('키오스크 비밀번호는 4자리 이상이어야 합니다.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upPwStmt = $pdo->prepare("UPDATE classes SET class_password = :pw WHERE id = :id AND user_id = :u");
        $upPwStmt->execute(['pw' => $hash, 'id' => $classId, 'u' => $userId]);
    } elseif (!empty($password) && mb_strlen($password) >= 4) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upPwStmt = $pdo->prepare("UPDATE classes SET class_password = :pw WHERE id = :id AND user_id = :u");
        $upPwStmt->execute(['pw' => $hash, 'id' => $classId, 'u' => $userId]);
    }

    // 2. 교사 아이디 및 키오스크 충돌 순번(kiosk_rank) 조회
    $uStmt = $pdo->prepare("SELECT username, COALESCE(kiosk_rank, 0) AS kiosk_rank FROM users WHERE id = :id");
    $uStmt->execute(['id' => $userId]);
    $userData = $uStmt->fetch(PDO::FETCH_ASSOC);

    $teacherUsername = !empty($userData['username']) ? $userData['username'] : ('user' . $userId);
    $kioskRank       = (int)($userData['kiosk_rank'] ?? 0);

    // 3. 현재 교사가 생성한 전체 키오스크 계정 수 확인
    $kCountStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM kiosk_accounts k 
        JOIN classes c ON k.class_id = c.id 
        WHERE c.user_id = :uid
    ");
    $kCountStmt->execute(['uid' => $userId]);
    $existingTotal = (int)$kCountStmt->fetchColumn();

    // 4. 하이픈 개수 및 접미사 규칙 생성
    // - rank 0: test-1, test-2 ...
    // - rank 1: test-a, test-b ...
    // - rank 2: test--1, test--2 ...
    // - rank 3: test--a, test--b ...
    // 구버전 PHP 호환을 위해 intdiv 대신 (int)floor 사용
    $hyphenCount = 1 + (int)floor($kioskRank / 2);
    $separator   = str_repeat('-', $hyphenCount);
    $isAlphabet  = ($kioskRank % 2 === 1);

    $classIndex = $existingTotal + 1;

    $makeSuffix = function($idx, $alpha) {
        if ($alpha) {
            if ($idx <= 26) {
                return chr(96 + (int)$idx);
            } else {
                return 'a' . chr(96 + (int)($idx - 26));
            }
        }
        return (string)$idx;
    };

    $suffix = $makeSuffix($classIndex, $isAlphabet);
    $kioskId = "{$teacherUsername}{$separator}{$suffix}";

    // 전역 중복 방지 안전 가드 루프
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM kiosk_accounts WHERE kiosk_id = :kid");
    while (true) {
        $checkStmt->execute(['kid' => $kioskId]);
        if ((int)$checkStmt->fetchColumn() === 0) {
            break;
        }
        $classIndex++;
        $suffix = $makeSuffix($classIndex, $isAlphabet);
        $kioskId = "{$teacherUsername}{$separator}{$suffix}";
    }

    // 5. DB 삽입
    $ins = $pdo->prepare("INSERT INTO kiosk_accounts (class_id, kiosk_id, created_at) VALUES (:cid, :kid, NOW())");
    $ins->execute([
        'cid' => $classId,
        'kid' => $kioskId
    ]);

    jsonSuccess([
        'account_id' => $pdo->lastInsertId(),
        'kiosk_id'   => $kioskId
    ], '키오스크 계정이 성공적으로 발급되었습니다.');

} catch (\Exception $e) {
    jsonError('계정 생성 오류: ' . $e->getMessage());
}
