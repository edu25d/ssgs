<?php
/**
 * api/google/restore_sheet.php
 * 구글 시트 및 드라이브 원본 파일로부터 채점 데이터 및 크롭 이미지 복원 (Zero-PII 직결 방식)
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

$assignmentId = (int)($_REQUEST['assign_id'] ?? 0);
if (!$assignmentId) {
    jsonError('과제 ID가 누락되었습니다.');
}

try {
    $accessToken = getValidAccessToken($pdo, $userId);
    if (!$accessToken) {
        throw new Exception("구글 드라이브 연동이 만료되었거나 연결되어 있지 않습니다.");
    }

    $assignStmt = $pdo->prepare("
        SELECT a.class_id, c.class_name, t.title AS exam_name, c.sheet_id, c.drive_folder_id, t.roi_data, t.pages_data, t.image_path 
        FROM assignments a 
        JOIN classes c ON a.class_id = c.id
        JOIN templates t ON a.template_id = t.id 
        WHERE a.id = :id AND c.user_id = :user_id
    ");
    $assignStmt->execute(['id' => $assignmentId, 'user_id' => $userId]);
    $assignment = $assignStmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment || empty($assignment['sheet_id'])) {
        throw new Exception("배정 정보나 연결된 학급 시트를 찾을 수 없습니다.");
    }

    // 템플릿 원본 페이지별 물리 해상도 맵 구축 (SSOT Contract A)
    $templatePages = json_decode($assignment['pages_data'] ?? '[]', true) ?: [];
    $tplPageDims = [];
    foreach ($templatePages as $p) {
        $pNum = (int)($p['page_num'] ?? 1);
        $tW   = (int)($p['width'] ?? 0);
        $tH   = (int)($p['height'] ?? 0);
        if ($tW > 0 && $tH > 0) {
            $tplPageDims[$pNum] = ['w' => $tW, 'h' => $tH];
        }
    }
    if (empty($tplPageDims) && !empty($assignment['image_path'])) {
        $cleanTpl = ltrim($assignment['image_path'], '/');
        $tplImgPath = APP_ROOT . '/' . $cleanTpl;
        if (file_exists($tplImgPath)) {
            $sz = @getimagesize($tplImgPath);
            if ($sz) {
                $tplPageDims[1] = ['w' => (int)$sz[0], 'h' => (int)$sz[1]];
            }
        }
    }

    $studentRows = readExamScoresFromSheet($accessToken, $assignment['sheet_id'], $assignment['exam_name']);

    $classFolderId = $assignment['drive_folder_id'];
    $examName      = $assignment['exam_name'];
    
    // 구글 드라이브 폴더 계층 ({classFolderId} -> 'sheet' -> {$examName})
    $sheetFolderId = getDriveFolderIdByName($accessToken, $classFolderId, 'sheet');
    $targetFolderId = null;
    if ($sheetFolderId) {
        $targetFolderId = getDriveFolderIdByName($accessToken, $sheetFolderId, $examName);
    }

    $cropDir     = APP_ROOT . "/public/uploads/crops/{$assignmentId}/";
    $originalDir = APP_ROOT . "/public/uploads/originals/{$assignmentId}/";
    if (!is_dir($cropDir))     @mkdir($cropDir, 0777, true);
    if (!is_dir($originalDir)) @mkdir($originalDir, 0777, true);

    $roiList = json_decode($assignment['roi_data'], true) ?: [];
    $pdo->beginTransaction();

    // 클래스 솔트 조회 (restore_sheet.php student_hash 조회용)
    $classSaltStmt = $pdo->prepare("SELECT class_salt FROM classes WHERE id = ?");
    $classSaltStmt->execute([$assignment['class_id']]);
    $classSalt = (string)$classSaltStmt->fetchColumn();

    foreach ($studentRows as $student) {
        $studentName = $student['student_name'];
        $reconstructedResults = json_decode($student['raw_results_json'], true) ?: [];

        $stuStmt = $pdo->prepare("SELECT id FROM students WHERE class_id = :class_id AND student_hash = :hash");
        $stuStmt->execute(['class_id' => $assignment['class_id'], 'hash' => makeStudentHash($classSalt, $studentName)]);
        $studentData = $stuStmt->fetch(PDO::FETCH_ASSOC);
        
        $studentId = $studentData['id'] ?? null;
        if (!$studentId) continue;

        // 1. 구글 시트의 raw_results_json 내에서 페이지별 drive_file_id 맵 구축
        $pageDriveMap = []; // pNum => drive_file_id
        if (is_array($reconstructedResults)) {
            foreach ($reconstructedResults as $rItem) {
                $pNum = (int)($rItem['page'] ?? 1);
                if (!empty($rItem['drive_file_id'])) {
                    $pageDriveMap[$pNum] = $rItem['drive_file_id'];
                }
            }
        }

        // 2. drive_file_id 누락 시 비식별 가명 파일명 규칙으로 드라이브 폴백 검색
        $hashKey = substr(hash('sha256', (string)$studentId . '_' . (string)$assignmentId), 0, 10);
        if ($targetFolderId) {
            // 단일쪽 파일 폴백
            if (empty($pageDriveMap[1])) {
                $anonFileName = sprintf("%s_s_%s.jpg", $examName, $hashKey);
                $foundId = getDriveFileIdByName($accessToken, $targetFolderId, $anonFileName);
                if ($foundId) {
                    $pageDriveMap[1] = $foundId;
                } else {
                    // 레거시 실명 파일명 폴백
                    $legacyFileName = "{$examName}-{$studentName}.jpg";
                    $legacyId = getDriveFileIdByName($accessToken, $targetFolderId, $legacyFileName);
                    if ($legacyId) $pageDriveMap[1] = $legacyId;
                }
            }

            // 다쪽 파일 폴백 (p1, p2, p3...)
            for ($p = 1; $p <= max(2, count($tplPageDims)); $p++) {
                if (empty($pageDriveMap[$p])) {
                    $multiAnon = sprintf("%s_s_%s_p%d.jpg", $examName, $hashKey, $p);
                    $multiId = getDriveFileIdByName($accessToken, $targetFolderId, $multiAnon);
                    if ($multiId) {
                        $pageDriveMap[$p] = $multiId;
                    }
                }
            }
        }

        if (empty($pageDriveMap)) continue;

        // 3. 학생의 페이지별 원본 파일 다운로드 및 메모리 로드
        $pageImages = [];
        $pageDims = [];
        $pageOriginalUrls = [];

        foreach ($pageDriveMap as $pNum => $dFileId) {
            $isMulti = count($pageDriveMap) > 1;
            $origFileName = $isMulti ? "student_restored_{$studentId}_p{$pNum}.jpg" : "student_restored_{$studentId}.jpg";
            $savePath = $originalDir . $origFileName;

            if (!downloadDriveFile($accessToken, $dFileId, $savePath)) {
                continue;
            }

            $img = @imagecreatefromjpeg($savePath);
            if ($img) {
                $pageImages[$pNum] = $img;
                $pageDims[$pNum] = ['w' => imagesx($img), 'h' => imagesy($img)];
                $pageOriginalUrls[$pNum] = "/public/uploads/originals/{$assignmentId}/" . $origFileName;
            }
        }

        if (empty($pageImages)) continue;

        // 4. submit_images.php와 100% 동일한 정밀 2차원 스케일 크롭 수행
        foreach ($roiList as $idx => $roi) {
            $rawId = $roi['id'] ?? $roi['item_id'] ?? $roi['num'] ?? ($idx + 1);
            $subId = $roi['sub_id'] ?? null;
            $displayNum = (string)($roi['display_num'] ?? ($subId ? "{$rawId}-{$subId}" : (string)$rawId));
            $itemId = $displayNum;
            $targetPage = (int)($roi['page'] ?? 1);
            if ($targetPage < 1) $targetPage = 1;

            $srcImage = $pageImages[$targetPage] ?? ($pageImages[1] ?? reset($pageImages));
            if (!$srcImage) continue;

            $imgW = $pageDims[$targetPage]['w'] ?? imagesx($srcImage);
            $imgH = $pageDims[$targetPage]['h'] ?? imagesy($srcImage);

            $tplW = $tplPageDims[$targetPage]['w'] ?? ($tplPageDims[1]['w'] ?? 1200);
            $tplH = $tplPageDims[$targetPage]['h'] ?? ($tplPageDims[1]['h'] ?? 1700);
            $scaleX = $imgW / (float)$tplW;
            $scaleY = $imgH / (float)$tplH;

            $type = strtolower($roi['type'] ?? 'objective');
            $isComposite = ($type === 'composite');

            if ($isComposite && !empty($roi['ans_rois']) && is_array($roi['ans_rois']) && count($roi['ans_rois']) >= 2) {
                // 복합 다답형 대표 이미지 복원
                $compositeSubCrops = [];
                foreach ($roi['ans_rois'] as $arIdx => $ar) {
                    if (!isset($ar['x'], $ar['y'], $ar['w'], $ar['h'])) continue;
                    $arW = $ar['w'] * $scaleX;
                    $arH = $ar['h'] * $scaleY;
                    $arCx = ($ar['x'] * $scaleX) + ($arW / 2);
                    $arCy = ($ar['y'] * $scaleY) + ($arH / 2);
                    $arNewW = $arW * 1.15;
                    $arNewH = $arH * 1.15;
                    $arNewX = max(0, min($arCx - ($arNewW / 2), $imgW - 10));
                    $arNewY = max(0, min($arCy - ($arNewH / 2), $imgH - 10));
                    $arNewW = max(10, min($arNewW, $imgW - $arNewX));
                    $arNewH = max(10, min($arNewH, $imgH - $arNewY));
                    $arCropRes = @imagecrop($srcImage, ['x' => (int)$arNewX, 'y' => (int)$arNewY, 'width' => (int)$arNewW, 'height' => (int)$arNewH]);
                    if ($arCropRes === false) $arCropRes = imagecreatetruecolor(10, 10);
                    $compositeSubCrops[] = $arCropRes;
                }
                $safeItemKey = str_replace(['-', ' '], ['_', ''], $itemId);
                $repFileName = sprintf("student_%d_item_%s_p%d_%s.jpg", $studentId, $safeItemKey, $targetPage, uniqid());
                $repSavePath = $cropDir . $repFileName;
                if (!empty($compositeSubCrops)) {
                    $pad = 6;
                    $combinedW = array_sum(array_map('imagesx', $compositeSubCrops)) + ($pad * (count($compositeSubCrops) + 1));
                    $maxH = max(array_map('imagesy', $compositeSubCrops));
                    $combinedH = $maxH + ($pad * 2);
                    $combinedRes = imagecreatetruecolor($combinedW, $combinedH);
                    $bgCol = imagecolorallocate($combinedRes, 248, 250, 252);
                    imagefill($combinedRes, 0, 0, $bgCol);
                    $borderCol = imagecolorallocate($combinedRes, 203, 213, 225);
                    $curX = $pad;
                    foreach ($compositeSubCrops as $ci => $subCr) {
                        $sW = imagesx($subCr);
                        $sH = imagesy($subCr);
                        imagecopy($combinedRes, $subCr, $curX, $pad, 0, 0, $sW, $sH);
                        imagerectangle($combinedRes, $curX - 1, $pad - 1, $curX + $sW, $pad + $sH, $borderCol);
                        imagedestroy($subCr);
                        $curX += $sW + $pad;
                    }
                    imagejpeg($combinedRes, $repSavePath, 85);
                    imagedestroy($combinedRes);
                }
                $cropUrl = "/public/uploads/crops/{$assignmentId}/" . $repFileName;
            } else {
                $targetRoi = $roi['ans_roi'] ?? $roi['num_roi'] ?? $roi;
                if (!isset($targetRoi['x'], $targetRoi['y'], $targetRoi['w'], $targetRoi['h'])) continue;

                $w = $targetRoi['w'] * $scaleX;
                $h = $targetRoi['h'] * $scaleY;
                $cx = ($targetRoi['x'] * $scaleX) + ($w / 2);
                $cy = ($targetRoi['y'] * $scaleY) + ($h / 2);
                
                $newW = $w * 1.15;
                $newH = $h * 1.15;
                $newX = max(0, min($cx - ($newW / 2), $imgW - 10));
                $newY = max(0, min($cy - ($newH / 2), $imgH - 10));
                $newW = max(10, min($newW, $imgW - $newX));
                $newH = max(10, min($newH, $imgH - $newY));

                $cropRes = @imagecrop($srcImage, ['x' => (int)$newX, 'y' => (int)$newY, 'width' => (int)$newW, 'height' => (int)$newH]);
                if ($cropRes === false) {
                    $cropRes = imagecreatetruecolor(10, 10);
                }

                $safeItemKey = str_replace(['-', ' '], ['_', ''], $itemId);
                $cropFileName = sprintf("student_%d_item_%s_p%d_%s.jpg", $studentId, $safeItemKey, $targetPage, uniqid());
                imagejpeg($cropRes, $cropDir . $cropFileName, 90);
                imagedestroy($cropRes);

                $cropUrl = "/public/uploads/crops/{$assignmentId}/" . $cropFileName;
            }

            $fullImageUrl = $pageOriginalUrls[$targetPage] ?? reset($pageOriginalUrls);
            $currDriveId = $pageDriveMap[$targetPage] ?? reset($pageDriveMap);

            if (is_array($reconstructedResults)) {
                foreach ($reconstructedResults as &$resItem) {
                    $resId = (string)($resItem['display_num'] ?? $resItem['id'] ?? $resItem['item_id'] ?? '');
                    $isMatch = ($resId === $itemId);
                    if (!$isMatch && empty($subId) && ($resId === (string)$rawId)) {
                        $isMatch = true;
                    }
                    if ($isMatch) {
                        $resItem['crop_url']      = $cropUrl;
                        $resItem['image_path']    = $fullImageUrl;
                        $resItem['drive_file_id'] = $currDriveId;
                    }
                }
                unset($resItem);
            }
        }

        // 메모리 해제
        foreach ($pageImages as $imgHandle) {
            if (is_resource($imgHandle) || (is_object($imgHandle) && $imgHandle instanceof GdImage)) {
                imagedestroy($imgHandle);
            }
        }

        // 이전 결과 삭제 후 재삽입
        $delStmt = $pdo->prepare("DELETE FROM exam_results WHERE assignment_id = :assign_id AND student_id = :student_id");
        $delStmt->execute(['assign_id' => $assignmentId, 'student_id' => $studentId]);

        $insStmt = $pdo->prepare("
            INSERT INTO exam_results (assignment_id, student_id, total_score, is_reviewed, raw_results, submitted_at) 
            VALUES (:assign_id, :student_id, :total_score, 0, :raw_results, NOW())
        ");
        $insStmt->execute([
            'assign_id'   => $assignmentId,
            'student_id'  => $studentId,
            'total_score' => $student['total_score'],
            'raw_results' => json_encode($reconstructedResults, JSON_UNESCAPED_UNICODE)
        ]);
    }

    $statusStmt = $pdo->prepare("UPDATE assignments SET status = 'in_progress' WHERE id = :assign_id");
    $statusStmt->execute(['assign_id' => $assignmentId]);

    $pdo->commit();
    jsonSuccess([], '데이터 및 이미지가 성공적으로 복원되었습니다.');

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('복원 실패: ' . $e->getMessage());
}
