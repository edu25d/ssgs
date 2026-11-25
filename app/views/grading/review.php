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
        SELECT t.title AS template_name, c.id AS class_id, c.class_name, c.sheet_id, c.class_salt, COALESCE(a.show_answer, 0) AS show_answer 
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
        SELECT t.title AS template_name, c.id AS class_id, c.class_name, c.sheet_id, c.class_salt 
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
            background: #f4f6f9; 
            padding: 20px; 
            margin: 0; 
            padding-bottom: 75px; 
        }
        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: white; 
            padding: 16px 22px; 
            border-radius: var(--radius-md, 10px); 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05); 
            border: 1px solid var(--gray-300, #dee2e6);
        }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .header-left h2 { font-size: 1.35rem; font-weight: 800; color: #1e293b; }
        .btn-back { 
            padding: 8px 16px; 
            background: #64748b; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            font-size: 0.92rem; 
            font-weight: 700; 
            cursor: pointer; 
            transition: 0.2s; 
        }
        .btn-back:hover { background: #475569; }
        
        .controls-wrapper { display: flex; justify-content: flex-start; align-items: center; margin: 18px 0; gap: 14px; }
        .view-toggle-container { display: flex; gap: 6px; background: #e2e8f0; padding: 5px; border-radius: 10px; }
        .toggle-btn { 
            padding: 9px 20px; 
            border: none; 
            border-radius: 7px; 
            font-weight: 700; 
            font-size: 1rem; 
            cursor: pointer; 
            background: transparent; 
            color: #475569; 
            transition: 0.2s; 
        }
        .toggle-btn.active { 
            background: #0d6efd; 
            color: white; 
            box-shadow: 0 2px 6px rgba(13,110,253,0.3); 
        }
        .filter-label { 
            background: white; 
            padding: 9px 16px; 
            border-radius: 8px; 
            border: 1px solid #cbd5e1; 
            font-weight: 700; 
            cursor: pointer; 
            user-select: none; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.04); 
            font-size: 0.92rem; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }
        
        .sub-tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 8px; margin-bottom: 16px; }
        .sub-tab-item { 
            padding: 7px 15px; 
            background: white; 
            border: 1px solid #cbd5e1; 
            border-radius: 20px; 
            font-size: 0.88rem; 
            font-weight: 700; 
            cursor: pointer; 
            white-space: nowrap; 
            transition: 0.2s;
        }
        .sub-tab-item:hover { border-color: #94a3b8; }
        .sub-tab-item.active { background: #1e293b; color: white; border-color: #1e293b; }
        .sub-tab-item .type-badge { font-size: 0.75em; padding: 2px 6px; border-radius: 4px; margin-left: 4px; background: #e2e8f0; color: #475569; }
        .sub-tab-item.active .type-badge { background: #334155; color: #f8fafc; }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0 12px; }
        th, td { 
            padding: 16px; 
            background: white; 
            vertical-align: middle; 
            border-top: 2px solid #cbd5e1; 
            border-bottom: 2px solid #cbd5e1; 
            cursor: pointer; 
            transition: background 0.15s, border-color 0.15s;
        }
        td:first-child { 
            border-left: 2px solid #cbd5e1; 
            border-radius: 10px 0 0 10px; 
            width: 16%; 
            text-align: center; 
            line-height: 1.4; 
        }
        td:nth-child(2) { text-align: left; padding-left: 32px; } 
        td:last-child { 
            border-right: 2px solid #cbd5e1; 
            border-radius: 0 10px 10px 0; 
            width: 32%; 
            text-align: center; 
        }
        
        /* 3색 상태 시스템 (명확한 시각 구분) */
        tr.pending td { border-color: #f59e0b; background: #fffdf0; }
        tr.correct td { border-color: #10b981; background: #f0fdf4; }
        tr.incorrect td { border-color: #ef4444; background: #fef2f2; }
        tr.partial td { border-color: #f59e0b; background: #fffbeb; }

        /* 상태 보존형 포커스 아웃라인 (본래 상태색을 지우지 않고 확실하게 포커스 표시) */
        tr.focused td {
            box-shadow: 0 4px 16px rgba(13, 110, 253, 0.22) !important;
        }
        tr.focused td:first-child {
            border-left: 6px solid #0d6efd !important;
        }

        /* 3색 상태 뱃지 */
        .badge-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .badge-pending { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-correct { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-incorrect { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-partial { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }

        .badge-appeal {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 800;
            background: #ede9fe;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        
        .crop-container { position: relative; display: inline-block; padding: 5px; }
        .crop-img { max-width: 100%; max-height: 160px; border: 1px solid #ccc; border-radius: 6px; display: block; }
        .expand-btn { position: absolute; background: rgba(13, 202, 240, 0.25); cursor: pointer; transition: 0.2s; }
        .expand-btn:hover { background: rgba(13, 202, 240, 0.7); }
        .expand-top { top: -5px; left: 0; right: 0; height: 10px; }
        .expand-bottom { bottom: -5px; left: 0; right: 0; height: 10px; }
        .expand-left { top: 0; bottom: 0; left: -5px; width: 10px; }
        .expand-right { top: 0; bottom: 0; right: -5px; width: 10px; }

        /* 소문항 통합 그리드 및 서브 카드 디자인 */
        .sub-items-grid {
            display: flex;
            gap: 14px;
            align-items: stretch;
            flex-wrap: wrap;
            width: 100%;
        }
        .sub-item-card {
            flex: 1 1 290px;
            min-width: 250px;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            cursor: pointer;
            transition: all 0.18s ease;
        }
        .sub-item-card:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }
        .sub-item-card.active {
            border: 2.5px solid #2563eb !important;
            background: #f0f7ff !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.22) !important;
        }
        .sub-item-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }
        .sub-item-title-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.95rem;
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
            font-size: 0.92rem;
            margin-top: 6px;
            padding: 4px 8px;
            background: #f1f5f9;
            border-radius: 6px;
            color: #334155;
            line-height: 1.4;
        }
        
        .answer-info-block { display: flex; flex-direction: row; justify-content: flex-start; align-items: center; gap: 15px; margin-top: 10px; padding-left: 5px; }
        .model-answer-badge { font-size: 0.92em; color: #856404; background: #fff3cd; padding: 6px 14px; border-radius: 6px; border: 1px solid #ffeeba; }
        .extracted-answer { font-size: 0.92em; color: #495057; background: #f8f9fa; padding: 6px 14px; border-radius: 6px; border: 1px solid #e9ecef; }
        .extracted-answer b { color: #0d6efd; }
        .badge-warning { background: #dc3545; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; margin-right: 4px; }
        
        .score-btn { padding: 9px 15px; font-size: 0.92em; font-weight: 700; border: 1.5px solid #cbd5e1; border-radius: 6px; margin: 0 3px; cursor: pointer; background: white; transition: 0.2s; }
        .btn-o { color: #10b981; border-color: #a7f3d0; } 
        .btn-x { color: #ef4444; border-color: #fecaca; } 
        .btn-p { color: #b45309; border-color: #fde68a; }
        tr.correct .btn-o { background: #10b981; color: white; border-color: #10b981; }
        tr.incorrect .btn-x { background: #ef4444; color: white; border-color: #ef4444; }
        tr.partial .btn-p { background: #f59e0b; color: white; border-color: #f59e0b; }
        
        .partial-input-wrapper { display: none; margin-top: 8px; }
        tr.partial-mode .partial-input-wrapper { display: block; }
        .partial-input { width: 70px; padding: 6px; font-size: 1.1em; text-align: center; border: 2px solid #ffc107; border-radius: 6px; font-weight: bold; outline: none; }
        .empty-msg { text-align: center; padding: 50px; font-size: 1.2em; color: #6c757d; }

        /* 루브릭 카드 스타일 */
        .rubric-info-card {
            background: #f8fafc;
            border-left: 4px solid #3b82f6;
            padding: 10px 16px;
            margin: 0 0 14px 0;
            border-radius: 6px;
            font-size: 0.88rem;
            color: #334155;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .rubric-info-card b { color: #1d4ed8; }
        
        /* 서브 칩 (소문항 하위 필터) */
        .sub-chips-container {
            display: flex;
            gap: 8px;
            margin: 8px 0 14px 0;
            align-items: center;
            flex-wrap: wrap;
        }
        .sub-chip {
            padding: 4px 12px;
            border-radius: 14px;
            border: 1px solid #cbd5e1;
            background: #f1f5f9;
            font-size: 0.82rem;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
            transition: 0.15s;
        }
        .sub-chip:hover { background: #e2e8f0; }
        .sub-chip.active { background: #2563eb; color: white; border-color: #2563eb; }
        
        /* 부분점수 퀵 프리셋 버튼 */
        .preset-score-btn {
            padding: 3px 7px;
            margin: 2px;
            border-radius: 4px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #b45309;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.15s;
        }
        .preset-score-btn:hover { background: #fde68a; }
    </style>
</head>
<body>

    <div class="header">
        <div class="header-left">
            <h2 style="margin:0;">📋 <?= e($examName) ?> - 채점 현황 점검</h2>
            <button class="btn-back" onclick="goBackAndReset()">⬅ 돌아가기</button>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="/views/grading/print_slips.php?assign_id=<?= $assignId ?>" class="btn-back" style="background:#475569; text-decoration:none;" target="_blank">🖨️ 학생 배부용 정오표</a>
            <a href="/views/grading/diagnose.php?assign_id=<?= $assignId ?>" class="btn-back" style="background:#2563eb; text-decoration:none;" target="_blank">🔬 AI 채점 정밀 진단</a>
            <span id="syncStatus" style="color: #198754; font-weight: bold;">🟢 실시간 자동 동기화 중</span>
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
        <label class="filter-label" style="margin-left: auto; border-color: #8b5cf6; background: #faf5ff;">
            <input type="checkbox" id="chkShowAnswer" onchange="toggleAnswerVisibility()" <?= $showAnswer ? 'checked' : '' ?>>
            <span style="color: #6d28d9; font-weight: 800;">💡 학생에게 모범 답안 공개</span>
        </label>
        <button type="button" id="btnSyncTemplate" class="btn-regrade" onclick="syncWithTemplate()" style="background: linear-gradient(135deg, #059669, #10b981); color: white; border: none; padding: 7px 14px; border-radius: 8px; font-weight: 800; font-size: 0.88rem; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(16,185,129,0.25);">
            <span>🔄</span> <span>템플릿 정답/배점 재동기화</span>
        </button>
        <button type="button" id="btnRegradePending" class="btn-regrade" onclick="regradePending()" style="background: linear-gradient(135deg, #0d6efd, #6366f1); color: white; border: none; padding: 7px 14px; border-radius: 8px; font-weight: 800; font-size: 0.88rem; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(13,110,253,0.25);">
            <span>⚡</span> <span>미판독 문항 AI 즉시 채점</span>
        </button>
    </div>

    <div class="sub-tabs" id="subTabsContainer"></div>
    <div id="subChipsContainer" class="sub-chips-container" style="display:none;"></div>
    <div id="rubricContainer"></div>

    <table id="reviewTable">
        <tbody id="studentList"></tbody>
    </table>

    <div style="position: fixed; bottom: 0; left: 0; right: 0; padding: 12px 20px; background: #212529; color: white; border-top: 3px solid #0d6efd; font-size: 14px; z-index: 1000;">
        ⌨️ <b>단축키:</b> 
        <span style="color:#28a745">[1] 정답(만점)</span> | 
        <span style="color:#dc3545">[2] 오답(0점)</span> | 
        <span style="color:#ffc107">[3] 부분점수</span> | 
        <span>[↑/↓] 위아래 이동</span> | 
        <span>[Backspace] 채점 취소</span> | 
        <span style="color:#0dcaf0">[Shift + 방향키] 크롭 이미지 확장</span>
    </div>

<script>
    const assignId = <?= $assignId ?>;
    const studentNameLookup = <?= json_encode($studentNameLookup ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const initialDbRows = <?= json_encode($initialRows, JSON_UNESCAPED_UNICODE) ?>;
    
    const storageKey = `gradingState_${assignId}`;
    const savedState = JSON.parse(sessionStorage.getItem(storageKey) || '{}');
    
    let rawRowsData = [];         
    let filteredData = [];        
    let displayRows = [];         
    
    let viewMode = savedState.viewMode || 'question';    
    let showSubjectiveOnly = false; // 진입 시 항상 전체 문항(객관식+주관식) 기본 표시
    let selectedFilterKey = savedState.selectedFilterKey || null; 
    let selectedSubFilter = savedState.selectedSubFilter || 'all'; 
    
    let currentRowIndex = -1;
    let isPartialMode = false;
    let maxLastId = 0; 

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

        // 레거시 객체 포맷 { status: 'processed', items: [...] } 대응
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
            
            // 대문항 번호 parentId 추출 (예: "10-1" -> "10", "10" -> "10", "17-2" -> "17")
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
                if (statusElem) statusElem.innerHTML = "<span style='color:#198754;'>🟢 실시간 자동 동기화 중</span>";

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
                    const prevSubIdx = prevBox ? prevBox.activeSubIndex : 0;
                    applyFiltersAndRefresh(false);
                    if (prevBox) {
                        const restoredBoxIdx = displayRows.findIndex(b => b.db_id === prevBox.db_id && b.parentId === prevBox.parentId);
                        if (restoredBoxIdx >= 0) {
                            currentRowIndex = restoredBoxIdx;
                            displayRows[restoredBoxIdx].activeSubIndex = prevSubIdx;
                        }
                    }
                    renderTable();
                }
            })
            .catch(() => {
                const statusElem = document.getElementById('syncStatus');
                if (statusElem) statusElem.innerHTML = "<span style='color:#ffc107;'>🟡 동기화 연결 확인 중...</span>";
            });
    }

    // 미판독 문항 즉시 재채점 호출 함수
    function regradePending() {
        const btn = document.getElementById('btnRegradePending');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = "<span>⏳</span> <span>AI 채점 진행 중...</span>";
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
                btn.innerHTML = "<span>⚡</span> <span>미판독 문항 AI 즉시 채점</span>";
            }
            if (res.success) {
                alert(res.message || '미판독 문항 채점이 완료되었습니다.');
                fetchLatestResults();
            } else {
                alert('재채점 실패: ' + (res.error || '오류가 발생했습니다.'));
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "<span>⚡</span> <span>미판독 문항 AI 즉시 채점</span>";
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
            btn.innerHTML = "<span>⏳</span> <span>템플릿 재동기화 중...</span>";
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
                    btn.innerHTML = "<span>🔄</span> <span>템플릿 정답/배점 재동기화</span>";
                }
            }
        })
        .catch(err => {
            console.error('동기화 오류:', err);
            alert('네트워크 통신 오류가 발생했습니다.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = "<span>🔄</span> <span>템플릿 정답/배점 재동기화</span>";
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

    function refreshTabsAndView(resetFocus = false) {
        const container = document.getElementById('subTabsContainer');
        container.innerHTML = '';
        
        // 서브 칩 컨테이너는 이제 하나의 문제 박스 안에 소문항이 함께 통합되므로 숨김
        const subChipsContainer = document.getElementById('subChipsContainer');
        if (subChipsContainer) {
            subChipsContainer.style.display = 'none';
            subChipsContainer.innerHTML = '';
        }
        
        if (filteredData.length === 0) {
            displayRows = [];
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

            const currentQ = questions.find(q => q.parentId === selectedFilterKey);

            // 루브릭 안내 바
            const rubricContainer = document.getElementById('rubricContainer');
            if (currentQ && currentQ.criteria) {
                rubricContainer.innerHTML = `
                    <div class="rubric-info-card">
                        <b>💡 ${currentQ.parentId}번 채점 기준 (루브릭):</b> 
                        <span>${currentQ.criteria}</span>
                    </div>
                `;
            } else {
                rubricContainer.innerHTML = '';
            }

            // 문제 박스 단위 그룹핑 (학생별로 1개 박스)
            if (selectedFilterKey) {
                const studentGroupMap = new Map();
                const matchingItems = filteredData.filter(r => String(r.parentId) === String(selectedFilterKey));
                
                matchingItems.forEach(item => {
                    if (!studentGroupMap.has(item.db_id)) {
                        studentGroupMap.set(item.db_id, {
                            db_id: item.db_id,
                            student_id: item.student_id,
                            studentName: item.studentName,
                            parentId: selectedFilterKey,
                            subItems: [],
                            activeSubIndex: 0
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
            // 학생별 보기
            const rubricContainer = document.getElementById('rubricContainer');
            if (rubricContainer) {
                rubricContainer.innerHTML = '';
            }
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
                    if (!parentGroupMap.has(pId)) {
                        parentGroupMap.set(pId, {
                            db_id: item.db_id,
                            student_id: item.student_id,
                            studentName: item.studentName,
                            parentId: pId,
                            subItems: [],
                            activeSubIndex: 0
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

        renderTable();
        
        if (resetFocus && displayRows.length > 0) setFocus(0, 0);
        else if (displayRows.length === 0) setFocus(-1, 0);
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
            if (index === currentRowIndex) tr.classList.add('focused');
            if (isPartialMode && index === currentRowIndex) tr.classList.add('partial-mode');

            const hasMultipleSub = box.subItems.length > 1;
            const activeSub = box.subItems[box.activeSubIndex] || box.subItems[0] || {};

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
                appealHtml = `<div style="margin-top:6px;"><span class="badge-appeal">🙋 확인 요청: "${box.appealReason}"</span></div>`;
            }

            // 중앙 열: 각 소문항의 크롭 영역 및 답안 카드를 나란히 렌더링
            let subItemsCardsHtml = `<div class="sub-items-grid">`;
            box.subItems.forEach((sub, subIdx) => {
                const isSubActive = (index === currentRowIndex && box.activeSubIndex === subIdx);
                
                let subBadge = '';
                if (sub.status === 'correct') {
                    subBadge = `<span class="badge-status badge-correct">✅ 정답 (${sub.currentScore}점)</span>`;
                } else if (sub.status === 'incorrect') {
                    subBadge = `<span class="badge-status badge-incorrect">❌ 오답 (0점)</span>`;
                } else if (sub.status === 'partial') {
                    subBadge = `<span class="badge-status badge-partial">△ 부분 (${sub.currentScore}점)</span>`;
                } else {
                    if (sub.isAiPending) {
                        subBadge = `<span class="badge-status badge-pending" style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe;">⚡ AI 판독 중</span>`;
                    } else {
                        subBadge = `<span class="badge-status badge-pending">⏳ 대기</span>`;
                    }
                }

                let subAppealBadge = sub.appeal ? `<span class="badge-appeal" style="font-size:0.75rem;">🙋 ${sub.appeal_reason}</span>` : '';

                let extractedAnswerHtml = '';
                if (sub.type === 'matching_line') {
                    const pairsText = sub.rawText || '선잇기 미인식';
                    extractedAnswerHtml = `<div class="extracted-answer" style="margin-top:4px;">
                        <span style="display:inline-block; padding:2px 6px; border-radius:4px; background:#eff6ff; color:#1d4ed8; font-weight:700; font-size:0.82em; border:1px solid #bfdbfe;">
                            📏 선잇기: <b>${pairsText}</b>
                        </span>
                    </div>`;
                } else if (sub.type !== 'subjective' && sub.type !== 'essay') {
                    if (sub.isAiPending) {
                        extractedAnswerHtml = `<div class="extracted-answer" style="margin-top:4px;"><span style="color:#2563eb; font-weight:700;">🤖 AI 판독 중...</span></div>`;
                    } else {
                        const warningBadge = sub.isUnread ? '<span class="badge-warning">⚠️</span> ' : '';
                        extractedAnswerHtml = `<div class="extracted-answer" style="margin-top:4px;">${warningBadge}인식: <b>${sub.rawText === '' ? '미기재' : sub.rawText}</b></div>`;
                    }
                }

                const pageBadge = sub.page && sub.page > 1 ? `<span style="font-size:0.75em; background:#e2e8f0; color:#475569; padding:1px 5px; border-radius:4px; margin-left:4px;">${sub.page}쪽</span>` : '';

                subItemsCardsHtml += `
                    <div class="sub-item-card ${isSubActive ? 'active' : ''}" onclick="selectSubItem(${index}, ${subIdx}, event)">
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

                        <div class="answer-info-block" style="flex-direction:column; align-items:flex-start; gap:4px; margin-top:8px; padding-left:0;">
                            <div class="model-answer-badge" style="width:100%; box-sizing:border-box;">💡 모범 답안: <b>${sub.answer}</b></div>
                            ${extractedAnswerHtml}
                        </div>
                    </div>
                `;
            });
            subItemsCardsHtml += `</div>`;

            // 우측 열: 현재 포커스된 소문항에 대한 채점 버튼 및 입력창
            let targetSubHeader = '';
            if (hasMultipleSub) {
                targetSubHeader = `
                    <div style="font-size:0.92rem; font-weight:800; color:#2563eb; margin-bottom:8px; padding:4px 8px; background:#eff6ff; border-radius:6px; display:inline-block;">
                        🎯 <b>[${activeSub.item_id}번]</b> 채점 중 
                        <span style="font-size:0.75rem; color:#64748b; font-weight:normal;">(Tab 전환)</span>
                    </div>
                `;
            }

            let presetBtnsHtml = '';
            if (activeSub.score > 1) {
                const step = activeSub.score >= 5 ? 1.0 : (activeSub.score >= 3 ? 1.0 : 0.5);
                const presetList = [];
                for (let ps = activeSub.score - step; ps > 0; ps -= step) {
                    presetList.push(Math.round(ps * 10) / 10);
                }
                presetBtnsHtml = `<div style="margin-top:6px; display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">` + presetList.map(ps => 
                    `<button type="button" class="preset-score-btn" onclick="savePartialScore(${index}, ${activeSub.score}, ${ps})" title="${ps}점 즉시 부여">${ps}점</button>`
                ).join('') + `</div>`;
            }

            tr.onclick = (e) => {
                if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'INPUT' && !e.target.classList.contains('expand-btn') && !e.target.closest('.sub-item-card')) {
                    setFocus(index, box.activeSubIndex || 0);
                }
            };

            tr.innerHTML = `
                <td>
                    <div style="font-size:1.15em; font-weight:800; color:#1e293b;">${leftHeaderTitle}</div>
                    <div class="box-score-summary">
                        합계: <b>${box.totalCurrentScore}</b> / ${box.totalMaxScore}점
                    </div>
                    <div style="margin-top: 6px;">${boxStatusBadge}</div>
                    ${appealHtml}
                </td>
                <td>
                    ${subItemsCardsHtml}
                </td>
                <td>
                    ${targetSubHeader}
                    <div>
                        <button class="score-btn btn-o" onclick="markSubScore(${index}, ${box.activeSubIndex}, ${activeSub.score}, 'correct')"><b>[1]</b> O 정답 (${activeSub.score})</button>
                        <button class="score-btn btn-x" onclick="markSubScore(${index}, ${box.activeSubIndex}, 0, 'incorrect')"><b>[2]</b> X 오답 (0)</button>
                        <button class="score-btn btn-p" onclick="enterPartialMode(${index}, ${box.activeSubIndex})"><b>[3]</b> △ 부분점수</button>
                    </div>
                    
                    <div class="partial-input-wrapper">
                        <div>
                            <input type="number" class="partial-input" id="partialInput-${index}" 
                                   min="0" max="${activeSub.score}" step="0.5" 
                                   value="${activeSub.currentScore !== null ? activeSub.currentScore : Math.floor(activeSub.score/2)}">
                            <span style="font-weight:bold;"> / ${activeSub.score}점</span>
                        </div>
                        ${presetBtnsHtml}
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function selectSubItem(boxIndex, subIdx, e) {
        if (e) e.stopPropagation();
        currentRowIndex = boxIndex;
        if (displayRows[boxIndex]) {
            displayRows[boxIndex].activeSubIndex = subIdx;
        }
        isPartialMode = false;
        renderTable();
    }

    function setFocus(boxIndex, subIdx = 0) {
        if (boxIndex < 0 || boxIndex >= displayRows.length) {
            currentRowIndex = -1;
            renderTable();
            return;
        }
        currentRowIndex = boxIndex;
        if (displayRows[boxIndex]) {
            displayRows[boxIndex].activeSubIndex = subIdx;
        }
        isPartialMode = false;
        renderTable();
        const targetRow = document.getElementById(`row-${boxIndex}`);
        if (targetRow) targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.addEventListener('keydown', (e) => {
        if (currentRowIndex < 0 || displayRows.length === 0) return;
        const box = displayRows[currentRowIndex];
        const curSubIdx = box.activeSubIndex || 0;
        const sub = box.subItems[curSubIdx];
        if (!sub) return;

        // Tab 키: 현재 문제 박스 안의 소문항 간 순환 이동
        if (e.key === 'Tab') {
            e.preventDefault();
            if (box.subItems.length > 1) {
                box.activeSubIndex = (curSubIdx + 1) % box.subItems.length;
                renderTable();
            }
            return;
        }

        // Shift + 방향키: 현재 포커스된 소문항 크롭 이미지 동적 확장
        if (e.shiftKey) {
            if (e.key === 'ArrowUp') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'top'); }
            if (e.key === 'ArrowDown') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'bottom'); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'left'); }
            if (e.key === 'ArrowRight') { e.preventDefault(); expandSubImage(currentRowIndex, curSubIdx, 'right'); }
            return;
        }

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
            } else if (e.key === 'Escape') {
                isPartialMode = false;
                renderTable();
            }
            return;
        }

        switch (e.key) {
            case '1':
                e.preventDefault(); markSubScore(currentRowIndex, curSubIdx, sub.score, 'correct'); break;
            case '2':
                e.preventDefault(); markSubScore(currentRowIndex, curSubIdx, 0, 'incorrect'); break;
            case '3':
                e.preventDefault(); enterPartialMode(currentRowIndex, curSubIdx); break;
            case 'ArrowDown':
                e.preventDefault(); 
                if (currentRowIndex + 1 < displayRows.length) {
                    const nextBox = displayRows[currentRowIndex + 1];
                    const targetSub = curSubIdx < nextBox.subItems.length ? curSubIdx : 0;
                    setFocus(currentRowIndex + 1, targetSub);
                }
                break;
            case 'ArrowUp':
                e.preventDefault(); 
                if (currentRowIndex > 0) {
                    const prevBox = displayRows[currentRowIndex - 1];
                    const targetSub = curSubIdx < prevBox.subItems.length ? curSubIdx : 0;
                    setFocus(currentRowIndex - 1, targetSub);
                }
                break;
            case 'Backspace':
                e.preventDefault();
                if (curSubIdx > 0) {
                    // 같은 박스 안의 이전 소문항 취소
                    markSubScore(currentRowIndex, curSubIdx - 1, 0, 'pending');
                    box.activeSubIndex = curSubIdx - 1;
                    renderTable();
                } else if (currentRowIndex > 0) {
                    // 이전 학생 박스의 마지막 소문항 취소
                    const prevBox = displayRows[currentRowIndex - 1];
                    const lastSubIdx = prevBox.subItems.length - 1;
                    markSubScore(currentRowIndex - 1, lastSubIdx, 0, 'pending');
                    setFocus(currentRowIndex - 1, lastSubIdx);
                }
                break;
        }
    });

    function markSubScore(boxIndex, subIdx, score, forceStatus = null) {
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

        // 채점 후 자동 다음 포커스 이동:
        // 1) 소문항이 더 남아있으면 다음 소문항으로 포커스
        // 2) 마지막 소문항이었으면 다음 학생의 첫 번째 소문항으로 이동!
        if (subIdx + 1 < box.subItems.length) {
            box.activeSubIndex = subIdx + 1;
            renderTable();
        } else {
            box.activeSubIndex = 0;
            if (boxIndex + 1 < displayRows.length) {
                setFocus(boxIndex + 1, 0);
            } else {
                renderTable();
            }
        }

        fetch('/api/grading/save_score.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                db_id: box.db_id,
                item_id: sub.item_id,
                score: score,
                status: sub.statusCode
            })
        })
        .then(res => res.json())
        .then(res => { if (!res.success) console.error('점수 저장 실패:', res.error); });
    }

    function enterPartialMode(boxIndex, subIdx) {
        if (currentRowIndex !== boxIndex || displayRows[boxIndex].activeSubIndex !== subIdx) {
            setFocus(boxIndex, subIdx);
        }
        isPartialMode = true;
        renderTable();
        setTimeout(() => {
            const inputEl = document.getElementById(`partialInput-${boxIndex}`);
            if (inputEl) { inputEl.focus(); inputEl.select(); }
        }, 50);
    }

    function savePartialScore(boxIndex, maxScore, score) {
        if (isNaN(score) || score < 0) score = 0;
        const box = displayRows[boxIndex];
        const subIdx = box ? box.activeSubIndex : 0;
        markSubScore(boxIndex, subIdx, score, 'partial');
    }

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
                    alert('이미지를 확장할 수 없습니다: ' + (data.error || '원본 파일 없음'));
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
                const statusElem = document.getElementById('syncStatus');
                if (statusElem) {
                    statusElem.innerHTML = `<span style="color:#8b5cf6; font-weight:800;">💡 ${data.message}</span>`;
                    setTimeout(() => {
                        statusElem.innerHTML = "<span style='color:#198754; font-weight:bold;'>🟢 실시간 자동 동기화 중</span>";
                    }, 3000);
                }
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
