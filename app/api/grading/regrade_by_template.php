<?php
/**
 * api/grading/regrade_by_template.php
 * 검수 시작 이후 템플릿의 정답 또는 배점이 수정되었을 때
 * 교사의 수기 검수 결과를 보호하면서 최신 템플릿 기준으로 전체 학생 채점 결과를 스마트 동기화/재채점
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/response.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/grading/objective.php';

$userId = requireTeacherAuth();

$input = getJsonInput();
$assignmentId = (int)($input['assignment_id'] ?? $_POST['assignment_id'] ?? $_GET['assignment_id'] ?? 0);

if (!$assignmentId) {
    jsonError('평가 ID가 유효하지 않습니다.');
}

try {
    // 1. 과제 및 템플릿 소유권 확인 & 최신 roi_data 로드
    $stmt = $pdo->prepare("
        SELECT a.id, a.template_id, t.roi_data, t.title AS template_title
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = :aid AND (t.user_id = :t_uid OR c.user_id = :c_uid)
    ");
    $stmt->execute(['aid' => $assignmentId, 't_uid' => $userId, 'c_uid' => $userId]);
    $assignRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignRow) {
        jsonError('과제를 찾을 수 없거나 열람/수정 권한이 없습니다.', 403);
    }

    $templateRois = json_decode($assignRow['roi_data'] ?? '[]', true) ?: [];
    if (empty($templateRois)) {
        jsonError('템플릿 문항 설정이 비어있습니다.');
    }

    // 템플릿 ROI 맵 생성 (display_num 및 raw_id+sub_id 키)
    $roiMap = [];
    foreach ($templateRois as $idx => $r) {
        $rawId = (int)($r['id'] ?? ($idx + 1));
        $subId = isset($r['sub_id']) && $r['sub_id'] !== null ? (string)$r['sub_id'] : '';
        $dispNum = (string)($r['display_num'] ?? ($subId !== '' ? "{$rawId}-{$subId}" : (string)$rawId));

        $roiInfo = [
            'raw_id'            => $rawId,
            'sub_id'            => $subId !== '' ? $subId : null,
            'display_num'       => $dispNum,
            'score'             => (float)($r['score'] ?? 0),
            'answer'            => trim((string)($r['answer'] ?? '')),
            'type'              => strtolower((string)($r['type'] ?? 'objective')),
            'has_partial_score' => !empty($r['has_partial_score']),
            'sub_scores'        => $r['sub_scores'] ?? [],
            'criteria'          => (string)($r['criteria'] ?? ''),
            'pairs'             => $r['pairs'] ?? []
        ];

        $roiMap[$dispNum] = $roiInfo;
        if ($subId !== '') {
            $roiMap["{$rawId}_{$subId}"] = $roiInfo;
        } else {
            $roiMap[(string)$rawId] = $roiInfo;
        }
    }

    // 2. 해당 과제의 모든 학생 시험 결과 조회
    $resStmt = $pdo->prepare("SELECT id, student_id, total_score, raw_results, is_reviewed FROM exam_results WHERE assignment_id = :aid");
    $resStmt->execute(['aid' => $assignmentId]);
    $examResults = $resStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($examResults)) {
        jsonSuccess(['updated_students' => 0], '제출된 학생 시험지가 없습니다.');
    }

    $pdo->beginTransaction();

    $updatedStudentCount = 0;
    $totalRecheckedCount = 0; // 배점 변경으로 pending 전환된 문항 수
    $totalRegradedCount  = 0; // 정답 변경 등으로 재판정된 문항 수

    $updateStmt = $pdo->prepare("UPDATE exam_results SET raw_results = :raw, total_score = :total, is_reviewed = :rev WHERE id = :id");

    foreach ($examResults as $resRow) {
        $recordId = (int)$resRow['id'];
        $rawList = json_decode($resRow['raw_results'] ?? '[]', true) ?: [];
        if (empty($rawList)) continue;

        $allGraded = true;
        $newTotalScore = 0.0;

        foreach ($rawList as &$item) {
            $rawId = (string)($item['item_id'] ?? $item['id'] ?? '');
            $subId = isset($item['sub_id']) && $item['sub_id'] !== null ? (string)$item['sub_id'] : '';
            $dispNum = (string)($item['display_num'] ?? ($subId !== '' ? "{$rawId}-{$subId}" : $rawId));

            // 최신 템플릿 ROI 탐색
            $tpl = $roiMap[$dispNum] ?? ($subId !== '' ? ($roiMap["{$rawId}_{$subId}"] ?? null) : ($roiMap[$rawId] ?? null));
            if (!$tpl) {
                $newTotalScore += (float)($item['currentScore'] ?? 0);
                continue;
            }

            $oldScore = (float)($item['score'] ?? 0);
            $newScore = (float)$tpl['score'];
            $oldAnswer = trim((string)($item['answer'] ?? ''));
            $newAnswer = trim((string)$tpl['answer']);
            $isManuallyEdited = !empty($item['is_manually_edited']);
            $currentStatus = isset($item['status']) ? (int)$item['status'] : -1;

            $item['criteria'] = $tpl['criteria'];
            $item['score']    = $newScore;

            // A. 배점이 변경된 경우
            if (abs($oldScore - $newScore) > 0.001) {
                if ($currentStatus === 2) {
                    // [확정 B안] 기존 부분점수 문항은 pending(재검수 필요) 전환!
                    $item['status']        = -1;
                    $item['currentScore']  = 0.0;
                    $item['appeal']        = true;
                    $item['appeal_reason'] = "배점 변경({$oldScore}점 ➔ {$newScore}점): 재검수 필요";
                    $totalRecheckedCount++;
                } elseif ($currentStatus === 1) {
                    // 기존 만점 문항은 새 만점으로 자동 갱신
                    $item['currentScore'] = $newScore;
                    $totalRegradedCount++;
                } else {
                    $item['currentScore'] = 0.0;
                }
            }

            // B. 정답이 변경된 경우
            if ($oldAnswer !== $newAnswer && $newAnswer !== '') {
                $item['answer'] = $newAnswer;

                if ($isManuallyEdited) {
                    // 교사 수기 검수 결과 보존
                    $item['appeal'] = true;
                    $item['appeal_reason'] = "정답 수정됨: 모범답안 '{$newAnswer}' 확인 필요";
                } else {
                    $type = strtolower($item['type'] ?? 'objective');
                    if ($type === 'matching_line') {
                        $modelPairs = [];
                        if (preg_match_all('/\(([\d\w가-힣]+)\)\s*[-~–—]\s*([㉠-㉭㉮-㉻\w가-힣]+)/u', $newAnswer, $m)) {
                            for ($k = 0; $k < count($m[0]); $k++) {
                                $modelPairs[] = ['from' => '(' . trim($m[1][$k]) . ')', 'to' => trim($m[2][$k])];
                            }
                        }
                        $detPairs = $item['detected_pairs'] ?? [];
                        $mCount = 0;
                        $tCount = count($modelPairs);
                        $summaryParts = [];
                        foreach ($modelPairs as $mp) {
                            $f = trim($mp['from']); $t = trim($mp['to']);
                            $found = false;
                            foreach ($detPairs as $dp) {
                                if (trim($dp['from']) === $f && trim($dp['to']) === $t) {
                                    $found = true; break;
                                }
                            }
                            if ($found) { $mCount++; $summaryParts[] = "{$f}-{$t}[O]"; }
                            else { $summaryParts[] = "{$f}-미연결[X]"; }
                        }
                        $isAll = ($tCount > 0 && $mCount === $tCount);
                        $item['status']       = $isAll ? 1 : ($mCount > 0 && $tpl['has_partial_score'] ? 2 : 0);
                        $item['currentScore'] = $isAll ? $newScore : ($mCount > 0 && $tpl['has_partial_score'] ? round($newScore * ($mCount / $tCount), 1) : 0);
                        $item['rawText']      = implode(', ', $summaryParts);
                        $totalRegradedCount++;
                    } elseif ($type !== 'subjective' && $type !== 'essay') {
                        $rawStudentAns = trim((string)($item['rawText'] ?? ''));
                        if ($rawStudentAns !== '' && $rawStudentAns !== '미기재' && $rawStudentAns !== '판독불가') {
                            $normalizedStudentAns = preg_replace('/[\s\/\.]+/u', ',', $rawStudentAns);
                            $extArr   = array_values(array_filter(array_map('trim', explode(',', $normalizedStudentAns)), 'strlen'));
                            $modelArr = array_values(array_filter(array_map('trim', explode(',', $newAnswer)), 'strlen'));

                            // [동일 보기 이체 표기 정규화] (ㄱ), ㄱ, ㉠ / (가), 가, ㉮ / (1), ①, 1 / ○, O / ✕, X
                            $canonicalExt   = array_map('canonicalizeAnswerToken', $extArr);
                            $canonicalModel = array_map('canonicalizeAnswerToken', $modelArr);
                            sort($canonicalExt);
                            sort($canonicalModel);
                            $isMatch = (!empty($canonicalModel) && $canonicalExt === $canonicalModel);
                            $item['status']       = $isMatch ? 1 : 0;
                            $item['currentScore'] = $isMatch ? $newScore : 0.0;
                            $totalRegradedCount++;
                        }
                    }
                }
            }

            if (!isset($item['status']) || $item['status'] === -1 || $item['status'] === '-1' || $item['status'] === 'pending') {
                $allGraded = false;
            }

            $newTotalScore += (float)($item['currentScore'] ?? 0);
        }
        unset($item);

        $newReviewed = $allGraded ? 1 : 0;
        $newPayload = json_encode($rawList, JSON_UNESCAPED_UNICODE);

        $updateStmt->execute([
            'raw'   => $newPayload,
            'total' => $newTotalScore,
            'rev'   => $newReviewed,
            'id'    => $recordId
        ]);

        $updatedStudentCount++;
    }

    $pdo->commit();

    jsonSuccess([
        'updated_students' => $updatedStudentCount,
        'regraded_items'   => $totalRegradedCount,
        'rechecked_items'  => $totalRecheckedCount
    ], "최신 템플릿 기준으로 재채점이 완료되었습니다. (학생 {$updatedStudentCount}명 반영, 재채점 {$totalRegradedCount}건, 부분점수 재검수 전환 {$totalRecheckedCount}건)");

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError('재채점 처리 중 오류 발생: ' . $e->getMessage(), 500);
}
