<?php
/**
 * api/templates/auto_extract.php
 * Upstage Document Parse API 기반 시험지 문항 및 ROI 자동 추출 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/upstage.php';

$userId = requireTeacherAuth();

$isMultipart = !empty($_FILES);
$templateId = 0;
$targetPdfPath = null;
$cleanupTemp = false;

if ($isMultipart) {
    $templateId = (int)($_POST['template_id'] ?? 0);
    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['pdf_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $targetPdfPath = $tmpName;
        }
    }
} else {
    $input = getJsonInput();
    $templateId = (int)($input['template_id'] ?? 0);
}

if (!$templateId && !$targetPdfPath) {
    jsonError('템플릿 ID 또는 PDF 파일이 필요합니다.', 400);
}

// 템플릿 정보 및 소유권 확인
$template = null;
if ($templateId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM templates WHERE id = :id AND user_id = :u");
    $stmt->execute(['id' => $templateId, 'u' => $userId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        jsonError('존재하지 않거나 권한이 없는 템플릿입니다.', 403);
    }

    // 서버에 저장된 원본 PDF 확인
    if (!$targetPdfPath) {
        $username = $_SESSION['username'] ?? ('user_' . $userId);
        $candidatePdf = APP_ROOT . "/template/images/{$username}/tpl_{$templateId}_original.pdf";
        if (file_exists($candidatePdf)) {
            $targetPdfPath = $candidatePdf;
        }
    }
}

if (!$targetPdfPath || !file_exists($targetPdfPath)) {
    jsonError('분석할 PDF 원본 파일이 서버에 존재하지 않습니다. PDF 파일을 업로드해주세요.', 404);
}

// Upstage API 파싱 실행
$upstageResult = parseDocumentWithUpstage($targetPdfPath);
if (!$upstageResult) {
    jsonError('Upstage AI 문서 파싱에 실패했습니다. 잠시 후 다시 시도해주세요.', 502);
}

// pages_data 로드
$pagesData = [];
if ($template && !empty($template['pages_data'])) {
    $pagesData = json_decode($template['pages_data'], true) ?: [];
}

// 방안 A: 캐시된 PDF.js 글리프(괄호/번호) 데이터 또는 요청 입력값 로드
$pageGlyphs = [];
if ($templateId > 0) {
    $username = $_SESSION['username'] ?? ('user_' . $userId);
    $candidateGlyphs = APP_ROOT . "/template/images/{$username}/tpl_{$templateId}_glyphs.json";
    if (file_exists($candidateGlyphs)) {
        $pageGlyphs = json_decode(file_get_contents($candidateGlyphs), true) ?: [];
    }
}
if (!empty($_POST['page_glyphs'])) {
    $pageGlyphs = json_decode($_POST['page_glyphs'], true) ?: $pageGlyphs;
} elseif (!empty($input['page_glyphs'])) {
    $pageGlyphs = $input['page_glyphs'];
}

// ROI 산출 (방안 A 적용)
$extractedRois = extractRoisFromUpstageData($upstageResult, $pagesData, $pageGlyphs);

// 선잇기 문항 감지 시 Gemini Vision 자동 크롭 및 정밀 분석 실행 (문항 시작~다음 문항 직전 영역 분석)
require_once APP_ROOT . '/includes/common/gemini_matching.php';
autoAnalyzeMatchingRoisServerSide($extractedRois, $pagesData);

// 인적사항 기재란 정밀 마스킹 영역 탐지 (Upstage + PDF.js 글리프 교차 융합)
$privacyMaskRois = detectStudentInfoMaskRois($upstageResult, $pagesData, $pageGlyphs);

// 템플릿 ID가 있는 경우 DB 자동 업데이트 (ROI 추출 성공 여부와 무관하게 privacy_mask_data는 항상 저장)
if ($templateId > 0) {
    // 기존 DB에 저장되어 있던 정답 정보가 있다면 새로 추출된 ROI에 병합 보존
    $existingRois = json_decode($template['roi_data'] ?? '[]', true) ?: [];
    $existingMap = [];
    foreach ($existingRois as $er) {
        $eKey = (string)($er['display_num'] ?? $er['id'] ?? '');
        if ($eKey !== '') $existingMap[$eKey] = $er;
    }
    foreach ($extractedRois as &$nr) {
        $nKey = (string)($nr['display_num'] ?? $nr['id'] ?? '');
        if (isset($existingMap[$nKey])) {
            $old = $existingMap[$nKey];
            if (!empty($old['answer'])) $nr['answer'] = $old['answer'];
            if (isset($old['score']) && $old['score'] > 0) $nr['score'] = $old['score'];
            if (isset($old['has_partial_score'])) $nr['has_partial_score'] = $old['has_partial_score'];
            if (!empty($old['sub_scores'])) $nr['sub_scores'] = $old['sub_scores'];
            if (!empty($old['criteria'])) $nr['criteria'] = $old['criteria'];
            if (!empty($old['pairs'])) $nr['pairs'] = $old['pairs'];
            if (!empty($old['matching_direction'])) $nr['matching_direction'] = $old['matching_direction'];
            if (!empty($old['matching_info'])) $nr['matching_info'] = $old['matching_info'];
        }
    }
    unset($nr);

    $roiJson = !empty($extractedRois) ? json_encode($extractedRois, JSON_UNESCAPED_UNICODE) : null;
    $maskJson = !empty($privacyMaskRois) ? json_encode($privacyMaskRois, JSON_UNESCAPED_UNICODE) : null;
    $upStmt = $pdo->prepare("UPDATE templates SET roi_data = :roi, privacy_mask_data = :mask WHERE id = :id");
    $upStmt->execute(['roi' => $roiJson, 'mask' => $maskJson, 'id' => $templateId]);
}

if (empty($extractedRois)) {
    jsonError('시험지에서 문항 번호 및 영역을 감지하지 못했습니다.', 422);
}

jsonSuccess([
    'template_id'        => $templateId,
    'total_questions'    => count($extractedRois),
    'rois'               => $extractedRois,
    'privacy_mask_rois'  => $privacyMaskRois
], 'AI 문항 및 ROI 자동 추출이 완료되었습니다.');
