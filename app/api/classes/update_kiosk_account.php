<?php
/**
 * api/classes/update_kiosk_account.php
 * 키오스크 ID는 충돌 방지 교차 규칙에 의해 순차적으로 자동 발급되므로 수동 수정을 제한합니다.
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

jsonError('키오스크 ID는 충돌 방지를 위해 시스템에서 자동 발급되며 임의 수정이 제한됩니다. 필요 시 삭제 후 다시 생성해 주세요.', 400);
