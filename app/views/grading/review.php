<?php
/**
 * views/grading/review.php
 * 문항별/학생별 실시간 채점 결과 점검 및 첨삭/부분점수 부여 뷰
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';

$userId   = requireTeacherAuth();
$assignId = (int)($_GET['assign_id'] ?? 0);
if (!$assignId) {
    die("잘못된 접근입니다. 과제 ID가 필요합니다.");
}

// 과제 및 템플릿 정보 조회 (show_answer 안전 조회)
$showAnswer = 0;
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

try {
    $stmt = $pdo->prepare("
        SELECT t.title AS template_name, t.roi_data, c.id AS class_id, c.class_name, c.sheet_id, c.class_salt, COALESCE(a.show_answer, 0) AS show_answer 
        FROM assignments a 
        JOIN templates t ON a.template_id = t.id 
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = :id AND (t.user_id = :t_uid OR c.user_id = :c_uid)
    ");
    $stmt->execute(['id' => $assignId, 't_uid' => $userId, 'c_uid' => $userId]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    $showAnswer = (int)($info['show_answer'] ?? 0);
} catch (Exception $e) {
    $stmt = $pdo->prepare("
        SELECT t.title AS template_name, t.roi_data, c.id AS class_id, c.class_name, c.sheet_id, c.class_salt 
        FROM assignments a 
        JOIN templates t ON a.template_id = t.id 
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = :id AND (t.user_id = :t_uid OR c.user_id = :c_uid)
    ");
    $stmt->execute(['id' => $assignId, 't_uid' => $userId, 'c_uid' => $userId]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$info) {
    die("과제를 찾을 수 없거나 열람 권한이 없습니다.");
}
$examName = $info ? ($info['template_name'] . ($info['class_name'] ? ' - ' . $info['class_name'] : '')) : '평가 점검';
$templateRois = [];
if (!empty($info['roi_data'])) {
    $templateRois = json_decode($info['roi_data'], true) ?: [];
}

$classId   = (int)($info['class_id'] ?? 0);
$classSalt = !empty($info['class_salt']) ? $info['class_salt'] : ($classId > 0 ? getOrCreateClassSalt($pdo, $classId) : '');
$sheetId   = $info['sheet_id'] ?? null;

// Phase 3: 교사 구글 시트에서 학생 실명 명단 실시간 조회 (Client-Side Join 준비, 서버 DB 미보관)
$studentNameLookup = []; // hash => realName
if (!empty($sheetId)) {
    $token = getValidAccessToken($pdo, $_SESSION['user_id']);
    if ($token) {
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($sheetId) . '/values/A5:B55';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $sheetData = json_decode($response, true);
            $values = $sheetData['values'] ?? [];
            foreach ($values as $row) {
                $sName = trim($row[0] ?? '');
                if (empty($sName)) continue;
                $sHash = makeStudentHash($classSalt, $sName);
                $studentNameLookup[$sHash] = $sName;
            }
        }
    }
}

// exam_results 쿼리 (학생 실명 대신 가명 student_hash 조회)
$stmtResult = $pdo->prepare("
    SELECT e.id, e.assignment_id, e.student_id, s.student_hash, e.total_score, e.is_reviewed, e.raw_results, e.submitted_at 
    FROM exam_results e
    LEFT JOIN students s ON e.student_id = s.id
    WHERE e.assignment_id = :assign_id 
    ORDER BY e.id ASC
");
$stmtResult->execute(['assign_id' => $assignId]);
$initialRows = $stmtResult->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title><?= e($examName) ?> - 채점 현황 점검</title>
    <link rel="stylesheet" href="/public/assets/css/dashboard.css">
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif; 
            background: #f8fafc; 
            color: #0f172a;
            padding: 20px 24px; 
            margin: 0; 
            padding-bottom: 70px; 
        }
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: white; 
            padding: 14px 20px; 
            border-radius: 12px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); 
            border: 1px solid #e2e8f0;
        }
        .header-left { display: flex; align-items: center; gap: 14px; }
        .header-left h2 { font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0; }
        .btn-back { 
            padding: 7px 14px; 
            background: #f1f5f9; 
            color: #334155; 
            border: 1px solid #cbd5e1; 
            border-radius: 8px; 
            font-size: 0.88rem; 
            font-weight: 700; 
            cursor: pointer; 
            transition: all 0.15s ease; 
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-back:hover { background: #e2e8f0; color: #0f172a; }
        
        .controls-wrapper { 
            display: flex; 
            justify-content: flex-start; 
            align-items: center; 
            margin: 16px 0 12px 0; 
            gap: 12px; 
        }
        .view-toggle-container { 
            display: flex; 
            gap: 4px; 
            background: #e2e8f0; 
            padding: 4px; 
            border-radius: 10px; 
        }
        .toggle-btn { 
            padding: 7px 16px; 
            border: none; 
            border-radius: 7px; 
            font-weight: 700; 
            font-size: 0.92rem; 
            cursor: pointer; 
            background: transparent; 
            color: #475569; 
            transition: all 0.15s ease; 
        }
        .toggle-btn.active { 
            background: white; 
            color: #0f172a; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
        }
        .filter-label { 
            background: white; 
            padding: 7px 14px; 
            border-radius: 8px; 
            border: 1px solid #cbd5e1; 
            font-weight: 700; 
            cursor: pointer; 
            user-select: none; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.03); 
            font-size: 0.88rem; 
            color: #334155;
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }
        .filter-label:hover { background: #f8fafc; }
        
        /* 탭 네비게이션: 흉물스러운 가로 스크롤바 숨김 */
        .sub-tabs { 
            display: flex; 
            gap: 8px; 
            overflow-x: auto; 
            padding-bottom: 6px; 
            margin-bottom: 12px; 
            scrollbar-width: none; 
            -ms-overflow-style: none; 
        }
        .sub-tabs::-webkit-scrollbar { display: none; }
        .sub-tab-item { 
            padding: 8px 16px; 
            background: white; 
            border: 1.5px solid #cbd5e1; 
            border-radius: 20px; 
            font-size: 0.88rem; 
            font-weight: 700; 
            color: #334155;
            cursor: pointer; 
            white-space: nowrap; 
            transition: all 0.15s ease;
        }
        .sub-tab-item:hover { border-color: #94a3b8; background: #f8fafc; }
        .sub-tab-item.active { 
            background: #1e293b; 
            color: white; 
            border-color: #1e293b; 
            box-shadow: 0 2px 6px rgba(30,41,59,0.25);
        }
        .sub-tab-item .type-badge { 
            font-size: 0.75em; 
            padding: 2px 6px; 
            border-radius: 4px; 
            margin-left: 5px; 
            background: #f1f5f9; 
            color: #475569; 
        }
        .sub-tab-item.active .type-badge { 
            background: #334155; 
            color: #f8fafc; 
        }

        /* 💡 최상단 기준 모범답안 & 배점 벤치마크 고정 바 (Sticky Header Benchmark) */
        .benchmark-bar {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-left: 5px solid #2563eb;
            border-radius: 10px;
            padding: 12px 18px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            flex-wrap: wrap;
        }
        .benchmark-info-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .benchmark-title {
            font-size: 1.02rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .benchmark-answers-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .benchmark-answer-tag {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 700;
        }
        .benchmark-badge-partial {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #047857;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 800;
        }
        .benchmark-badge-nopartial {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 800;
        }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
        th, td { 
            padding: 14px 16px; 
            background: white; 
            vertical-align: middle; 
            border-top: 1.5px solid #e2e8f0; 
            border-bottom: 1.5px solid #e2e8f0; 
            transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        td:first-child { 
            border-left: 1.5px solid #e2e8f0; 
            border-radius: 10px 0 0 10px; 
            width: 15%; 
            text-align: center; 
            line-height: 1.4; 
        }
        td:nth-child(2) { text-align: left; padding: 12px 18px; width: 60%; } 
        td:last-child { 
            border-right: 1.5px solid #e2e8f0; 
            border-radius: 0 10px 10px 0; 
            width: 25%; 
            text-align: center; 
        }
        
        /* 3색 상태 테두리 */
        tr.pending td { border-top-color: #f1f5f9; border-bottom-color: #f1f5f9; }
        tr.correct td { border-top-color: #d1fae5; border-bottom-color: #d1fae5; }
        tr.incorrect td { border-top-color: #fee2e2; border-bottom-color: #fee2e2; }
        tr.partial td { border-top-color: #fef3c7; border-bottom-color: #fef3c7; }

        /* Level 1: 학생 단위 (대문항 전체) 포커스 스타일 */
        tr.focused-box td {
            background: #f8fafc !important;
            border-top: 2px solid #2563eb !important;
            border-bottom: 2px solid #2563eb !important;
            box-shadow: 0 3px 12px rgba(37, 99, 235, 0.16) !important;
        }
        tr.focused-box td:first-child {
            border-left: 5px solid #2563eb !important;
        }
        tr.focused-box td:last-child {
            border-right: 2px solid #2563eb !important;
        }

        /* Level 2: 소문항 단위 포커스 시 부모 행 스타일 (살짝 옅어짐) */
        tr.focused-sub td {
            background: #ffffff !important;
            border-top: 1.5px solid #94a3b8 !important;
            border-bottom: 1.5px solid #94a3b8 !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05) !important;
        }
        tr.focused-sub td:first-child {
            border-left: 4px solid #94a3b8 !important;
        }
        tr.focused-sub td:last-child {
            border-right: 1.5px solid #94a3b8 !important;
        }

        /* 3색 상태 뱃지 */
        .badge-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .badge-pending { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
        .badge-correct { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-incorrect { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-partial { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }

        .badge-appeal {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 800;
            background: #f5f3ff;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        
        /* 크롭 컨테이너 & 호버 시에만 등장하는 리사이즈 핸들러 */
        .crop-container { 
            position: relative; 
            display: inline-block; 
            padding: 4px; 
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .crop-img { 
            max-width: 100%; 
            max-height: 130px; 
            border-radius: 4px; 
            display: block; 
            object-fit: contain;
        }
        .expand-btn { 
            position: absolute; 
            background: rgba(37, 99, 235, 0.6); 
            cursor: pointer; 
            transition: opacity 0.15s ease; 
            opacity: 0; 
            pointer-events: none; 
            z-index: 5;
        }
        .crop-container:hover .expand-btn { 
            opacity: 0.65; 
            pointer-events: auto; 
        }
        .expand-btn:hover { 
            opacity: 1 !important; 
            background: rgba(37, 99, 235, 0.95); 
        }
        .expand-top { top: -4px; left: 0; right: 0; height: 8px; border-radius: 4px 4px 0 0; }
        .expand-bottom { bottom: -4px; left: 0; right: 0; height: 8px; border-radius: 0 0 4px 4px; }
        .expand-left { top: 0; bottom: 0; left: -4px; width: 8px; border-radius: 4px 0 0 4px; }
        .expand-right { top: 0; bottom: 0; right: -4px; width: 8px; border-radius: 0 4px 4px 0; }

        /* 소문항 통합 그리드 및 서브 카드 디자인 */
        .sub-items-grid {
            display: flex;
            gap: 12px;
            align-items: stretch;
            flex-wrap: wrap;
            width: 100%;
        }
        .sub-item-card {
            flex: 1 1 240px;
            min-width: 210px;
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .sub-item-card:hover {
            border-color: #cbd5e1;
            background: #fafafa;
        }
        /* Level 2 소문항 활성 포커스 스타일 */
        .sub-item-card.active {
            border: 2.5px solid #2563eb !important;
            background: #eff6ff !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2) !important;
        }
        .sub-item-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            padding-bottom: 5px;
            border-bottom: 1px solid #f1f5f9;
        }
        .sub-item-title-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.92rem;
            font-weight: 800;
            color: #1e293b;
        }
        .sub-item-card.active .sub-item-title-pill {
            color: #1d4ed8;
        }
        .sub-item-focus-badge {
            display: none;
            font-size: 0.72rem;
            padding: 2px 6px;
            border-radius: 4px;
            background: #2563eb;
            color: white;
            font-weight: 800;
        }
        .sub-item-card.active .sub-item-focus-badge {
            display: inline-block;
        }
        .box-score-summary {
            font-size: 0.88rem;
            margin-top: 4px;
            padding: 3px 8px;
            background: #f1f5f9;
            border-radius: 6px;
            color: #334155;
            line-height: 1.4;
        }
        
        .extracted-answer { 
            font-size: 0.85em; 
            color: #334155; 
            background: #f8fafc; 
            padding: 4px 8px; 
            border-radius: 4px; 
            border: 1px solid #e2e8f0; 
            margin-top: 6px;
            text-align: center;
        }
        .extracted-answer b { color: #2563eb; }
        .badge-warning { background: #ef4444; color: white; padding: 1px 4px; border-radius: 3px; font-size: 0.75em; font-weight: bold; margin-right: 4px; }
        
        /* 버튼 스타일 */
        .score-btn { 
            padding: 8px 14px; 
            font-size: 0.88em; 
            font-weight: 700; 
            border: 1.5px solid #cbd5e1; 
            border-radius: 6px; 
            margin: 2px 3px; 
            cursor: pointer; 
            background: white; 
            transition: all 0.15s ease; 
        }
        .btn-o { color: #059669; border-color: #a7f3d0; background: #ecfdf5; } 
        .btn-o:hover { background: #059669; color: white; border-color: #059669; }
        .btn-x { color: #dc2626; border-color: #fecaca; background: #fef2f2; } 
        .btn-x:hover { background: #dc2626; color: white; border-color: #dc2626; }
        .btn-p { color: #d97706; border-color: #fde68a; background: #fffbeb; }
        .btn-p:hover { background: #d97706; color: white; border-color: #d97706; }
        .btn-tab-drill { 
            color: #2563eb; 
            border-color: #bfdbfe; 
            background: #eff6ff; 
            font-size: 0.82em; 
            padding: 6px 12px;
            border-radius: 6px;
            display: inline-block;
            margin-top: 6px;
            cursor: pointer;
            font-weight: 700;
        }
        .btn-tab-drill:hover { background: #2563eb; color: white; border-color: #2563eb; }
        
        .partial-input-wrapper { display: none; margin-top: 8px; }
        tr.partial-mode .partial-input-wrapper { display: block; }
        .partial-input { width: 65px; padding: 5px; font-size: 1.05em; text-align: center; border: 2px solid #f59e0b; border-radius: 6px; font-weight: bold; outline: none; }
        .empty-msg { text-align: center; padding: 50px; font-size: 1.1em; color: #64748b; }

        /* 부분점수 퀵 프리셋 버튼 */
        .preset-score-btn {
            padding: 2px 6px;
            margin: 2px;
            border-radius: 4px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #b45309;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.15s;
        }
        .preset-score-btn:hover { background: #fde68a; }

        /* 토스트 알림 (경고/안내 메시지) */
        #toastNotice {
            position: fixed;
            top: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.92rem;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
            z-index: 2000;
            display: none;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s;
        }
    </style>
</head>
<body>

    <div id="toastNotice"></div>

    <div class="header">
        <div class="header-left">
            <h2>📋 <?= e($examName) ?> - 채점 현황 점검</h2>
            <button class="btn-back" onclick="goBackAndReset()">⬅ 돌아가기</button>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="/views/grading/print_slips.php?assign_id=<?= $assignId ?>" class="btn-back" target="_blank">🖨️ 정오표 출력</a>
            <a href="/views/grading/diagnose.php?assign_id=<?= $assignId ?>" class="btn-back" target="_blank">🔬 AI 정밀 진단</a>
            <span id="syncStatus" style="color: #059669; font-weight: bold; font-size: 0.88rem;">🟢 동기화 완료</span>
        </div>
    </div>

    <div class="controls-wrapper">
        <div class="view-toggle-container">
            <button class="toggle-btn active" id="btnModeQ" onclick="switchViewMode('question')">📝 문항별 모아보기</button>
            <button class="toggle-btn" id="btnModeS" onclick="switchViewMode('student')">👤 학생별 모아보기</button>
        </div>
        <label class="filter-label">
            <input type="checkbox" id="chkSubjectiveOnly" onchange="toggleFilter()"> 주관식만 보기
        </label>
        <label class="filter-label" style="margin-left: auto;">
            <input type="checkbox" id="chkShowAnswer" onchange="toggleAnswerVisibility()" <?= $showAnswer ? 'checked' : '' ?>>
            <span>학생에게 모범 답안 공개</span>
        </label>
        <button type="button" id="btnSyncTemplate" class="btn-back" onclick="syncWithTemplate()" title="템플릿 에디터의 배점/정답을 재적용합니다">
            <span>🔄 템플릿 재동기화</span>
        </button>
        <button type="button" id="btnRegradePending" class="btn-back" onclick="regradePending()" style="background:#eff6ff; color:#2563eb; border-color:#bfdbfe;" title="미판독/판독대기 문항을 즉시 채점합니다">
            <span>⚡ 미판독 AI 채점</span>
        </button>
    </div>

    <!-- 문항 탭 네비게이터 (가로 스크롤) -->
    <div class="sub-tabs" id="subTabsContainer"></div>

    <!-- 💡 최상단 기준 모범답안 & 배점 벤치마크 고정 바 -->
    <div id="benchmarkBar" class="benchmark-bar" style="display:none;"></div>

    <table id="reviewTable">
        <tbody id="studentList"></tbody>
    </table>

    <!-- 하단 단축키 가이드 바 (동적 전환) -->
    <div id="bottomShortcutBar" style="position: fixed; bottom: 0; left: 0; right: 0; padding: 10px 24px; background: #0f172a; color: #f8fafc; border-top: 2px solid #2563eb; font-size: 13.5px; z-index: 1000; display: flex; justify-content: space-between; align-items: center;">
        <div id="shortcutKeyText">
            ⌨️ <b>단축키:</b> 
            <span style="color:#34d399">[1] 전체 정답(만점)</span> | 
            <span style="color:#f87171">[2] 전체 오답(0점)</span> | 
            <span style="color:#60a5fa">[Tab] 소문항 개별 채점</span> | 
            <span>[↑/↓] 학생 이동</span> | 
            <span>[Backspace] 채점 취소</span>
        </div>
        <div style="color: #94a3b8; font-size: 12px;">
            Shift + 방향키: 이미지 크롭 확장
        </div>
    </div>

<script>
    const assignId = <?= $assignId ?>;
    const studentNameLookup = <?= json_encode($studentNameLookup ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const initialDbRows = <?= json_encode($initialRows, JSON_UNESCAPED_UNICODE) ?>;
    const templateRois = <?= json_encode($templateRois ?? [], JSON_UNESCAPED_UNICODE) ?>;
    
    const storageKey = `gradingState_${assignId}`;
    const savedState = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
    
    let rawRowsData = [];         
    let filteredData = [];        
    let displayRows = [];         
    
    let viewMode = savedState.viewMode || 'question';    
    let showSubjectiveOnly = false; // 진입 시 항상 전체 문항 기본 표시
    let selectedFilterKey = savedState.selectedFilterKey || null; 
    let selectedSubFilter = savedState.selectedSubFilter || 'all'; 
    
    // 포커스 상태 관리:
    // focusLevel: 'box' (학생/대문항 전체 L1 포커스) | 'sub' (소문항 크롭 L2 포커스)
    let currentRowIndex = -1;
    let focusLevel = 'box';
    let isPartialMode = false;
    let maxLastId = 0; 

    // 템플릿 메타데이터 파싱 (부분점수 허용 여부 등)
    const templateQuestionMeta = new Map();
    if (Array.isArray(templateRois)) {
        templateRois.forEach((r, idx) => {
            const rawId = String(r.id || (idx + 1));
            const subId = (r.sub_id !== undefined && r.sub_id !== null && r.sub_id !== '') ? String(r.sub_id) : '';
            const dispNum = String(r.display_num || (subId !== '' ? `${rawId}-${subId}` : rawId));
            const parentId = dispNum.includes('-') ? dispNum.split('-')[0].trim() : (rawId.includes('-') ? rawId.split('-')[0].trim() : rawId);
            
            if (!templateQuestionMeta.has(parentId)) {
                templateQuestionMeta.set(parentId, {
                    parentId: parentId,
                    has_partial_score: Boolean(r.has_partial_score),
                    type: r.type || 'objective',
                    criteria: r.criteria || '',
                    answer: r.answer || '',
                    score: parseFloat(r.score || 0),
                    subItems: []
                });
            }
            const pMeta = templateQuestionMeta.get(parentId);
            if (r.has_partial_score) pMeta.has_partial_score = true;
            if (!pMeta.criteria && r.criteria) pMeta.criteria = r.criteria;
            pMeta.subItems.push({
                item_id: dispNum,
                sub_id: subId,
                answer: r.answer || '',
                score: parseFloat(r.score || 0),
                has_partial_score: Boolean(r.has_partial_score)
            });
        });
    }

    function showToast(msg) {
        const toast = document.getElementById('toastNotice');
        if (!toast) return;
        toast.innerHTML = msg;
        toast.style.display = 'flex';
        toast.style.opacity = '1';
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => { toast.style.display = 'none'; }, 200);
        }, 2200);
    }

    function goBackAndReset() {
        const dbIds = new Set();
        if (Array.isArray(rawRowsData)) {
            rawRowsData.forEach(r => { if (r.db_id) dbIds.add(r.db_id); });
        }
        if (Array.isArray(initialDbRows)) {
            initialDbRows.forEach(r => { if (r.id) dbIds.add(r.id); });
        }

        dbIds.forEach(dbId => {
            const formData = new FormData();
            formData.append('db_id', dbId);
            navigator.sendBeacon('/api/grading/delete_temp_image.php', formData);
        });

        sessionStorage.removeItem(storageKey);
        location.href = '/views/dashboard/main.php';
    }

    document.getElementById('chkSubjectiveOnly').checked = showSubjectiveOnly;
    document.getElementById('btnModeQ').classList.toggle('active', viewMode === 'question');
    document.getElementById('btnModeS').classList.toggle('active', viewMode === 'student');

    function saveState() {
        sessionStorage.setItem(storageKey, JSON.stringify({
            viewMode: viewMode,
            showSubjectiveOnly: showSubjectiveOnly,
            selectedFilterKey: selectedFilterKey
        }));
    }

    function unpackDbRow(dbRow) {
        const resultsMap = new Map(); 
        let rawList = [];
        try {
            rawList = typeof dbRow.raw_results === 'string' ? JSON.parse(dbRow.raw_results) : (dbRow.raw_results || []);
        } catch(e) { rawList = []; }

        if (!Array.isArray(rawList) && rawList && Array.isArray(rawList.items)) {
            rawList = rawList.items;
        } else if (!Array.isArray(rawList)) {
            rawList = [];
        }

        const studentHash = dbRow.student_hash || '';
        const studentLabel = studentNameLookup[studentHash] || dbRow.student_name || ('학생 ' + (dbRow.student_id || dbRow.id)); 

        rawList.forEach(item => {
            const rawIdStr = String(item.item_id || item.id || '0');
            const subIdVal = (item.sub_id !== undefined && item.sub_id !== null && item.sub_id !== '') ? String(item.sub_id) : '';
            const displayNum = (item.display_num || (subIdVal !== '' ? `${rawIdStr}-${subIdVal}` : rawIdStr)).toString();
            
            let parentId = '';
            let resolvedSubId = subIdVal;
            if (displayNum.includes('-')) {
                const parts = displayNum.split('-');
                parentId = parts[0].trim();
                if (!resolvedSubId && parts[1]) resolvedSubId = parts[1].trim();
            } else if (rawIdStr.includes('-')) {
                const parts = rawIdStr.split('-');
                parentId = parts[0].trim();
                if (!resolvedSubId && parts[1]) resolvedSubId = parts[1].trim();
            } else {
                parentId = rawIdStr.trim();
            }
            const itemId = displayNum;
            const maxScore = parseFloat(item.score || 0);
            
            let statusCode = -1;
            if (item.status !== undefined && item.status !== null) {
                if (typeof item.status === 'number') statusCode = item.status;
                else if (item.status === 'correct') statusCode = 1;
                else if (item.status === 'incorrect') statusCode = 0;
                else if (item.status === 'partial') statusCode = 2;
                else if (item.status === 'pending') statusCode = -1;
            }

            const statusClassMap = { 1: 'correct', 0: 'incorrect', 2: 'partial', '-1': 'pending' };
            const statusStr = statusClassMap[statusCode] || 'pending';

            let currentScore = 0;
            if (item.currentScore !== undefined && item.currentScore !== null) {
                currentScore = parseFloat(item.currentScore);
            } else if (statusCode === 1) {
                currentScore = maxScore;
            } else if (statusCode === 2) {
                currentScore = parseFloat(item.score_awarded || 0);
            }

            const recognizedText = (item.rawText || item.recognized_text || '').trim();
            const isAiPending = (item.type !== 'subjective') && (recognizedText === '판독대기' || recognizedText === 'AI 채점 중...' || (statusCode === -1 && !recognizedText));
            const isUnread = (item.type !== 'subjective') && !isAiPending && (!recognizedText || recognizedText === '미기재' || recognizedText === '판독불가');

            resultsMap.set(itemId, {
                db_id: dbRow.id,
                student_id: dbRow.student_id,
                studentName: studentLabel,
                item_id: itemId,
                raw_id: parentId,
                parentId: parentId,
                sub_id: resolvedSubId || null,
                page: parseInt(item.page || 1, 10),
                type: (resolvedSubId ? 'sub_item' : (item.type || 'objective')),
                score: maxScore,
                currentScore: currentScore,
                statusCode: statusCode,
                status: statusStr,
                answer: item.answer || item.model_answer || '미지정',
                criteria: item.criteria || '',
                rawText: recognizedText,
                detected_pairs: item.detected_pairs || [],
                has_partial_score: Boolean(item.has_partial_score),
                isAiPending: isAiPending,
                isUnread: isUnread,
                crop_image_url: item.crop_url || item.crop_image_url || '',
                appeal: !item.appeal_resolved && !!item.appeal,
                appeal_reason: item.appeal_reason || '확인 요청'
            });
        });
        
        return Array.from(resultsMap.values()); 
    }

    if (Array.isArray(initialDbRows)) {
        initialDbRows.forEach(row => {
            const rowId = parseInt(row.id || 0);
            if (rowId > maxLastId) maxLastId = rowId;

            const items = unpackDbRow(row);
            items.forEach(item => {
                const idx = rawRowsData.findIndex(r => r.db_id === item.db_id && r.item_id === item.item_id);
                if (idx >= 0) rawRowsData[idx] = item;
                else rawRowsData.push(item);
            });
        });
        applyFiltersAndRefresh(true);
    }

    function fetchLatestResults() {
        fetch(`/api/grading/get_results.php?assign_id=${assignId}`)
            .then(res => res.json())
            .then(dataList => {
                const statusElem = document.getElementById('syncStatus');
                if (statusElem) statusElem.innerHTML = "<span style='color:#059669; font-weight:bold;'>🟢 동기화 완료</span>";

                if (!Array.isArray(dataList) || dataList.length === 0) return;

                let hasNewData = false;
                dataList.forEach(data => {
                    const items = unpackDbRow(data);
                    items.forEach(item => {
                        const idx = rawRowsData.findIndex(r => r.db_id === item.db_id && r.item_id === item.item_id);
                        if (idx >= 0) {
                            const prev = rawRowsData[idx];
                            if (prev.statusCode !== item.statusCode || prev.currentScore !== item.currentScore || prev.rawText !== item.rawText) {
                                rawRowsData[idx] = item;
                                hasNewData = true;
                            }
                        } else {
                            rawRowsData.push(item);
                            hasNewData = true;
                        }
                    });
                });

                if (hasNewData) {
                    if (isPartialMode) return;
                    const prevBox = displayRows[currentRowIndex];
                    const prevSubIdx = prevBox ? prevBox.activeSubIndex : -1;
                    const prevLevel = focusLevel;
                    applyFiltersAndRefresh(false);
                    if (prevBox) {
                        const restoredBoxIdx = displayRows.findIndex(b => b.db_id === prevBox.db_id && b.parentId === prevBox.parentId);
                        if (restoredBoxIdx >= 0) {
                            currentRowIndex = restoredBoxIdx;
                            focusLevel = prevLevel;
                            displayRows[restoredBoxIdx].activeSubIndex = prevSubIdx;
                        }
                    }
                    renderTable();
                }
            })
            .catch(() => {
                const statusElem = document.getElementById('syncStatus');
                if (statusElem) statusElem.innerHTML = "<span style='color:#f59e0b; font-weight:bold;'>🟡 동기화 확인 중</span>";
            });
    }

    function regradePending() {
        const btn = document.getElementById('btnRegradePending');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = "<span>⏳ AI 채점 중...</span>";
        }

        fetch('/api/grading/regrade_pending.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assignment_id: assignId })
        })
        .then(res => res.json())
        .then(res => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "<span>⚡ 미판독 AI 채점</span>";
            }
            if (res.success) {
                showToast('✅ ' + (res.message || '미판독 문항 채점이 완료되었습니다.'));
                fetchLatestResults();
            } else {
                alert('재채점 실패: ' + (res.error || '오류가 발생했습니다.'));
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "<span>⚡ 미판독 AI 채점</span>";
            }
            alert('통신 오류: ' + err.message);
        });
    }

    function syncWithTemplate() {
        if (!confirm("템플릿 에디터에서 수정한 최신 정답/배점을 전체 학생 결과에 반영하시겠습니까?\n\n- 선생님이 수기 검수한 점수는 안전하게 유지됩니다.\n- 배점이 변경된 부분점수 문항은 '재검수 필요' 상태로 전환됩니다.")) {
            return;
        }
        const btn = document.getElementById('btnSyncTemplate');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = "<span>⏳ 동기화 중...</span>";
        }

        fetch('/api/grading/regrade_by_template.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assignment_id: assignId })
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                alert(res.message || '템플릿 동기화가 완료되었습니다.');
                location.reload();
            } else {
                alert('동기화 실패: ' + (res.error || '알 수 없는 오류'));
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = "<span>🔄 템플릿 재동기화</span>";
                }
            }
        })
        .catch(err => {
            console.error('동기화 오류:', err);
            alert('네트워크 통신 오류가 발생했습니다.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "<span>🔄 템플릿 재동기화</span>";
            }
        });
    }

    setInterval(fetchLatestResults, 3000);

    function toggleFilter() {
        showSubjectiveOnly = document.getElementById('chkSubjectiveOnly').checked;
        saveState();
        applyFiltersAndRefresh(true);
    }

    function applyFiltersAndRefresh(resetFocus = false) {
        filteredData = rawRowsData.filter(r => {
            if (showSubjectiveOnly) {
                const t = r.type || 'objective';
                if (t !== 'subjective' && t !== 'short_answer' && t !== 'essay') return false;
            }
            return true;
        });
        refreshTabsAndView(resetFocus);
    }

    function switchViewMode(mode) {
        viewMode = mode;
        selectedFilterKey = null; 
        saveState();
        document.getElementById('btnModeQ').classList.toggle('active', mode === 'question');
        document.getElementById('btnModeS').classList.toggle('active', mode === 'student');
        refreshTabsAndView(true);
    }

    function updateBoxSummary(box) {
        let totalMax = 0;
        let totalCur = 0;
        let allCorrect = true;
        let anyIncorrect = false;
        let anyPending = false;
        let anyPartial = false;
        let hasAppeal = false;
        let appealReason = '';

        box.subItems.forEach(sub => {
            totalMax += sub.score;
            totalCur += sub.currentScore;
            if (sub.statusCode === 1) {
                // 정답
            } else if (sub.statusCode === 0) {
                allCorrect = false;
                anyIncorrect = true;
            } else if (sub.statusCode === 2) {
                allCorrect = false;
                anyPartial = true;
            } else {
                allCorrect = false;
                anyPending = true;
            }
            if (sub.appeal) {
                hasAppeal = true;
                if (!appealReason) appealReason = sub.appeal_reason;
            }
        });

        box.totalMaxScore = Math.round(totalMax * 10) / 10;
        box.totalCurrentScore = Math.round(totalCur * 10) / 10;
        box.hasAppeal = hasAppeal;
        box.appealReason = appealReason || '확인 요청';

        if (anyPending) {
            box.status = 'pending';
        } else if (allCorrect) {
            box.status = 'correct';
        } else if (totalCur > 0 && totalCur < totalMax) {
            box.status = 'partial';
        } else if (totalCur === 0) {
            box.status = 'incorrect';
        } else {
            box.status = 'partial';
        }
    }

    // 💡 최상단 모범답안 & 배점 벤치마크 바 렌더링
    function updateBenchmarkBar() {
        const bar = document.getElementById('benchmarkBar');
        if (!bar) return;

        if (viewMode !== 'question' || !selectedFilterKey || displayRows.length === 0) {
            bar.style.display = 'none';
            bar.innerHTML = '';
            return;
        }

        const sampleBox = displayRows[0];
        const pMeta = templateQuestionMeta.get(String(selectedFilterKey)) || {};
        const hasMultipleSub = sampleBox.subItems.length > 1;
        const hasPartial = sampleBox.hasPartialScore;

        let answersHtml = '';
        sampleBox.subItems.forEach(sub => {
            answersHtml += `<span class="benchmark-answer-tag"><b>[${sub.item_id}번]</b> 정답: ${sub.answer} (${sub.score}점)</span>`;
        });

        let partialBadgeHtml = '';
        if (hasMultipleSub) {
            partialBadgeHtml = hasPartial 
                ? `<span class="benchmark-badge-partial">✓ 부분점수 인정 (소문항별 채점 가능)</span>`
                : `<span class="benchmark-badge-nopartial">✕ 부분점수 불가 (All or Nothing: 만점 또는 0점)</span>`;
        }

        let criteriaHtml = '';
        const criteriaText = pMeta.criteria || (sampleBox.subItems[0] && sampleBox.subItems[0].criteria);
        if (criteriaText) {
            criteriaHtml = `<div style="width:100%; font-size:0.84rem; color:#475569; margin-top:4px; padding-top:4px; border-top:1px dashed #e2e8f0;">
                <b>💡 루브릭(채점 기준):</b> ${criteriaText}
            </div>`;
        }

        bar.innerHTML = `
            <div class="benchmark-info-left">
                <div class="benchmark-title">
                    <span>📌 ${selectedFilterKey}번 기준 모범답안</span>
                    <span style="font-size:0.85rem; color:#64748b; font-weight:normal;">(총 배점 ${sampleBox.totalMaxScore}점)</span>
                </div>
                <div class="benchmark-answers-group">
                    ${answersHtml}
                </div>
                ${partialBadgeHtml}
            </div>
            <div style="font-size:0.82rem; color:#64748b; font-weight:700;">
                응시 인원: ${displayRows.length}명
            </div>
            ${criteriaHtml}
        `;
        bar.style.display = 'flex';
    }

    function refreshTabsAndView(resetFocus = false) {
        const container = document.getElementById('subTabsContainer');
        container.innerHTML = '';
        
        if (filteredData.length === 0) {
            displayRows = [];
            updateBenchmarkBar();
            renderTable();
            return;
        }

        if (viewMode === 'question') {
            const parentMap = new Map();
            filteredData.forEach(r => {
                const pId = String(r.parentId || '1');
                if (!parentMap.has(pId)) {
                    parentMap.set(pId, {
                        parentId: pId,
                        type: r.type,
                        criteria: r.criteria || '',
                        subItems: new Set()
                    });
                }
                const pObj = parentMap.get(pId);
                if (r.sub_id !== null && r.sub_id !== undefined && r.sub_id !== '') {
                    pObj.subItems.add(String(r.item_id));
                }
                if (!pObj.criteria && r.criteria) {
                    pObj.criteria = r.criteria;
                }
            });

            const questions = Array.from(parentMap.values());
            questions.sort((a,b) => String(a.parentId).localeCompare(String(b.parentId), undefined, { numeric: true }));

            if (selectedFilterKey && !questions.some(q => q.parentId === selectedFilterKey)) {
                selectedFilterKey = null;
            }
            if (!selectedFilterKey && questions.length > 0) {
                selectedFilterKey = questions[0].parentId;
            }

            questions.forEach(q => {
                const btn = document.createElement('button');
                btn.className = `sub-tab-item ${q.parentId === selectedFilterKey ? 'active' : ''}`;
                
                let badgeLabel = '객관식';
                if (q.subItems.size > 1) {
                    badgeLabel = `소문항 ${q.subItems.size}`;
                } else if (q.type === 'subjective') badgeLabel = '주관식';
                else if (q.type === 'short_answer') badgeLabel = '단답형';
                else if (q.type === 'matching_line') badgeLabel = '선잇기';
                else if (q.type === 'sub_item') badgeLabel = '소문항';

                btn.innerHTML = `${q.parentId}번 <span class="type-badge">${badgeLabel}</span>`;
                btn.onclick = () => { 
                    selectedFilterKey = q.parentId; 
                    saveState(); 
                    refreshTabsAndView(true); 
                };
                container.appendChild(btn);
            });

            // 문제 박스 단위 그룹핑 (학생별로 1개 행)
            if (selectedFilterKey) {
                const studentGroupMap = new Map();
                const matchingItems = filteredData.filter(r => String(r.parentId) === String(selectedFilterKey));
                const pMeta = templateQuestionMeta.get(String(selectedFilterKey));
                const questionHasPartial = pMeta ? pMeta.has_partial_score : false;
                
                matchingItems.forEach(item => {
                    if (!studentGroupMap.has(item.db_id)) {
                        studentGroupMap.set(item.db_id, {
                            db_id: item.db_id,
                            student_id: item.student_id,
                            studentName: item.studentName,
                            parentId: selectedFilterKey,
                            hasPartialScore: questionHasPartial || item.has_partial_score,
                            subItems: [],
                            activeSubIndex: -1 // 기본값: 학생 전체 포커스
                        });
                    }
                    studentGroupMap.get(item.db_id).subItems.push(item);
                });

                displayRows = Array.from(studentGroupMap.values());
                displayRows.forEach(box => {
                    box.subItems.sort((a,b) => String(a.item_id).localeCompare(String(b.item_id), undefined, {numeric: true}));
                    updateBoxSummary(box);
                });
                displayRows.sort((a,b) => a.db_id - b.db_id);
            } else {
                displayRows = [];
            }

        } else {
            // 학생별 보기 모드
            const students = [];
            filteredData.forEach(r => {
                if (!students.some(s => s.db_id === r.db_id)) {
                    students.push({ db_id: r.db_id, studentName: r.studentName });
                }
            });

            if (selectedFilterKey && !students.some(s => s.db_id === selectedFilterKey)) {
                selectedFilterKey = null;
            }

            students.forEach(s => {
                const btn = document.createElement('button');
                btn.className = `sub-tab-item ${s.db_id === selectedFilterKey ? 'active' : ''}`;
                btn.innerText = s.studentName;
                btn.onclick = () => { selectedFilterKey = s.db_id; saveState(); refreshTabsAndView(true); };
                container.appendChild(btn);
            });

            if (selectedFilterKey) {
                const parentGroupMap = new Map();
                const studentItems = filteredData.filter(r => r.db_id === selectedFilterKey);

                studentItems.forEach(item => {
                    const pId = String(item.parentId);
                    const pMeta = templateQuestionMeta.get(pId);
                    const questionHasPartial = pMeta ? pMeta.has_partial_score : false;

                    if (!parentGroupMap.has(pId)) {
                        parentGroupMap.set(pId, {
                            db_id: item.db_id,
                            student_id: item.student_id,
                            studentName: item.studentName,
                            parentId: pId,
                            hasPartialScore: questionHasPartial || item.has_partial_score,
                            subItems: [],
                            activeSubIndex: -1
                        });
                    }
                    parentGroupMap.get(pId).subItems.push(item);
                });

                displayRows = Array.from(parentGroupMap.values());
                displayRows.forEach(box => {
                    box.subItems.sort((a,b) => String(a.item_id).localeCompare(String(b.item_id), undefined, {numeric: true}));
                    updateBoxSummary(box);
                });
                displayRows.sort((a,b) => String(a.parentId).localeCompare(String(b.parentId), undefined, { numeric: true }));
            } else {
                displayRows = [];
            }
        }

        updateBenchmarkBar();
        renderTable();
        
        // 이동 시 항상 학생 단위(Level 1: 'box')로 최우선 포커스!
        if (resetFocus && displayRows.length > 0) setFocus(0, 'box');
        else if (displayRows.length === 0) setFocus(-1, 'box');
    }

    function updateShortcutGuide() {
        const textEl = document.getElementById('shortcutKeyText');
        if (!textEl) return;

        if (focusLevel === 'sub') {
            textEl.innerHTML = `
                ⌨️ <b>[소문항 개별 채점]</b> 
                <span style="color:#34d399">[1] O 정답</span> | 
                <span style="color:#f87171">[2] X 오답</span> | 
                <span style="color:#fbbf24">[3] △ 부분점수</span> | 
                <span style="color:#60a5fa">[Tab] 다음 소문항</span> | 
                <span>[Esc / Shift+Tab] 학생 전체로 복귀</span>
            `;
        } else {
            textEl.innerHTML = `
                ⌨️ <b>[문항 전체 채점]</b> 
                <span style="color:#34d399">[1] 전체 정답(만점)</span> | 
                <span style="color:#f87171">[2] 전체 오답(0점)</span> | 
                <span style="color:#60a5fa">[Tab] 소문항 개별 채점</span> | 
                <span>[↑/↓] 학생 이동</span> | 
                <span>[Backspace] 취소</span>
            `;
        }
    }

    function renderTable() {
        const tbody = document.getElementById('studentList');
        tbody.innerHTML = '';

        if (displayRows.length === 0) {
            const msg = selectedFilterKey ? '조건에 맞는 데이터가 없습니다.' : '👆 상단의 탭(문항 또는 학생)을 먼저 선택해주세요.';
            tbody.innerHTML = `<tr id="emptyRow"><td colspan="3" class="empty-msg">${msg}</td></tr>`;
            return;
        }

        displayRows.forEach((box, index) => {
            const tr = document.createElement('tr');
            tr.id = `row-${index}`;
            tr.className = box.status; 
            
            const isCurrent = (index === currentRowIndex);
            if (isCurrent) {
                if (focusLevel === 'box') {
                    tr.classList.add('focused-box');
                } else {
                    tr.classList.add('focused-sub');
                }
            }
            if (isPartialMode && isCurrent) tr.classList.add('partial-mode');

            const hasMultipleSub = box.subItems.length > 1;
            const curSubIdx = (box.activeSubIndex >= 0) ? box.activeSubIndex : 0;
            const activeSub = box.subItems[curSubIdx] || box.subItems[0] || {};

            // 좌측 열: 학생 정보 및 대문항 총점/상태
            let leftHeaderTitle = '';
            if (viewMode === 'question') {
                const subBadge = hasMultipleSub ? `<span style="font-size:0.8em; color:#7c3aed; font-weight:700;">(소문항 ${box.subItems.length}개)</span>` : '';
                leftHeaderTitle = `<div>${box.studentName} ${subBadge}</div>`;
            } else {
                const subBadge = hasMultipleSub ? `<span style="font-size:0.8em; color:#7c3aed; font-weight:700;">(소문항 ${box.subItems.length}개)</span>` : '';
                leftHeaderTitle = `<div>${box.parentId}번 ${subBadge}</div>`;
            }

            let boxStatusBadge = '';
            if (box.status === 'correct') {
                boxStatusBadge = `<span class="badge-status badge-correct">✅ 정답 (${box.totalCurrentScore}점)</span>`;
            } else if (box.status === 'incorrect') {
                boxStatusBadge = `<span class="badge-status badge-incorrect">❌ 오답 (0점)</span>`;
            } else if (box.status === 'partial') {
                boxStatusBadge = `<span class="badge-status badge-partial">△ 부분점수 (${box.totalCurrentScore}점)</span>`;
            } else {
                boxStatusBadge = `<span class="badge-status badge-pending">⏳ 검수 대기</span>`;
            }

            let appealHtml = '';
            if (box.hasAppeal) {
                appealHtml = `<div style="margin-top:4px;"><span class="badge-appeal">🙋 "${box.appealReason}"</span></div>`;
            }

            // 중앙 열: 각 소문항의 크롭 영역 및 학생 답안 (모범답안 중복 텍스트 완전 제거!)
            let subItemsCardsHtml = `<div class="sub-items-grid">`;
            box.subItems.forEach((sub, subIdx) => {
                const isSubActive = (isCurrent && focusLevel === 'sub' && box.activeSubIndex === subIdx);
                
                let subBadge = '';
                if (sub.status === 'correct') {
                    subBadge = `<span class="badge-status badge-correct">✅ 정답 (${sub.currentScore}점)</span>`;
                } else if (sub.status === 'incorrect') {
                    subBadge = `<span class="badge-status badge-incorrect">❌ 오답 (0점)</span>`;
                } else if (sub.status === 'partial') {
                    subBadge = `<span class="badge-status badge-partial">△ 부분 (${sub.currentScore}점)</span>`;
                } else {
                    subBadge = sub.isAiPending 
                        ? `<span class="badge-status badge-pending" style="color:#2563eb;">⚡ AI 판독 중</span>`
                        : `<span class="badge-status badge-pending">⏳ 대기</span>`;
                }

                let subAppealBadge = sub.appeal ? `<span class="badge-appeal" style="font-size:0.75rem;">🙋 ${sub.appeal_reason}</span>` : '';

                let extractedAnswerHtml = '';
                if (sub.type === 'matching_line') {
                    const pairsText = sub.rawText || '선잇기 미인식';
                    extractedAnswerHtml = `<div class="extracted-answer">📏 선잇기: <b>${pairsText}</b></div>`;
                } else if (sub.type !== 'subjective' && sub.type !== 'essay') {
                    if (sub.isAiPending) {
                        extractedAnswerHtml = `<div class="extracted-answer" style="color:#2563eb;">🤖 AI 판독 중...</div>`;
                    } else {
                        const warningBadge = sub.isUnread ? '<span class="badge-warning">⚠️</span> ' : '';
                        extractedAnswerHtml = `<div class="extracted-answer">${warningBadge}인식: <b>${sub.rawText === '' ? '미기재' : sub.rawText}</b></div>`;
                    }
                }

                const pageBadge = sub.page && sub.page > 1 ? `<span style="font-size:0.75em; background:#e2e8f0; color:#475569; padding:1px 5px; border-radius:4px; margin-left:4px;">${sub.page}쪽</span>` : '';

                subItemsCardsHtml += `
                    <div class="sub-item-card ${isSubActive ? 'active' : ''}" onclick="handleSubCardClick(${index}, ${subIdx}, event)">
                        <div class="sub-item-card-header">
                            <div class="sub-item-title-pill">
                                <span>${sub.item_id}번</span>
                                ${pageBadge}
                                <span style="font-size:0.8rem; color:#64748b; font-weight:normal;">(${sub.score}점)</span>
                                <span class="sub-item-focus-badge">🎯 선택됨</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:4px;">
                                ${subBadge}
                                ${subAppealBadge}
                            </div>
                        </div>

                        <div class="crop-container" style="align-self:center;">
                            <div class="expand-btn expand-top" onclick="expandSubImage(${index}, ${subIdx}, 'top', event)" title="Shift+↑ 확장"></div>
                            <div class="expand-btn expand-bottom" onclick="expandSubImage(${index}, ${subIdx}, 'bottom', event)" title="Shift+↓ 확장"></div>
                            <div class="expand-btn expand-left" onclick="expandSubImage(${index}, ${subIdx}, 'left', event)" title="Shift+← 확장"></div>
                            <div class="expand-btn expand-right" onclick="expandSubImage(${index}, ${subIdx}, 'right', event)" title="Shift+→ 확장"></div>
                            <img src="${sub.crop_image_url}" class="crop-img" id="cropImg-${index}-${subIdx}" alt="${sub.item_id} 답안 크롭">
                        </div>

                        ${extractedAnswerHtml}
                    </div>
                `;
            });
            subItemsCardsHtml += `</div>`;

            // 우측 열: 포커스 상태(학생 전체 vs 소문항 개별)에 따른 맞춤형 채점 컨트롤
            let actionControlHtml = '';
            
            if (!isCurrent || focusLevel === 'box') {
                // [Level 1: 학생 전체 포커스] 1타 일괄 채점
                let tabDrillBtnHtml = '';
                if (hasMultipleSub) {
                    if (box.hasPartialScore) {
                        tabDrillBtnHtml = `
                            <div>
                                <button type="button" class="btn-tab-drill" onclick="enterSubFocus(${index}, 0, event)">
                                    🔍 <b>[Tab]</b> 소문항 개별 채점 (부분점수용)
                                </button>
                            </div>
                        `;
                    } else {
                        tabDrillBtnHtml = `
                            <div style="font-size:0.75rem; color:#94a3b8; margin-top:5px;">
                                ※ 부분점수 미인정 문항 (All or Nothing)
                            </div>
                        `;
                    }
                }

                actionControlHtml = `
                    <div style="font-size:0.82rem; font-weight:800; color:#0f172a; margin-bottom:6px;">
                        🎯 <b>문항 전체 채점</b> <span style="font-size:0.75rem; color:#64748b; font-weight:normal;">(1타 전체 반영)</span>
                    </div>
                    <div>
                        <button class="score-btn btn-o" onclick="markAllSubScores(${index}, 'correct', event)"><b>[1]</b> 전체 만점 (${box.totalMaxScore}점)</button>
                        <button class="score-btn btn-x" onclick="markAllSubScores(${index}, 'incorrect', event)"><b>[2]</b> 전체 오답 (0점)</button>
                    </div>
                    ${tabDrillBtnHtml}
                `;
            } else {
                // [Level 2: 소문항 개별 포커스 (Tab 진입 상태)]
                let presetBtnsHtml = '';
                if (activeSub.score > 1) {
                    const step = activeSub.score >= 5 ? 1.0 : (activeSub.score >= 3 ? 1.0 : 0.5);
                    const presetList = [];
                    for (let ps = activeSub.score - step; ps > 0; ps -= step) {
                        presetList.push(Math.round(ps * 10) / 10);
                    }
                    presetBtnsHtml = `<div style="margin-top:6px; display:flex; gap:3px; justify-content:center; flex-wrap:wrap;">` + presetList.map(ps => 
                        `<button type="button" class="preset-score-btn" onclick="savePartialScore(${index}, ${activeSub.score}, ${ps}, event)" title="${ps}점 즉시 부여">${ps}점</button>`
                    ).join('') + `</div>`;
                }

                actionControlHtml = `
                    <div style="font-size:0.88rem; font-weight:800; color:#2563eb; margin-bottom:6px;">
                        🎯 <b>[${activeSub.item_id}번]</b> 채점 중 
                        <span style="font-size:0.72rem; color:#64748b; font-weight:normal;">(Tab: 다음 / Esc: 복귀)</span>
                    </div>
                    <div>
                        <button class="score-btn btn-o" onclick="markSubScore(${index}, ${curSubIdx}, ${activeSub.score}, 'correct', event)"><b>[1]</b> O 정답 (${activeSub.score})</button>
                        <button class="score-btn btn-x" onclick="markSubScore(${index}, ${curSubIdx}, 0, 'incorrect', event)"><b>[2]</b> X 오답 (0)</button>
                        <button class="score-btn btn-p" onclick="enterPartialMode(${index}, ${curSubIdx}, event)"><b>[3]</b> △ 부분점수</button>
                    </div>
                    <div style="margin-top:4px;">
                        <button type="button" class="score-btn" style="background:#f1f5f9; color:#475569; font-size:0.78rem; padding:4px 8px;" onclick="exitSubFocus(${index}, event)">
                            ↩ [Esc] 학생 전체 포커스로 복귀
                        </button>
                    </div>

                    <div class="partial-input-wrapper">
                        <div>
                            <input type="number" class="partial-input" id="partialInput-${index}" 
                                   min="0" max="${activeSub.score}" step="0.5" 
                                   value="${activeSub.currentScore !== null ? activeSub.currentScore : Math.floor(activeSub.score/2)}">
                            <span style="font-weight:bold; font-size:0.9rem;"> / ${activeSub.score}점</span>
                        </div>
                        ${presetBtnsHtml}
                    </div>
                `;
            }

            tr.onclick = (e) => {
                if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT' && !e.target.classList.contains('expand-btn') && !e.target.closest('.sub-item-card')) {
                    setFocus(index, 'box');
                }
            };

            tr.innerHTML = `
                <td>
                    <div style="font-size:1.08em; font-weight:800; color:#0f172a;">${leftHeaderTitle}</div>
                    <div class="box-score-summary">
                        합계: <b>${box.totalCurrentScore}</b> / ${box.totalMaxScore}점
                    </div>
                    <div style="margin-top: 4px;">${boxStatusBadge}</div>
                    ${appealHtml}
                </td>
                <td>
                    ${subItemsCardsHtml}
                </td>
                <td>
                    ${actionControlHtml}
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    // 포커스 제어 함수 (이동 시 항상 학생 전체 L1 'box'로 시작)
    function setFocus(boxIndex, level = 'box', subIdx = 0) {
        if (boxIndex < 0 || boxIndex >= displayRows.length) {
            currentRowIndex = -1;
            focusLevel = 'box';
            renderTable();
            updateShortcutGuide();
            return;
        }
        currentRowIndex = boxIndex;
        focusLevel = level;
        if (displayRows[boxIndex]) {
            displayRows[boxIndex].activeSubIndex = (level === 'sub') ? subIdx : -1;
        }
        isPartialMode = false;
        renderTable();
        updateShortcutGuide();
        
        const targetRow = document.getElementById(`row-${boxIndex}`);
        if (targetRow) targetRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // 소문항 모드로 진입 (Tab 키 또는 버튼 클릭)
    function enterSubFocus(boxIndex, subIdx = 0, e = null) {
        if (e) e.stopPropagation();
        const box = displayRows[boxIndex];
        if (!box) return;

        // 부분점수 인정 여부 검증 (미인정 시 진입 차단)
        if (!box.hasPartialScore) {
            showToast('⚠️ 이 문항은 부분 점수가 인정되지 않아 소문항 개별 채점이 불가능합니다. (All or Nothing)');
            return;
        }

        setFocus(boxIndex, 'sub', subIdx);
    }

    // 학생 전체 모드로 복귀 (Esc 키 또는 복귀 버튼)
    function exitSubFocus(boxIndex, e = null) {
        if (e) e.stopPropagation();
        setFocus(boxIndex, 'box');
    }

    // 소문항 카드 직접 클릭 시 처리
    function handleSubCardClick(boxIndex, subIdx, e) {
        if (e) e.stopPropagation();
        const box = displayRows[boxIndex];
        if (!box) return;

        if (!box.hasPartialScore) {
            showToast('⚠️ 이 문항은 부분 점수가 인정되지 않습니다. (문항 전체 정/오답만 가능)');
            setFocus(boxIndex, 'box');
            return;
        }

        setFocus(boxIndex, 'sub', subIdx);
    }

    // [핵심] 학생 전체(대문항 전체) 일괄 채점 함수 (1타 처리 후 즉시 다음 학생 이동)
    function markAllSubScores(boxIndex, targetStatus, e = null) {
        if (e) e.stopPropagation();
        const box = displayRows[boxIndex];
        if (!box) return;

        const isCorrect = (targetStatus === 'correct');
        const isIncorrect = (targetStatus === 'incorrect');
        const isPending = (targetStatus === 'pending');

        const savePromises = [];

        box.subItems.forEach(sub => {
            if (isCorrect) {
                sub.currentScore = sub.score;
                sub.status = 'correct';
                sub.statusCode = 1;
            } else if (isIncorrect) {
                sub.currentScore = 0;
                sub.status = 'incorrect';
                sub.statusCode = 0;
            } else {
                sub.currentScore = 0;
                sub.status = 'pending';
                sub.statusCode = -1;
            }
            sub.appeal = false;

            // rawRowsData 원본 동기화
            const rawIdx = rawRowsData.findIndex(r => r.db_id === box.db_id && r.item_id === sub.item_id);
            if (rawIdx >= 0) {
                rawRowsData[rawIdx].currentScore = sub.currentScore;
                rawRowsData[rawIdx].status = sub.status;
                rawRowsData[rawIdx].statusCode = sub.statusCode;
                rawRowsData[rawIdx].appeal = false;
            }

            // DB 저장 API 호출
            savePromises.push(
                fetch('/api/grading/save_score.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        db_id: box.db_id,
                        item_id: sub.item_id,
                        score: sub.currentScore,
                        status: sub.statusCode
                    })
                }).then(res => res.json())
            );
        });

        updateBoxSummary(box);

        // 일괄 채점 완료 후 즉시 다음 학생의 L1(학생 전체 포커스)으로 자동 이동!
        if (boxIndex + 1 < displayRows.length) {
            setFocus(boxIndex + 1, 'box');
        } else {
            renderTable();
        }

        Promise.all(savePromises).then(results => {
            const hasError = results.some(r => !r || !r.success);
            if (hasError) console.error('일부 점수 저장 실패');
        });
    }

    // 소문항 개별 채점 함수
    function markSubScore(boxIndex, subIdx, score, forceStatus = null, e = null) {
        if (e) e.stopPropagation();
        const box = displayRows[boxIndex];
        if (!box || !box.subItems[subIdx]) return;
        const sub = box.subItems[subIdx];

        sub.currentScore = score;
        sub.appeal = false;
        
        if (forceStatus) {
            sub.status = forceStatus;
            sub.statusCode = (forceStatus === 'correct') ? 1 : ((forceStatus === 'incorrect') ? 0 : ((forceStatus === 'pending') ? -1 : 2));
        } else {
            if (score === sub.score) { sub.status = 'correct'; sub.statusCode = 1; }
            else if (score === 0) { sub.status = 'incorrect'; sub.statusCode = 0; }
            else { sub.status = 'partial'; sub.statusCode = 2; }
        }

        updateBoxSummary(box);

        // rawRowsData 원본 동기화
        const rawIdx = rawRowsData.findIndex(r => r.db_id === box.db_id && r.item_id === sub.item_id);
        if (rawIdx >= 0) {
            rawRowsData[rawIdx].currentScore = score;
            rawRowsData[rawIdx].status = sub.status;
            rawRowsData[rawIdx].statusCode = sub.statusCode;
            rawRowsData[rawIdx].appeal = false;
        }

        // DB 저장 비동기 호출
        fetch('/api/grading/save_score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                db_id: box.db_id,
                item_id: sub.item_id,
                score: score,
                status: sub.statusCode
            })
        }).then(res => res.json()).then(res => {
            if (!res.success) console.error('점수 저장 실패:', res.error);
        });

        // 소문항 개별 채점 후 포커스 이동 규칙:
        // 1) 아직 소문항이 남아있으면 다음 소문항으로 포커스
        // 2) 마지막 소문항 채점이 완료되면 해당 학생 완료 -> 다음 학생의 L1(학생 전체)으로 자동 이동!
        if (subIdx + 1 < box.subItems.length) {
            box.activeSubIndex = subIdx + 1;
            renderTable();
        } else {
            if (boxIndex + 1 < displayRows.length) {
                setFocus(boxIndex + 1, 'box');
            } else {
                focusLevel = 'box';
                renderTable();
                updateShortcutGuide();
            }
        }
    }

    function enterPartialMode(boxIndex, subIdx, e = null) {
        if (e) e.stopPropagation();
        if (currentRowIndex !== boxIndex || focusLevel !== 'sub' || displayRows[boxIndex].activeSubIndex !== subIdx) {
            setFocus(boxIndex, 'sub', subIdx);
        }
        isPartialMode = true;
        renderTable();
        setTimeout(() => {
            const inputEl = document.getElementById(`partialInput-${boxIndex}`);
            if (inputEl) { inputEl.focus(); inputEl.select(); }
        }, 50);
    }

    function savePartialScore(boxIndex, maxScore, score, e = null) {
        if (e) e.stopPropagation();
        if (isNaN(score) || score < 0) score = 0;
        const box = displayRows[boxIndex];
        const subIdx = box && box.activeSubIndex >= 0 ? box.activeSubIndex : 0;
        markSubScore(boxIndex, subIdx, score, 'partial');
    }

    // 전역 키보드 인터랙션 제어
    document.addEventListener('keydown', (e) => {
        if (currentRowIndex < 0 || displayRows.length === 0) return;
        const box = displayRows[currentRowIndex];
        if (!box) return;

        const curSubIdx = box.activeSubIndex >= 0 ? box.activeSubIndex : 0;
        const sub = box.subItems[curSubIdx] || box.subItems[0];

        // 1. Tab 키: 포커스 계층 전환 및 소문항 이동
        if (e.key === 'Tab') {
            e.preventDefault();
            
            if (focusLevel === 'box') {
                // 학생 전체 포커스 상태에서 Tab 누름:
                // 소문항이 2개 이상이고 부분점수가 인정되는 문항일 때만 소문항 개별 모드로 진입!
                if (box.subItems.length > 1) {
                    if (box.hasPartialScore) {
                        enterSubFocus(currentRowIndex, 0);
                    } else {
                        showToast('⚠️ 이 문항은 부분 점수가 인정되지 않아 소문항 개별 채점을 할 수 없습니다. (All or Nothing)');
                    }
                }
            } else {
                // 이미 소문항 포커스 상태에서 Tab 누름:
                if (e.shiftKey) {
                    if (box.activeSubIndex > 0) {
                        box.activeSubIndex--;
                        renderTable();
                    } else {
                        // 첫 번째 소문항에서 Shift+Tab 시 학생 전체 포커스로 복귀
                        exitSubFocus(currentRowIndex);
                    }
                } else {
                    box.activeSubIndex = (box.activeSubIndex + 1) % box.subItems.length;
                    renderTable();
                }
            }
            return;
        }

        // 2. Escape 키: 소문항 모드 탈출 후 학생 전체 포커스로 복귀
        if (e.key === 'Escape') {
            if (isPartialMode) {
                isPartialMode = false;
                renderTable();
                return;
            }
            if (focusLevel === 'sub') {
                exitSubFocus(currentRowIndex);
                return;
            }
        }

        // 3. Shift + 방향키: 현재 포커스된 소문항 크롭 이미지 동적 확장
        if (e.shiftKey) {
            if (e.key === 'ArrowUp') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'top'); }
            if (e.key === 'ArrowDown') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'bottom'); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'left'); }
            if (e.key === 'ArrowRight') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'right'); }
            return;
        }

        // 4. 부분점수 수치 입력 모드
        if (isPartialMode) {
            const inputEl = document.getElementById(`partialInput-${currentRowIndex}`);
            if (e.key === 'Enter') {
                e.preventDefault();
                savePartialScore(currentRowIndex, sub.score, parseFloat(inputEl.value));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                inputEl.value = Math.min(sub.score, (parseFloat(inputEl.value) || 0) + 0.5);
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                inputEl.value = Math.max(0, (parseFloat(inputEl.value) || 0) - 0.5);
            }
            return;
        }

        // 5. 메인 단축키 [1], [2], [3], [ArrowUp], [ArrowDown], [Backspace]
        switch (e.key) {
            case '1':
                e.preventDefault();
                if (focusLevel === 'box') {
                    // 학생 전체 포커스: 1타 일괄 만점 처리 후 다음 학생 이동
                    markAllSubScores(currentRowIndex, 'correct');
                } else {
                    // 소문항 포커스: 해당 소문항 만점 처리
                    markSubScore(currentRowIndex, box.activeSubIndex, sub.score, 'correct');
                }
                break;

            case '2':
                e.preventDefault();
                if (focusLevel === 'box') {
                    // 학생 전체 포커스: 1타 일괄 0점 처리 후 다음 학생 이동
                    markAllSubScores(currentRowIndex, 'incorrect');
                } else {
                    // 소문항 포커스: 해당 소문항 0점 처리
                    markSubScore(currentRowIndex, box.activeSubIndex, 0, 'incorrect');
                }
                break;

            case '3':
                e.preventDefault();
                if (focusLevel === 'box') {
                    if (box.subItems.length > 1) {
                        if (box.hasPartialScore) {
                            enterSubFocus(currentRowIndex, 0);
                            enterPartialMode(currentRowIndex, 0);
                        } else {
                            showToast('⚠️ 이 문항은 부분 점수가 인정되지 않습니다.');
                        }
                    } else {
                        enterPartialMode(currentRowIndex, 0);
                    }
                } else {
                    enterPartialMode(currentRowIndex, box.activeSubIndex);
                }
                break;

            case 'ArrowDown':
                e.preventDefault(); 
                // 학생 간 이동 시 항상 학생 단위(Level 1: 'box')로 최우선 포커스!
                if (currentRowIndex + 1 < displayRows.length) {
                    setFocus(currentRowIndex + 1, 'box');
                }
                break;

            case 'ArrowUp':
                e.preventDefault(); 
                // 학생 간 이동 시 항상 학생 단위(Level 1: 'box')로 최우선 포커스!
                if (currentRowIndex > 0) {
                    setFocus(currentRowIndex - 1, 'box');
                }
                break;

            case 'Backspace':
                e.preventDefault();
                if (focusLevel === 'sub') {
                    if (box.activeSubIndex > 0) {
                        markSubScore(currentRowIndex, box.activeSubIndex - 1, 0, 'pending');
                        box.activeSubIndex--;
                        renderTable();
                    } else {
                        exitSubFocus(currentRowIndex);
                    }
                } else {
                    if (currentRowIndex > 0) {
                        markAllSubScores(currentRowIndex - 1, 'pending');
                        setFocus(currentRowIndex - 1, 'box');
                    }
                }
                break;
        }
    });

    function expandSubImage(boxIndex, subIdx, direction, e) {
        if (e) e.stopPropagation();
        const box = displayRows[boxIndex];
        if (!box || !box.subItems[subIdx]) return;
        const sub = box.subItems[subIdx];

        const imgEl = document.getElementById(`cropImg-${boxIndex}-${subIdx}`);
        if (imgEl) imgEl.style.opacity = '0.5';

        fetch(`/api/grading/expand_crop.php?db_id=${box.db_id}&item_id=${encodeURIComponent(sub.item_id)}&direction=${direction}`)
            .then(res => res.json())
            .then(data => {
                if (imgEl) imgEl.style.opacity = '1';
                if (data.success && data.new_image_base64) {
                    if (imgEl) imgEl.src = data.new_image_base64;
                    sub.crop_image_url = data.new_image_base64;
                    const rawIdx = rawRowsData.findIndex(r => r.db_id === box.db_id && r.item_id === sub.item_id);
                    if (rawIdx >= 0) rawRowsData[rawIdx].crop_image_url = data.new_image_base64;
                } else {
                    showToast('⚠️ 이미지를 확장할 수 없습니다: ' + (data.error || '원본 파일 없음'));
                }
            })
            .catch(() => { if (imgEl) imgEl.style.opacity = '1'; });
    }

    function toggleAnswerVisibility() {
        const isChecked = document.getElementById('chkShowAnswer').checked;
        fetch('/api/grading/toggle_answer_visibility.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assignment_id: assignId,
                show_answer: isChecked ? 1 : 0
            })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert('설정 변경 실패: ' + (data.error || '오류 발생'));
                document.getElementById('chkShowAnswer').checked = !isChecked;
            } else {
                showToast('💡 ' + data.message);
            }
        })
        .catch(() => {
            alert('네트워크 통신 오류가 발생했습니다.');
            document.getElementById('chkShowAnswer').checked = !isChecked;
        });
    }
</script>
</body>
</html>
