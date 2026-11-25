<?php
/**
 * api/templates/save.php
 * 템플릿 ROI 및 문항 정답/배점 설정 저장 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';

$userId = requireTeacherAuth();

$inputData  = getJsonInput();
$templateId = (int)($inputData['template_id'] ?? 0);
$roiData    = $inputData['roi_data'] ?? null;
$pagesData  = $inputData['pages_data'] ?? null;
$privacyMaskData = $inputData['privacy_mask_data'] ?? null;

if (!$templateId || !is_array($roiData)) {
    jsonError('올바르지 않은 데이터 형식입니다.', 400);
}

// 소유권 확인
$stmt = $pdo->prepare("SELECT id, image_path FROM templates WHERE id = :id AND user_id = :u");
$stmt->execute(['id' => $templateId, 'u' => $userId]);
$templateRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$templateRow) {
    jsonError('해당 템플릿에 대한 수정 권한이 없습니다.', 403);
}

// SSOT Contract B 정규화: roi_data 내 page 및 page_uid 무결성 보장
$normalizedRoi = [];
foreach ($roiData as $idx => $item) {
    if (!is_array($item)) continue;
    $item['id'] = (int)($item['id'] ?? ($idx + 1));
    $item['page'] = (int)($item['page'] ?? 1);
    if ($item['page'] < 1) $item['page'] = 1;
    if (empty($item['page_uid'])) {
        $item['page_uid'] = 'p_' . $item['page'];
    }
    // Phase 3 확장 필드 무결성 보장
    // 등록/editor에서 결정된 type을 그대로 유지한다. 비어 있을 때만 Type System 기준 기본값으로 채운다.
    // sub_type은 소문항 구성/구분 등 보조 정보에만 사용하며, type과 동일하게 채우지 않는다.
    $item['type'] = (string)($item['type'] ?? 'objective');
    $item['sub_type'] = isset($item['sub_type']) ? (string)$item['sub_type'] : null;
    $item['answer'] = (string)($item['answer'] ?? '');
    $item['score'] = (int)($item['score'] ?? 0);
    $item['has_partial_score'] = (bool)($item['has_partial_score'] ?? false);
    if (!isset($item['sub_scores']) || !is_array($item['sub_scores'])) {
        $item['sub_scores'] = (object)[];
    }
    $item['criteria'] = (string)($item['criteria'] ?? '');
    if (isset($item['matching_direction'])) {
        $item['matching_direction'] = in_array($item['matching_direction'], ['vertical', 'horizontal'], true) ? $item['matching_direction'] : 'vertical';
    }
    if (isset($item['matching_info']) && is_array($item['matching_info'])) {
        $item['matching_info'] = $item['matching_info'];
    }
    $normalizedRoi[] = $item;
}
$roiJson = json_encode($normalizedRoi, JSON_UNESCAPED_UNICODE);

// SSOT Contract A 정규화: pages_data 처리
$pagesJson = null;
$firstPageImg = null;
if (is_array($pagesData) && !empty($pagesData)) {
    $normalizedPages = [];
    foreach ($pagesData as $pIdx => $pItem) {
        if (!is_array($pItem)) continue;
        $pageNum = (int)($pItem['page_num'] ?? ($pIdx + 1));
        $normalizedPages[] = [
            'page_uid'   => (string)($pItem['page_uid'] ?? ('p_' . $pageNum . '_' . substr(md5(uniqid()), 0, 4))),
            'page_num'   => $pageNum,
            'title'      => (string)($pItem['title'] ?? ($pageNum . '쪽')),
            'image_path' => (string)($pItem['image_path'] ?? ''),
            'width'      => (int)($pItem['width'] ?? 0),
            'height'     => (int)($pItem['height'] ?? 0),
        ];
    }
    if (!empty($normalizedPages)) {
        $pagesJson = json_encode($normalizedPages, JSON_UNESCAPED_UNICODE);
        if (!empty($normalizedPages[0]['image_path'])) {
            $firstPageImg = $normalizedPages[0]['image_path'];
        }
    }
}

try {
    $maskJson = null;
    if ($privacyMaskData !== null) {
        $maskJson = is_string($privacyMaskData) ? $privacyMaskData : json_encode($privacyMaskData, JSON_UNESCAPED_UNICODE);
    }

    if ($pagesJson !== null) {
        $updateSql = "UPDATE templates SET roi_data = :roi, pages_data = :pages" 
                   . ($firstPageImg ? ", image_path = :img" : "")
                   . ($privacyMaskData !== null ? ", privacy_mask_data = :mask" : "")
                   . " WHERE id = :id";
        $params = [
            'roi'   => $roiJson,
            'pages' => $pagesJson,
            'id'    => $templateId
        ];
        if ($firstPageImg) {
            $params['img'] = $firstPageImg;
        }
        if ($privacyMaskData !== null) {
            $params['mask'] = $maskJson;
        }
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);
    } else {
        $updateSql = "UPDATE templates SET roi_data = :roi"
                   . ($privacyMaskData !== null ? ", privacy_mask_data = :mask" : "")
                   . " WHERE id = :id";
        $params = [
            'roi' => $roiJson,
            'id'  => $templateId
        ];
        if ($privacyMaskData !== null) {
            $params['mask'] = $maskJson;
        }
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);
    }

    jsonSuccess(['template_id' => $templateId], '템플릿 설정이 성공적으로 저장되었습니다.');
} catch (PDOException $e) {
    jsonError('DB 저장 중 오류: ' . $e->getMessage(), 500);
}
