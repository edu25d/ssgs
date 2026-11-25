<?php
/**
 * api/templates/debug_mask.php
 * Upstage 인적사항 마스킹 탐지 진단 스크립트 (테스트/디버그용)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/upstage.php';

header('Content-Type: application/json; charset=utf-8');

$userId = requireTeacherAuth();
$templateId = (int)($_GET['id'] ?? 0);

try {
    if (!$templateId) {
        // 가장 최근 등록된 템플릿 조회
        $latestStmt = $pdo->prepare("SELECT id FROM templates WHERE user_id = :u ORDER BY id DESC LIMIT 1");
        $latestStmt->execute(['u' => $userId]);
        $templateId = (int)$latestStmt->fetchColumn();
    }

    if (!$templateId) {
        echo json_encode(['error' => '템플릿이 없습니다.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, user_id, title, image_path, pages_data, privacy_mask_data FROM templates WHERE id = :id AND user_id = :u");
    $stmt->execute(['id' => $templateId, 'u' => $userId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$template) {
        echo json_encode(['error' => '해당 템플릿을 찾을 수 없습니다. (ID: ' . $templateId . ')']);
        exit;
    }

    // 템플릿 파일 단일 직결 경로 확인
    $imagePath = $template['image_path'] ?? '';
    if (empty($imagePath)) {
        $pages = json_decode($template['pages_data'] ?? '[]', true) ?: [];
        $imagePath = $pages[0]['image_path'] ?? '';
    }

    $cleanRel = ltrim($imagePath, '/');
    $imgPhysPath = APP_ROOT . '/' . $cleanRel;
    $tplDir = dirname($imgPhysPath);
    $pdfPath = $tplDir . "/tpl_{$templateId}_original.pdf";

    $hasPdf = file_exists($pdfPath);
    $hasImg = file_exists($imgPhysPath);
    $targetFile = $hasPdf ? $pdfPath : ($hasImg ? $imgPhysPath : null);

    $diag = [
        'template_id'         => $templateId,
        'title'               => $template['title'],
        'db_privacy_mask_raw' => $template['privacy_mask_data'],
        'resolved_paths'      => [
            'pdf_path'        => $pdfPath,
            'pdf_exists'      => $hasPdf,
            'img_path'        => $imgPhysPath,
            'img_exists'      => $hasImg,
            'target_file_used'=> $targetFile,
            'target_file_size'=> $targetFile ? filesize($targetFile) : 0,
        ]
    ];

    if (!$targetFile) {
        $diag['error'] = '분석할 원본 파일(PDF 또는 이미지)이 디스크에 존재하지 않습니다.';
        echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Upstage API 파싱 실행
    $upstageData = parseDocumentWithUpstage($targetFile);
    if (!$upstageData) {
        $diag['error'] = 'Upstage API 파싱 실패 (응답 없음 또는 API 키 오류)';
        echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $elements = $upstageData['elements'] ?? [];
    $pagesData = json_decode($template['pages_data'] ?? '[]', true) ?: [];

    // 1쪽 엘리먼트들 전수 분석
    $p1Elements = [];
    $p1MaxX = 0; $p1MaxY = 0;
    foreach ($elements as $el) {
        if ((int)($el['page'] ?? 1) === 1 && !empty($el['coordinates'])) {
            $p1Elements[] = $el;
            foreach ($el['coordinates'] as $c) {
                if (isset($c['x']) && $c['x'] > $p1MaxX) $p1MaxX = (float)$c['x'];
                if (isset($c['y']) && $c['y'] > $p1MaxY) $p1MaxY = (float)$c['y'];
            }
        }
    }

    $isNorm = ($p1MaxX <= 1.05 && $p1MaxY <= 1.05);
    $baseH = $isNorm ? 1.0 : ($p1MaxY > 100 ? $p1MaxY : 1700);

    $analyzedElements = [];
    foreach ($p1Elements as $idx => $el) {
        $rawContent = $el['content'] ?? ($el['text'] ?? '');
        if (is_array($rawContent)) {
            $t = trim($rawContent['text'] ?? '');
            $text = ($t !== '') ? $t : strip_tags($rawContent['html'] ?? '');
        } else {
            $text = strip_tags((string)$rawContent);
        }
        $cleanText = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
        if ($cleanText === '') continue;

        $coords = $el['coordinates'] ?? [];
        $ys = array_column($coords, 'y');
        $minY = !empty($ys) ? min($ys) : 0;
        $normMinY = round($minY / $baseH, 4);

        $hasName = (bool)preg_match('/(이\s*름|성\s*명)/u', $cleanText);
        $hasStudentHeader = (bool)preg_match('/(학\s*년|반|번|번\s*호)/u', $cleanText);
        $hasSchool = (bool)preg_match('/(초등|학\s*교)/u', $cleanText);
        $isQNumber = (bool)preg_match('/^\s*\d{1,2}\s*[\.|\)|\s]/u', $cleanText);

        $analyzedElements[] = [
            'index'            => $idx,
            'category'         => $el['category'] ?? '',
            'text_preview'     => mb_substr($cleanText, 0, 40),
            'norm_y'           => $normMinY,
            'under_30_pct'     => ($normMinY <= 0.30),
            'matched_keywords' => array_filter([
                'name'    => $hasName,
                'header'  => $hasStudentHeader,
                'school'  => $hasSchool,
                'q_guard' => $isQNumber ? 'EXCLUDED(문항)' : null
            ])
        ];
    }

    $glyphsPath = $tplDir . "/tpl_{$templateId}_glyphs.json";
    $pageGlyphs = file_exists($glyphsPath) ? (json_decode(file_get_contents($glyphsPath), true) ?: []) : [];

    $diag['p1_total_elements'] = count($p1Elements);
    $diag['p1_scale_info'] = ['is_norm' => $isNorm, 'p1_max_x' => $p1MaxX, 'p1_max_y' => $p1MaxY];
    $diag['p1_raw_first_element'] = $p1Elements[0] ?? null;
    $diag['p1_elements_sample'] = $analyzedElements;
    $diag['glyphs_info'] = [
        'glyphs_file_exists'   => file_exists($glyphsPath),
        'student_glyphs_count' => count($pageGlyphs[1]['student_header_items'] ?? ($pageGlyphs['1']['student_header_items'] ?? [])),
        'title_glyphs_count'   => count($pageGlyphs[1]['title_items'] ?? ($pageGlyphs['1']['title_items'] ?? [])),
        'header_lines_count'   => count($pageGlyphs[1]['header_lines'] ?? ($pageGlyphs['1']['header_lines'] ?? []))
    ];

    // 최종 detectStudentInfoMaskRois 함수 실행 결과 (Upstage + PDF.js 글리프 교차 융합)
    $finalResult = detectStudentInfoMaskRois($upstageData, $pagesData, $pageGlyphs);
    $diag['detectStudentInfoMaskRois_result'] = $finalResult;

    // 만약 탐지 성공했으면 DB에 즉시 저장 (Self-Healing)
    if (!empty($finalResult)) {
        $upStmt = $pdo->prepare("UPDATE templates SET privacy_mask_data = :mask WHERE id = :id");
        $upStmt->execute(['mask' => json_encode($finalResult, JSON_UNESCAPED_UNICODE), 'id' => $templateId]);
        $diag['db_updated'] = true;
    } else {
        $diag['db_updated'] = false;
    }

    echo json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(['exception' => $e->getMessage()]);
}
