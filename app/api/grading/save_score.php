<?php
/**
 * api/grading/save_score.php
 * 채점 검토 화면에서 개별 문항 점수 및 정오(status) 수정 API
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';

$input = getJsonInput();
$resultId = (int)($input['db_id'] ?? 0);
$itemId   = (string)($input['item_id'] ?? '');
$score    = (float)($input['score'] ?? 0);
$status   = isset($input['status']) ? (int)$input['status'] : -1; 

if (!$resultId || $itemId === '') {
    jsonError('필수 파라미터가 누락되었습니다.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT raw_results, total_score FROM exam_results WHERE id = :id");
    $stmt->execute(['id' => $resultId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("결과를 찾을 수 없습니다.");
    }

    $rawResults = json_decode($row['raw_results'], true) ?: [];
    $allGraded = true;
    $newTotalScore = 0;

    foreach ($rawResults as &$res) {
        $curItemId    = (string)($res['item_id'] ?? $res['id'] ?? '');
        $curSubId     = isset($res['sub_id']) && $res['sub_id'] !== null ? (string)$res['sub_id'] : '';
        $curDisplay   = (string)($res['display_num'] ?? ($curSubId !== '' ? "{$curItemId}-{$curSubId}" : $curItemId));
        
        // display_num(예: 3-1), 순수 item_id(단일문항), 또는 복합 키 일치 확인
        $isMatch = ($curDisplay === $itemId) || ($curSubId === '' && $curItemId === $itemId);
        if (!$isMatch && isset($input['sub_id']) && (string)$input['sub_id'] === $curSubId && $curItemId === (string)($input['parent_id'] ?? $input['raw_id'] ?? '')) {
            $isMatch = true;
        }
        
        if ($isMatch) {
            $res['currentScore'] = $score;
            $res['status']       = $status;
            $res['is_manually_edited'] = true; // 교사 수기 수정 플래그 보존
            if (!empty($res['appeal'])) {
                $res['appeal_resolved'] = true;
            }
            
            unset($res['is_graded'], $res['score_awarded'], $res['isCorrect']);
        }
        
        if (!isset($res['status']) || $res['status'] === -1 || $res['status'] === '-1' || $res['status'] === 'pending') {
            $allGraded = false;
        }
        
        $newTotalScore += (float)($res['currentScore'] ?? 0);
    }
    unset($res);

    $isReviewed = $allGraded ? 1 : 0;
    
    $updateStmt = $pdo->prepare("
        UPDATE exam_results 
        SET raw_results = :raw, total_score = :score, is_reviewed = :rev 
        WHERE id = :id
    ");
    $updateStmt->execute([
        'raw'   => json_encode($rawResults, JSON_UNESCAPED_UNICODE),
        'score' => $newTotalScore,
        'rev'   => $isReviewed,
        'id'    => $resultId
    ]);

    $pdo->commit();
    jsonSuccess([
        'is_fully_reviewed' => $isReviewed, 
        'new_total'         => $newTotalScore,
        'total_score'       => $newTotalScore
    ], '점수가 저장되었습니다.');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('점수 저장 실패: ' . $e->getMessage());
}
