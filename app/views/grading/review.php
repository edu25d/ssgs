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
$templateName = $info['template_name'] ?? '평가 점검';
$className    = $info['class_name'] ?? '';
$examName     = $templateName . ($className ? ' - ' . $className : '');
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
    <title><?= e($templateName) ?><?= $className ? ' (' . e($className) . ')' : '' ?> - 채점 검수</title>
    <link rel="stylesheet" href="/public/assets/css/dashboard.css">
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif; 
            background: #f8fafc; 
            color: #0f172a;
            padding: 12px 20px; 
            margin: 0; 
            padding-bottom: 70px; 
        }

        /* 1줄 통합 슬림 헤더 */
        .navbar-compact {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            height: 52px;
            padding: 0 16px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
            gap: 12px;
        }
        .nav-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex-shrink: 1;
        }
        .btn-icon-back {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #334155;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }
        .btn-icon-back:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .badge-class {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 800;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .exam-title-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
            overflow: hidden;
        }
        .exam-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .screen-context {
            font-size: 0.86rem;
            color: #64748b;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .nav-center {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .segmented-control {
            display: flex;
            gap: 2px;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .seg-btn {
            padding: 5px 12px;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.84rem;
            cursor: pointer;
            background: transparent;
            color: #475569;
            transition: all 0.15s ease;
        }
        .seg-btn.active {
            background: white;
            color: #0f172a;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .chip-filter {
            padding: 5px 11px;
            border-radius: 7px;
            border: 1.5px solid #cbd5e1;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            background: white;
            color: #334155;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s ease;
        }
        .chip-filter:hover { background: #f8fafc; }
        .chip-filter.active {
            background: #eff6ff;
            color: #2563eb;
            border-color: #93c5fd;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .badge-pending-pulse {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #b45309;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 0.8rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: 0.15s;
            animation: pendingPulse 2s infinite ease-in-out;
        }
        @keyframes pendingPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            50% { box-shadow: 0 0 0 5px rgba(245, 158, 11, 0); }
        }
        .badge-pending-pulse:hover {
            background: #fef3c7;
        }

        /* 정답 공개 스위치 */
        .toggle-switch-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            user-select: none;
            padding: 4px 8px;
            border-radius: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .toggle-switch-label input { display: none; }
        .toggle-track {
            width: 28px;
            height: 16px;
            background: #cbd5e1;
            border-radius: 10px;
            position: relative;
            transition: background 0.2s;
        }
        .toggle-track::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 12px;
            height: 12px;
            background: white;
            border-radius: 50%;
            transition: transform 0.2s;
        }
        .toggle-switch-label input:checked + .toggle-track {
            background: #2563eb;
        }
        .toggle-switch-label input:checked + .toggle-track::after {
            transform: translateX(12px);
        }

        .btn-nav-action {
            padding: 5px 11px;
            background: #ffffff;
            color: #334155;
            border: 1.5px solid #cbd5e1;
            border-radius: 7px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .btn-nav-action:hover { background: #f1f5f9; color: #0f172a; }

        .sync-status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #059669;
        }
        .sync-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
            box-shadow: 0 0 0 2px rgba(16,185,129,0.25);
        }

        /* 더보기 드롭다운 */
        .dropdown-wrap {
            position: relative;
        }
        .btn-icon-more {
            width: 32px;
            height: 32px;
            border-radius: 7px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
        }
        .btn-icon-more:hover { background: #f1f5f9; }
        .dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            background: white;
            min-width: 210px;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border: 1px solid #e2e8f0;
            z-index: 1000;
            padding: 6px 0;
        }
        .dropdown-menu.show { display: block; }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 8px 14px;
            background: transparent;
            border: none;
            font-size: 0.84rem;
            font-weight: 600;
            color: #334155;
            text-align: left;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.12s;
            box-sizing: border-box;
        }
        .dropdown-item:hover { background: #f8fafc; color: #0f172a; }
        .dropdown-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 4px 0;
        }

        /* 💡 상단 고정 헤더 패널 (스크롤 시에도 문항 탭과 모범답안 벤치마크 바가 항상 화면 상단에 고정) */
        .sticky-header-panel {
            position: sticky;
            top: 0;
            z-index: 500;
            background: #f8fafc;
            padding-top: 4px;
            padding-bottom: 4px;
            border-bottom: 1.5px solid #e2e8f0;
            margin-bottom: 8px;
        }

        /* 탭 네비게이션 래퍼 */
        .sub-tabs-wrapper {
            display: flex;
            align-items: center;
            gap: 6px;
            position: relative;
            margin-bottom: 6px;
        }
        .tab-scroll-btn {
            width: 28px;
            height: 36px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            border-radius: 6px;
            color: #475569;
            font-weight: bold;
            font-size: 1.1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: 0.15s;
            user-select: none;
        }
        .tab-scroll-btn:hover { background: #e2e8f0; color: #0f172a; }

        .sub-tabs { 
            display: flex; 
            gap: 6px; 
            overflow-x: auto; 
            padding: 3px 2px; 
            scroll-behavior: smooth;
            flex-grow: 1;
            scrollbar-width: thin; 
            scrollbar-color: #cbd5e1 transparent; 
        }
        .sub-tabs::-webkit-scrollbar { height: 4px; }
        .sub-tabs::-webkit-scrollbar-track { background: transparent; }
        .sub-tabs::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .sub-tabs::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* 컴팩트 문항 알약 (폭 42px) */
        .sub-tab-pill { 
            flex: 0 0 42px;
            width: 42px;
            height: 36px;
            padding: 2px 0;
            background: white; 
            border: 1.5px solid #cbd5e1; 
            border-radius: 7px; 
            cursor: pointer; 
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            user-select: none;
            box-sizing: border-box;
        }
        .sub-tab-pill:hover { border-color: #94a3b8; background: #f8fafc; }
        .sub-tab-pill.active { 
            background: #1e293b; 
            border-color: #1e293b; 
            box-shadow: 0 2px 6px rgba(30,41,59,0.25);
        }
        .sub-tab-pill.student-pill {
            flex: 0 0 auto;
            width: auto;
            padding: 6px 12px;
            font-size: 0.86rem;
        }
        .sub-tab-pill .pill-num {
            font-size: 0.82rem;
            font-weight: 800;
            color: #334155;
            line-height: 1.1;
        }
        .sub-tab-pill.active .pill-num {
            color: #ffffff;
        }

        /* 유형 표시 미니 닷 */
        .type-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            margin-top: 2px;
        }
        .type-dot.objective { background: #94a3b8; }
        .type-dot.short_answer { background: #0284c7; }
        .type-dot.subjective { background: #7c3aed; }
        .type-dot.matching_line { background: #059669; }
        .type-dot-multi {
            display: flex;
            gap: 2px;
            margin-top: 2px;
        }
        .type-dot-multi span {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #d97706;
        }
        .sub-tab-pill.active .type-dot.objective { background: #cbd5e1; }
        .sub-tab-pill.active .type-dot.short_answer { background: #38bdf8; }
        .sub-tab-pill.active .type-dot.subjective { background: #c084fc; }
        .sub-tab-pill.active .type-dot.matching_line { background: #34d399; }
        .sub-tab-pill.active .type-dot-multi span { background: #fbbf24; }

        /* 💡 최상단 기준 모범답안 & 배점 벤치마크 바 */
        .benchmark-bar {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #2563eb;
            border-radius: 7px;
            padding: 6px 14px;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.03);
            flex-wrap: wrap;
        }
        .benchmark-info-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .benchmark-title {
            font-size: 0.92rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .benchmark-answers-group {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .benchmark-answer-tag {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .benchmark-badge-partial {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #047857;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.78rem;
            font-weight: 800;
        }
        .benchmark-badge-nopartial {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.78rem;
            font-weight: 800;
        }
        
        table { width: 100%; border-collapse: separate; border-spacing: 0 6px; }
        th, td { 
            padding: 6px 12px; 
            vertical-align: middle; 
            border-top: 1.5px solid #e2e8f0; 
            border-bottom: 1.5px solid #e2e8f0; 
            transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        td:first-child { 
            border-left: 1.5px solid #e2e8f0; 
            border-radius: 8px 0 0 8px; 
            width: 14%; 
            text-align: center; 
            line-height: 1.3; 
        }
        td:nth-child(2) { text-align: left; padding: 6px 12px; width: 62%; } 
        td:last-child { 
            border-right: 1.5px solid #e2e8f0; 
            border-radius: 0 8px 8px 0; 
            width: 24%; 
            text-align: center; 
        }
        
        /* 4색 상태 전체 배경 틴트 복원 */
        tr.correct td { 
            background-color: #f0fdf4 !important; 
            border-top-color: #bbf7d0 !important; 
            border-bottom-color: #bbf7d0 !important; 
        }
        tr.correct td:first-child { border-left-color: #86efac !important; }
        tr.correct td:last-child { border-right-color: #86efac !important; }

        tr.incorrect td { 
            background-color: #fef2f2 !important; 
            border-top-color: #fecaca !important; 
            border-bottom-color: #fecaca !important; 
        }
        tr.incorrect td:first-child { border-left-color: #fca5a5 !important; }
        tr.incorrect td:last-child { border-right-color: #fca5a5 !important; }

        tr.partial td { 
            background-color: #fffbeb !important; 
            border-top-color: #fde68a !important; 
            border-bottom-color: #fde68a !important; 
        }
        tr.partial td:first-child { border-left-color: #fcd34d !important; }
        tr.partial td:last-child { border-right-color: #fcd34d !important; }

        tr.pending td { 
            background-color: #ffffff !important; 
            border-top-color: #e2e8f0 !important; 
            border-bottom-color: #e2e8f0 !important; 
        }
        tr.pending td:first-child { border-left-color: #cbd5e1 !important; }
        tr.pending td:last-child { border-right-color: #cbd5e1 !important; }

        /* Level 1: 학생 단위 (대문항 전체) 포커스 스타일 */
        tr.focused-box td {
            border-top: 2px solid #2563eb !important;
            border-bottom: 2px solid #2563eb !important;
            box-shadow: 0 0 0 1px #2563eb inset, 0 2px 8px rgba(37, 99, 235, 0.12) !important;
        }
        tr.focused-box td:first-child {
            border-left: 4px solid #2563eb !important;
        }
        tr.focused-box td:last-child {
            border-right: 2px solid #2563eb !important;
        }

        /* Level 2: 소문항 단위 포커스 시 부모 행 스타일 */
        tr.focused-sub td {
            border-top: 1.5px solid #94a3b8 !important;
            border-bottom: 1.5px solid #94a3b8 !important;
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
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .badge-pending { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
        .badge-correct { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-incorrect { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-partial { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }

        .badge-appeal {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 5px;
            font-size: 0.75rem;
            font-weight: 800;
            background: #f5f3ff;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        
        /* 플랫 소문항 패널 구조 */
        .sub-items-row {
            display: flex;
            gap: 10px;
            align-items: stretch;
            width: 100%;
        }
        .sub-crop-pane {
            flex: 1 1 0;
            min-width: 180px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
            min-height: 102px;
        }
        .sub-crop-pane:hover {
            border-color: #94a3b8;
        }
        /* Level 2 소문항 활성 포커스 스타일 */
        .sub-crop-pane.active {
            border-color: #2563eb !important;
            background: #eff6ff !important;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.3) !important;
        }

        /* 📸 크롭 이미지: 높이 98px로 최적화 (현재의 60% 수준) */
        .crop-img-large { 
            max-width: 100%; 
            height: 98px; 
            border-radius: 4px; 
            display: block; 
            object-fit: contain;
            background: transparent;
        }

        /* 소문항 번호 미니멀 라벨 */
        .sub-pane-tag {
            position: absolute;
            top: 4px;
            left: 6px;
            font-size: 0.78rem;
            font-weight: 800;
            color: #334155;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: rgba(241, 245, 249, 0.9);
            padding: 1px 6px;
            border-radius: 4px;
            backdrop-filter: blur(2px);
            z-index: 4;
            border: 1px solid rgba(203, 213, 225, 0.6);
        }
        .sub-crop-pane.active .sub-pane-tag {
            color: #1d4ed8;
            background: #dbeafe;
            border-color: #bfdbfe;
        }

        /* 소문항 상태 뱃지 */
        .sub-status-overlay {
            position: absolute;
            top: 4px;
            right: 6px;
            z-index: 4;
            font-size: 0.74rem;
            padding: 1px 5px;
        }

        /* AI 인식값 플로팅 오버레이 */
        .ai-recognition-overlay {
            position: absolute;
            bottom: 4px;
            right: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            background: rgba(15, 23, 42, 0.82);
            color: #ffffff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.15);
            backdrop-filter: blur(3px);
            z-index: 4;
            pointer-events: none;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .ai-recognition-overlay b {
            color: #60a5fa;
            font-size: 0.95em;
        }
        .ai-recognition-overlay.is-correct {
            background: rgba(4, 120, 87, 0.9);
        }
        .ai-recognition-overlay.is-incorrect {
            background: rgba(185, 28, 28, 0.9);
        }

        /* 크롭 확장 버튼 */
        .expand-btn { 
            position: absolute; 
            background: rgba(37, 99, 235, 0.6); 
            cursor: pointer; 
            transition: opacity 0.15s ease; 
            opacity: 0; 
            pointer-events: none; 
            z-index: 5;
        }
        .sub-crop-pane:hover .expand-btn { 
            opacity: 0.65; 
            pointer-events: auto; 
        }
        .expand-btn:hover { 
            opacity: 1 !important; 
            background: rgba(37, 99, 235, 0.95); 
        }
        .expand-top { top: 0; left: 0; right: 0; height: 7px; }
        .expand-bottom { bottom: 0; left: 0; right: 0; height: 7px; }
        .expand-left { top: 0; bottom: 0; left: 0; width: 7px; }
        .expand-right { top: 0; bottom: 0; right: 0; width: 7px; }
        
        /* 버튼 스타일 */
        .score-btn-group {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .score-btn { 
            padding: 6px 12px; 
            font-size: 0.84em; 
            font-weight: 700; 
            border: 1.5px solid #cbd5e1; 
            border-radius: 6px; 
            margin: 1px 2px; 
            cursor: pointer; 
            background: white; 
            transition: all 0.12s ease; 
        }
        .btn-o { color: #047857; border-color: #a7f3d0; background: #ecfdf5; } 
        .btn-o:hover { background: #059669; color: white; border-color: #059669; }
        .btn-x { color: #b91c1c; border-color: #fecaca; background: #fef2f2; } 
        .btn-x:hover { background: #dc2626; color: white; border-color: #dc2626; }
        .btn-p { color: #b45309; border-color: #fde68a; background: #fffbeb; }
        .btn-p:hover { background: #d97706; color: white; border-color: #d97706; }
        .btn-tab-drill-compact { 
            color: #2563eb; 
            border: 1px solid #bfdbfe; 
            background: #eff6ff; 
            font-size: 0.78em; 
            padding: 3px 8px;
            border-radius: 5px;
            display: inline-block;
            cursor: pointer;
            font-weight: 700;
        }
        .btn-tab-drill-compact:hover { background: #2563eb; color: white; }
        .btn-sub-exit {
            background: #f1f5f9;
            color: #475569;
            font-size: 0.75rem;
            padding: 3px 7px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-sub-exit:hover { background: #e2e8f0; color: #0f172a; }
        
        .partial-input-wrapper { display: none; margin-top: 6px; }
        tr.partial-mode .partial-input-wrapper { display: block; }
        .partial-input { width: 55px; padding: 3px; font-size: 0.95em; text-align: center; border: 2px solid #f59e0b; border-radius: 5px; font-weight: bold; outline: none; }
        .empty-msg { text-align: center; padding: 40px; font-size: 1.05em; color: #64748b; }

        /* 부분점수 퀵 프리셋 버튼 */
        .preset-score-btn {
            padding: 2px 6px;
            margin: 1px;
            border-radius: 4px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #b45309;
            font-size: 0.74rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.12s;
        }
        .preset-score-btn:hover { background: #fde68a; }

        /* 토스트 알림 */
        #toastNotice {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: white;
            padding: 8px 18px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
            z-index: 2000;
            display: none;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s;
        }

        /* 듀얼 하단 고정 바 */
        #bottomShortcutBar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 46px;
            padding: 0 16px;
            background: #0f172a;
            color: #f8fafc;
            border-top: 2px solid #2563eb;
            font-size: 13px;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 -3px 12px rgba(0,0,0,0.15);
        }
        .bot-nav-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-bot-nav {
            padding: 4px 9px;
            background: #1e293b;
            color: #f8fafc;
            border: 1px solid #334155;
            border-radius: 5px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.12s;
            user-select: none;
        }
        .btn-bot-nav:hover { background: #2563eb; border-color: #2563eb; }
        .bot-scrubber {
            display: flex;
            gap: 4px;
            align-items: center;
            max-width: 440px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .bot-scrubber::-webkit-scrollbar { display: none; }
        .bot-scrub-chip {
            padding: 2px 7px;
            border-radius: 4px;
            background: #1e293b;
            color: #94a3b8;
            border: 1px solid #334155;
            font-size: 0.76rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.12s;
            white-space: nowrap;
        }
        .bot-scrub-chip:hover { background: #334155; color: #fff; }
        .bot-scrub-chip.active { background: #2563eb; color: #fff; border-color: #3b82f6; }
    </style>
</head>
<body>

    <div id="toastNotice"></div>

    <!-- PC 1줄 통합 슬림 헤더 바 -->
    <header class="navbar-compact">
        <div class="nav-left">
            <button type="button" class="btn-icon-back" onclick="goBackAndReset()" title="과제 목록으로 돌아가기">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </button>
            <?php if (!empty($className)): ?>
                <span class="badge-class"><?= e($className) ?></span>
            <?php endif; ?>
            <div class="exam-title-wrap">
                <h1 class="exam-title"><?= e($templateName) ?></h1>
                <span class="screen-context">· 채점 검수</span>
            </div>
        </div>

        <div class="nav-center">
            <div class="segmented-control">
                <button type="button" class="seg-btn active" id="btnModeQ" onclick="switchViewMode('question')">📝 문항별</button>
                <button type="button" class="seg-btn" id="btnModeS" onclick="switchViewMode('student')">👤 학생별</button>
            </div>
            <button type="button" class="chip-filter" id="btnSubjectiveOnly" onclick="toggleFilter()">
                <span>✍️ 주관식만</span>
            </button>
        </div>

        <div class="nav-right">
            <!-- 미판독 발생 시 동적 펄스 뱃지 노출 -->
            <button type="button" id="btnPendingBadge" class="badge-pending-pulse" onclick="regradePending()" style="display:none;" title="미판독 문항을 즉시 AI 재채점합니다">
                <span>⚡ 미판독 <b id="pendingCount">0</b>건 [채점]</span>
            </button>

            <!-- 정답 공개 토글 스위치 -->
            <label class="toggle-switch-label" title="학생에게 모범 답안을 공개할지 여부를 설정합니다">
                <input type="checkbox" id="chkShowAnswer" onchange="toggleAnswerVisibility()" <?= $showAnswer ? 'checked' : '' ?>>
                <span class="toggle-track"></span>
                <span>답안공개</span>
            </label>

            <a href="/views/grading/print_slips.php?assign_id=<?= $assignId ?>" class="btn-nav-action" target="_blank" title="학생 배부용 정오표를 인쇄합니다">
                🖨️ 정오표
            </a>

            <!-- 동기화 상태 인디케이터 -->
            <div id="syncStatus" class="sync-status-indicator" title="서버와 실시간 동기화 완료">
                <span class="sync-dot" id="syncDot"></span>
            </div>

            <!-- 더보기 드롭다운 -->
            <div class="dropdown-wrap">
                <button type="button" class="btn-icon-more" onclick="toggleMoreMenu(event)" title="추가 작업 메뉴">⋮</button>
                <div class="dropdown-menu" id="moreMenu">
                    <button type="button" class="dropdown-item" onclick="syncWithTemplate()">🔄 템플릿 배점/정답 재동기화</button>
                    <button type="button" class="dropdown-item" onclick="syncGoogleSheet()">📊 구글 시트로 최종 성적 반영</button>
                    <div class="dropdown-divider"></div>
                    <a href="/views/grading/diagnose.php?assign_id=<?= $assignId ?>" class="dropdown-item" target="_blank">🔬 AI 정밀 진단 (엔지니어용)</a>
                </div>
            </div>
        </div>
    </header>

    <!-- 💡 상단 고정 헤더 패널 (스크롤 시에도 문항 탭과 모범답안 벤치마크 바가 항상 화면 상단에 고정) -->
    <div class="sticky-header-panel">
        <div class="sub-tabs-wrapper">
            <button type="button" class="tab-scroll-btn" onclick="scrollTabs(-1)" title="이전 문항 스크롤">‹</button>
            <div class="sub-tabs" id="subTabsContainer"></div>
            <button type="button" class="tab-scroll-btn" onclick="scrollTabs(1)" title="다음 문항 스크롤">›</button>
        </div>
        <div id="benchmarkBar" class="benchmark-bar" style="display:none;"></div>
    </div>

    <table id="reviewTable">
        <tbody id="studentList"></tbody>
    </table>

    <!-- 하단 듀얼 내비게이션 & 단축키 가이드 바 -->
    <div id="bottomShortcutBar">
        <div class="bot-nav-wrap">
            <button type="button" class="btn-bot-nav" id="btnPrevQ" onclick="navigateFilter(-1)">‹ 이전</button>
            <div class="bot-scrubber" id="botScrubber"></div>
            <button type="button" class="btn-bot-nav" id="btnNextQ" onclick="navigateFilter(1)">다음 ›</button>
        </div>
        <div id="shortcutKeyText"></div>
        <div style="color: #94a3b8; font-size: 11.5px; white-space:nowrap;">
            Shift+방향키: 크롭 확장 | [ / ] 문항 이동
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

    const btnSubjInit = document.getElementById('btnSubjectiveOnly');
    if (btnSubjInit) btnSubjInit.classList.toggle('active', showSubjectiveOnly);
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
                const statusDot = document.getElementById('syncDot');
                if (statusDot) statusDot.style.background = '#10b981';

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
                const statusDot = document.getElementById('syncDot');
                if (statusDot) statusDot.style.background = '#f59e0b';
            });
    }

    function updatePendingBadge() {
        let pendingCount = 0;
        if (Array.isArray(rawRowsData)) {
            rawRowsData.forEach(r => {
                if (r.type !== 'subjective' && (r.isAiPending || r.statusCode === -1)) {
                    pendingCount++;
                }
            });
        }
        const badge = document.getElementById('btnPendingBadge');
        const countEl = document.getElementById('pendingCount');
        if (badge && countEl) {
            countEl.textContent = pendingCount;
            badge.style.display = (pendingCount > 0) ? 'inline-flex' : 'none';
        }
    }

    function toggleMoreMenu(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('moreMenu');
        if (menu) menu.classList.toggle('show');
    }
    document.addEventListener('click', (e) => {
        const menu = document.getElementById('moreMenu');
        if (menu && !e.target.closest('.dropdown-wrap')) {
            menu.classList.remove('show');
        }
    });

    function syncGoogleSheet() {
        if (!confirm('현재까지의 검수 및 채점 결과를 교사 구글 시트에 최종 반영하시겠습니까?')) {
            return;
        }
        showToast('⏳ 구글 시트로 동기화 중...');
        fetch('/api/google/sync_sheet.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assignment_id: assignId })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('✅ 구글 시트 동기화가 성공적으로 완료되었습니다.');
            } else {
                alert('동기화 실패: ' + (data.error || '오류 발생'));
            }
        })
        .catch(err => {
            alert('네트워크 통신 오류: ' + err.message);
        });
    }

    function scrollTabs(direction) {
        const tabsEl = document.getElementById('subTabsContainer');
        if (tabsEl) {
            tabsEl.scrollBy({ left: direction * 220, behavior: 'smooth' });
        }
    }

    function regradePending() {
        const btn = document.getElementById('btnPendingBadge');
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
            }
            if (res.success) {
                showToast('✅ ' + (res.message || '미판독 문항 채점이 완료되었습니다.'));
                fetchLatestResults();
            } else {
                alert('재채점 실패: ' + (res.error || '오류가 발생했습니다.'));
                updatePendingBadge();
            }
        })
        .catch(err => {
            if (btn) btn.disabled = false;
            alert('통신 오류: ' + err.message);
            updatePendingBadge();
        });
    }

    function syncWithTemplate() {
        if (!confirm("템플릿 에디터에서 수정한 최신 정답/배점을 전체 학생 결과에 반영하시겠습니까?\n\n- 선생님이 수기 검수한 점수는 안전하게 유지됩니다.\n- 배점이 변경된 부분점수 문항은 '재검수 필요' 상태로 전환됩니다.")) {
            return;
        }
        showToast('⏳ 템플릿 동기화 중...');

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
            }
        })
        .catch(err => {
            console.error('동기화 오류:', err);
            alert('네트워크 통신 오류가 발생했습니다.');
        });
    }

    setInterval(fetchLatestResults, 3000);

    function toggleFilter() {
        showSubjectiveOnly = !showSubjectiveOnly;
        const btnSubj = document.getElementById('btnSubjectiveOnly');
        if (btnSubj) btnSubj.classList.toggle('active', showSubjectiveOnly);
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
        updatePendingBadge();
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

    function updateBottomBarNav(list, currentKey) {
        const scrubber = document.getElementById('botScrubber');
        const prevBtn = document.getElementById('btnPrevQ');
        const nextBtn = document.getElementById('btnNextQ');
        if (!scrubber) return;

        scrubber.innerHTML = '';
        const curIdx = list.indexOf(String(currentKey));

        if (prevBtn) {
            prevBtn.disabled = (curIdx <= 0);
            prevBtn.style.opacity = (curIdx <= 0) ? '0.4' : '1';
        }
        if (nextBtn) {
            nextBtn.disabled = (curIdx < 0 || curIdx >= list.length - 1);
            nextBtn.style.opacity = (curIdx < 0 || curIdx >= list.length - 1) ? '0.4' : '1';
        }

        list.forEach(key => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = `bot-scrub-chip ${String(key) === String(currentKey) ? 'active' : ''}`;
            chip.textContent = (viewMode === 'question') ? `${key}번` : key;
            chip.onclick = () => {
                selectedFilterKey = (viewMode === 'question') ? key : (parseInt(key, 10) || key);
                saveState();
                refreshTabsAndView(true);
            };
            scrubber.appendChild(chip);
        });

        const activeChip = scrubber.querySelector('.bot-scrub-chip.active');
        if (activeChip) {
            activeChip.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        }
    }

    function navigateFilter(delta) {
        if (viewMode === 'question') {
            const parentMap = new Map();
            filteredData.forEach(r => {
                const pId = String(r.parentId || '1');
                if (!parentMap.has(pId)) parentMap.set(pId, true);
            });
            const qList = Array.from(parentMap.keys()).sort((a,b) => String(a).localeCompare(String(b), undefined, { numeric: true }));
            const curIdx = qList.indexOf(String(selectedFilterKey));
            if (curIdx >= 0) {
                const nextIdx = curIdx + delta;
                if (nextIdx >= 0 && nextIdx < qList.length) {
                    selectedFilterKey = qList[nextIdx];
                    saveState();
                    refreshTabsAndView(true);
                }
            }
        } else {
            const sList = [];
            filteredData.forEach(r => {
                if (!sList.includes(r.db_id)) sList.push(r.db_id);
            });
            const curIdx = sList.indexOf(selectedFilterKey);
            if (curIdx >= 0) {
                const nextIdx = curIdx + delta;
                if (nextIdx >= 0 && nextIdx < sList.length) {
                    selectedFilterKey = sList[nextIdx];
                    saveState();
                    refreshTabsAndView(true);
                }
            }
        }
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
                const isActive = (q.parentId === selectedFilterKey);
                btn.className = `sub-tab-pill ${isActive ? 'active' : ''}`;
                btn.id = `tab-item-${q.parentId}`;
                
                let typeName = '객관식';
                let dotHtml = '';
                
                if (q.subItems.size > 1) {
                    typeName = `소문항 ${q.subItems.size}개`;
                    dotHtml = `<span class="type-dot-multi" title="${typeName}"><span></span><span></span></span>`;
                } else if (q.type === 'subjective') {
                    typeName = '주관식';
                    dotHtml = `<span class="type-dot subjective" title="${typeName}"></span>`;
                } else if (q.type === 'short_answer') {
                    typeName = '단답형';
                    dotHtml = `<span class="type-dot short_answer" title="${typeName}"></span>`;
                } else if (q.type === 'matching_line') {
                    typeName = '선잇기';
                    dotHtml = `<span class="type-dot matching_line" title="${typeName}"></span>`;
                } else {
                    dotHtml = `<span class="type-dot objective" title="${typeName}"></span>`;
                }

                btn.title = `${q.parentId}번 (${typeName})`;
                btn.innerHTML = `<span class="pill-num">${q.parentId}번</span>${dotHtml}`;
                btn.onclick = () => { 
                    selectedFilterKey = q.parentId; 
                    saveState(); 
                    refreshTabsAndView(true); 
                };
                container.appendChild(btn);
            });

            const qKeyList = questions.map(q => String(q.parentId));
            updateBottomBarNav(qKeyList, selectedFilterKey);

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
                const isActive = (s.db_id === selectedFilterKey);
                btn.className = `sub-tab-pill student-pill ${isActive ? 'active' : ''}`;
                btn.id = `tab-item-${s.db_id}`;
                btn.title = s.studentName;
                btn.innerHTML = `<span class="pill-num">${s.studentName}</span>`;
                btn.onclick = () => { selectedFilterKey = s.db_id; saveState(); refreshTabsAndView(true); };
                container.appendChild(btn);
            });

            const sKeyList = students.map(s => s.db_id);
            updateBottomBarNav(sKeyList, selectedFilterKey);

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
        
        // 활성 탭 자동 중앙 스크롤
        const activeTabEl = document.getElementById(`tab-item-${selectedFilterKey}`);
        if (activeTabEl) {
            activeTabEl.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        }

        // 이동 시 항상 학생 단위(Level 1: 'box')로 최우선 포커스!
        if (resetFocus && displayRows.length > 0) setFocus(0, 'box');
        else if (displayRows.length === 0) setFocus(-1, 'box');
    }

    function updateShortcutGuide() {
        const textEl = document.getElementById('shortcutKeyText');
        if (!textEl) return;

        if (focusLevel === 'sub') {
            textEl.innerHTML = `
                ⌨️ <b>소문항:</b> 
                <span style="color:#34d399">[1] O</span> | 
                <span style="color:#f87171">[2] X</span> | 
                <span style="color:#fbbf24">[3] △</span> | 
                <span style="color:#60a5fa">[Tab] 다음</span> | 
                <span>[Esc] 복귀</span>
            `;
        } else {
            textEl.innerHTML = `
                ⌨️ <b>단축키:</b> 
                <span style="color:#34d399">[1] 만점</span> | 
                <span style="color:#f87171">[2] 오답</span> | 
                <span style="color:#60a5fa">[Tab] 소문항</span> | 
                <span>[↑/↓] 학생</span> | 
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
                boxStatusBadge = `<span class="badge-status badge-partial">△ 부분 (${box.totalCurrentScore}점)</span>`;
            } else {
                boxStatusBadge = `<span class="badge-status badge-pending">⏳ 검수대기</span>`;
            }

            let appealHtml = '';
            if (box.hasAppeal) {
                appealHtml = `<div style="margin-top:3px;"><span class="badge-appeal">🙋 "${box.appealReason}"</span></div>`;
            }

            // 중앙 열: 박스 겹치기를 완전히 제거하고 크롭 이미지를 큼직하게 극대화한 플랫 뷰
            let subItemsCardsHtml = `<div class="sub-items-row">`;
            box.subItems.forEach((sub, subIdx) => {
                const isSubActive = (isCurrent && focusLevel === 'sub' && box.activeSubIndex === subIdx);
                
                // 소문항 번호 (소문항 2개 이상일 때만 좌상단에 미니멀 칩으로 표시)
                let tagLabelHtml = '';
                if (hasMultipleSub) {
                    const pageBadge = sub.page && sub.page > 1 ? ` ${sub.page}쪽` : '';
                    tagLabelHtml = `
                        <div class="sub-pane-tag">
                            <span>${sub.item_id}번${pageBadge}</span>
                            ${isSubActive ? '<span style="color:#2563eb; font-weight:900;">●</span>' : ''}
                        </div>
                    `;
                }

                // 소문항 개별 상태 뱃지 (우측 상단 오버레이)
                let statusBadgeHtml = '';
                if (sub.status === 'correct') {
                    statusBadgeHtml = `<span class="badge-status badge-correct sub-status-overlay">정답 (${sub.currentScore}점)</span>`;
                } else if (sub.status === 'incorrect') {
                    statusBadgeHtml = `<span class="badge-status badge-incorrect sub-status-overlay">오답 (0점)</span>`;
                } else if (sub.status === 'partial') {
                    statusBadgeHtml = `<span class="badge-status badge-partial sub-status-overlay">부분 (${sub.currentScore}점)</span>`;
                } else {
                    if (sub.isAiPending) {
                        statusBadgeHtml = `<span class="badge-status badge-pending sub-status-overlay" style="color:#2563eb;">AI 판독 중</span>`;
                    }
                }

                // AI 인식값 (세로 공간 0px 소모! 우측 하단 플로팅 오버레이 칩)
                let aiOverlayHtml = '';
                if (sub.type === 'matching_line') {
                    const pairsText = sub.rawText || '미인식';
                    aiOverlayHtml = `<div class="ai-recognition-overlay"><span>선잇기: <b>${pairsText}</b></span></div>`;
                } else if (sub.type !== 'subjective' && sub.type !== 'essay') {
                    if (sub.isAiPending) {
                        aiOverlayHtml = `<div class="ai-recognition-overlay"><span>🤖 AI 채점 중...</span></div>`;
                    } else if (sub.rawText) {
                        const isMatch = (sub.status === 'correct');
                        const isMismatch = (sub.status === 'incorrect');
                        const overlayClass = isMatch ? 'is-correct' : (isMismatch ? 'is-incorrect' : '');
                        const resText = isMatch ? '일치' : (isMismatch ? '불일치' : '');
                        aiOverlayHtml = `
                            <div class="ai-recognition-overlay ${overlayClass}">
                                <span>인식: <b>${sub.rawText}</b></span>
                                ${resText ? `<span style="font-size:0.75em; opacity:0.9;">(${resText})</span>` : ''}
                            </div>
                        `;
                    } else if (sub.isUnread) {
                        aiOverlayHtml = `<div class="ai-recognition-overlay is-incorrect"><span>⚠️ 미기재/판독불가</span></div>`;
                    }
                }

                subItemsCardsHtml += `
                    <div class="sub-crop-pane ${isSubActive ? 'active' : ''}" onclick="handleSubCardClick(${index}, ${subIdx}, event)">
                        ${tagLabelHtml}
                        ${statusBadgeHtml}
                        
                        <div class="expand-btn expand-top" onclick="expandSubImage(${index}, ${subIdx}, 'top', event)" title="Shift+↑ 확장"></div>
                        <div class="expand-btn expand-bottom" onclick="expandSubImage(${index}, ${subIdx}, 'bottom', event)" title="Shift+↓ 확장"></div>
                        <div class="expand-btn expand-left" onclick="expandSubImage(${index}, ${subIdx}, 'left', event)" title="Shift+← 확장"></div>
                        <div class="expand-btn expand-right" onclick="expandSubImage(${index}, ${subIdx}, 'right', event)" title="Shift+→ 확장"></div>

                        <img src="${sub.crop_image_url}" class="crop-img-large" id="cropImg-${index}-${subIdx}" alt="${sub.item_id} 답안 크롭">

                        ${aiOverlayHtml}
                    </div>
                `;
            });
            subItemsCardsHtml += `</div>`;

            // 우측 열: 포커스 상태(학생 전체 vs 소문항 개별)에 따른 맞춤형 채점 컨트롤
            let actionControlHtml = '';
            
            if (!isCurrent || focusLevel === 'box') {
                // [Level 1: 학생 전체 포커스] 1타 일괄 채점
                let tabDrillBtnHtml = '';
                if (hasMultipleSub && box.hasPartialScore) {
                    tabDrillBtnHtml = `
                        <div style="margin-top:4px;">
                            <button type="button" class="btn-tab-drill-compact" onclick="enterSubFocus(${index}, 0, event)">
                                🔍 <b>[Tab]</b> 소문항 개별
                            </button>
                        </div>
                    `;
                }

                actionControlHtml = `
                    <div class="score-btn-group">
                        <button class="score-btn btn-o" onclick="markAllSubScores(${index}, 'correct', event)"><b>[1]</b> 만점 (${box.totalMaxScore}점)</button>
                        <button class="score-btn btn-x" onclick="markAllSubScores(${index}, 'incorrect', event)"><b>[2]</b> 오답 (0점)</button>
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
                    presetBtnsHtml = `<div style="margin-top:4px; display:flex; gap:3px; justify-content:center; flex-wrap:wrap;">` + presetList.map(ps => 
                        `<button type="button" class="preset-score-btn" onclick="savePartialScore(${index}, ${activeSub.score}, ${ps}, event)" title="${ps}점 즉시 부여">${ps}점</button>`
                    ).join('') + `</div>`;
                }

                actionControlHtml = `
                    <div style="font-size:0.8rem; font-weight:800; color:#2563eb; margin-bottom:3px;">
                        🎯 <b>[${activeSub.item_id}번]</b> 채점 중 
                        <span style="font-size:0.72rem; color:#64748b; font-weight:normal;">(Tab: 다음)</span>
                    </div>
                    <div class="score-btn-group">
                        <button class="score-btn btn-o" onclick="markSubScore(${index}, ${curSubIdx}, ${activeSub.score}, 'correct', event)"><b>[1]</b> O (${activeSub.score})</button>
                        <button class="score-btn btn-x" onclick="markSubScore(${index}, ${curSubIdx}, 0, 'incorrect', event)"><b>[2]</b> X (0)</button>
                        <button class="score-btn btn-p" onclick="enterPartialMode(${index}, ${curSubIdx}, event)"><b>[3]</b> △</button>
                    </div>
                    <div style="margin-top:3px;">
                        <button type="button" class="btn-sub-exit" onclick="exitSubFocus(${index}, event)">
                            ↩ [Esc] 복귀
                        </button>
                    </div>

                    <div class="partial-input-wrapper">
                        <div>
                            <input type="number" class="partial-input" id="partialInput-${index}" 
                                   min="0" max="${activeSub.score}" step="0.5" 
                                   value="${activeSub.currentScore !== null ? activeSub.currentScore : Math.floor(activeSub.score/2)}">
                            <span style="font-weight:bold; font-size:0.85rem;"> / ${activeSub.score}점</span>
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
                    <div style="font-size:0.96rem; font-weight:800; color:#0f172a;">${leftHeaderTitle}</div>
                    <div class="box-score-summary" style="font-size:0.82rem; color:#475569; margin:2px 0;">
                        <b>${box.totalCurrentScore}</b> / ${box.totalMaxScore}점
                    </div>
                    <div>${boxStatusBadge}</div>
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

        // 5. 문항 간 이동 단축키 [ / ] 또는 PageUp / PageDown
        if (!isPartialMode) {
            if (e.key === '[' || e.key === 'PageUp') {
                e.preventDefault();
                navigateFilter(-1);
                return;
            }
            if (e.key === ']' || e.key === 'PageDown') {
                e.preventDefault();
                navigateFilter(1);
                return;
            }
        }

        // 6. 메인 단축키 [1], [2], [3], [ArrowUp], [ArrowDown], [Backspace]
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

    // 마우스 휠 가로 스크롤 지원
    const tabsContainerEl = document.getElementById('subTabsContainer');
    if (tabsContainerEl) {
        tabsContainerEl.addEventListener('wheel', (e) => {
            if (e.deltaY !== 0) {
                e.preventDefault();
                tabsContainerEl.scrollLeft += e.deltaY;
            }
        }, { passive: false });
    }
</script>
</body>
</html>
