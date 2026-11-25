<?php
/**
 * api/templates/analyze_matching.php
 * 선잇기(Matching Line) 문항 Gemini Vision 정밀 분석 API
 */

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/common/gemini_matching.php';

$userId = requireTeacherAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_length()) ob_clean();
    jsonError('POST 요청만 허용됩니다.', 405);
}

$input = getJsonInput();
$imageBase64 = $input['image_base64'] ?? '';
$answerKey   = (string)($input['answer_key'] ?? '');

if (empty($imageBase64)) {
    if (ob_get_length()) ob_clean();
    jsonError('분석할 문항 크롭 이미지가 누락되었습니다.', 400);
}

$res = analyzeMatchingQuestionWithGemini($imageBase64, $answerKey);

if (ob_get_length()) ob_clean();

if (!$res['success']) {
    jsonError($res['error'] ?? '선잇기 분석에 실패했습니다.');
}

jsonSuccess(array_merge($res['data'], ['data' => $res['data']]), '선잇기 문항 분석이 완료되었습니다.');
