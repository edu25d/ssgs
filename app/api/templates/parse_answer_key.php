<?php
/**
 * api/templates/parse_answer_key.php
 * 이원목적분류표 / 정답표 자동 파싱 및 템플릿 ROI 즉시 바인딩 API
 */

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/common/answer_key_parser.php';

$userId = requireTeacherAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_length()) ob_clean();
    jsonError('POST 요청만 허용됩니다.', 405);
}

$templateId = (int)($_POST['template_id'] ?? 0);
$answerKeyText = trim($_POST['answer_key_text'] ?? '');
$parsedAnswers = [];

try {
    // 1. 파일 업로드 처리 (PDF 또는 이미지)
    if (isset($_FILES['answer_key_file']) && $_FILES['answer_key_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['answer_key_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowedExts)) {
            if (ob_get_length()) ob_clean();
            jsonError('지원하지 않는 파일 형식입니다. (PDF, JPG, PNG 파일만 지원)');
        }

        $username = $_SESSION['username'] ?? ('user_' . $userId);
        $uploadDir = APP_ROOT . "/template/images/{$username}/";
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $tmpSaveName = "ans_key_" . uniqid() . '.' . $ext;
        $tmpSavePath = $uploadDir . $tmpSaveName;

        if (!move_uploaded_file($file['tmp_name'], $tmpSavePath)) {
            if (ob_get_length()) ob_clean();
            jsonError('정답표 파일 저장에 실패했습니다.');
        }

        $parsedAnswers = parseAnswerKeyPdf($tmpSavePath);
        // 임시 파일 정리
        @unlink($tmpSavePath);

    } elseif ($answerKeyText !== '') {
        // 2. 텍스트 / 표 복사-붙여넣기 데이터 처리
        $parsedAnswers = parseAnswerKeyText($answerKeyText);
    } else {
        if (ob_get_length()) ob_clean();
        jsonError('정답표 파일 또는 텍스트 데이터를 제공해주세요.');
    }

    if (empty($parsedAnswers)) {
        if (ob_get_length()) ob_clean();
        jsonError('정답표에서 유효한 문항 정답 데이터를 추출하지 못했습니다. 파일 형태를 확인해주세요.');
    }

    $boundRois = null;

    // 3. template_id가 전달된 경우 현재 템플릿의 roi_data와 즉시 바인딩 및 저장
    if ($templateId > 0) {
        $stmt = $pdo->prepare("SELECT id, roi_data, pages_data FROM templates WHERE id = :id AND user_id = :uid");
        $stmt->execute(['id' => $templateId, 'uid' => $userId]);
        $tpl = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tpl) {
            $existingRois = json_decode($tpl['roi_data'] ?? '[]', true) ?: [];
            if (!empty($existingRois)) {
                $boundRois = bindAnswerKeyToRois($existingRois, $parsedAnswers);

                // 선잇기 문항 감지 시 Gemini Vision 자동 크롭 및 정밀 분석 실행
                $pagesData = json_decode($tpl['pages_data'] ?? '[]', true) ?: [];
                require_once APP_ROOT . '/includes/common/gemini_matching.php';
                autoAnalyzeMatchingRoisServerSide($boundRois, $pagesData);

                $upStmt = $pdo->prepare("UPDATE templates SET roi_data = :roi WHERE id = :id");
                $upStmt->execute([
                    'roi' => json_encode($boundRois, JSON_UNESCAPED_UNICODE),
                    'id'  => $templateId
                ]);
            }
        }
    }

    if (ob_get_length()) ob_clean();
    jsonSuccess([
        'parsed_count'   => count($parsedAnswers),
        'parsed_answers' => $parsedAnswers,
        'bound_rois'     => $boundRois,
        'template_id'    => $templateId
    ], '정답표가 성공적으로 파싱 및 적용되었습니다.');

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    error_log("[parse_answer_key.php Error] " . $e->getMessage());
    jsonError('정답표 처리 중 오류가 발생했습니다: ' . $e->getMessage());
}
