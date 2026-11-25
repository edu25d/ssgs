<?php
/**
 * api/auth/register.php
 * 교육청 구글 공식 계정 원스톱 가입으로 통합되어 일반 가입 API를 제한합니다.
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

jsonError('교사 회원가입은 교육청/학교 공식 구글 계정으로 원스톱 처리됩니다. 로그인 화면의 [교육청 공식 계정으로 시작하기]를 이용해 주세요.', 400);
