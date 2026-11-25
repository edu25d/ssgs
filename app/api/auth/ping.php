<?php
/**
 * api/auth/ping.php
 * 세션 활동 시간 갱신 (Heartbeat Ping) API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    jsonError('세션이 만료되었거나 비인가 상태입니다.', 401, ['authenticated' => false]);
}

$_SESSION['last_activity'] = time();
jsonSuccess(['authenticated' => true, 'user_id' => (int)$_SESSION['user_id']], '세션이 정상 유지 중입니다.');
