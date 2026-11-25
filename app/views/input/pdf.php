<?php
/**
 * views/input/pdf.php
 * 학급별 스캔 PDF 일괄 등록 및 스마트 매칭 입력기
 * - PC 데스크톱 중심 3열 고밀도 그리드 (한눈에 18~24명 검수)
 * - 이름 가나다순 단일 정렬 기준
 * - 스티키 캔버스 돋보기 (Pan & Zoom Lightbox, 마우스 다회 드래그 지원, Esc/배경 클릭 닫기)
 * - 결석/순서 뒤바뀜 대응 원클릭 리플 시프트(Ripple Shift) 및 드래그 스왑
 * - 비파괴적 백지(Blank Page) 감지 안전망
 * - 제로-지식(Zero-PII) 브라우저 선-마스킹 및 4인 청크 안전 전송
 */

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once APP_ROOT . '/includes/common/auth.php';
require_once APP_ROOT . '/includes/common/utils.php';
require_once APP_ROOT . '/includes/common/privacy_helper.php';
require_once APP_ROOT . '/includes/common/upstage.php';
require_once APP_ROOT . '/includes/google/google_drive_helper.php';

$userId = requireTeacherAuth();

$assignId = (int)($_GET['assign_id'] ?? 0);
$studentsArray = [];
$examTitle = '평가';
$className = '';
$totalPages = 1;
$templatePages = [];
$privacyMaskRois = [];
$classSalt = '';
$classId = 0;
$sheetId = null;

if ($assignId) {
    $stmt = $pdo->prepare("
        SELECT a.id, a.class_id, a.template_id, a.exam_name, t.title as template_title, t.pages_data, t.privacy_mask_data, t.image_path, 
               c.class_name, c.sheet_id, c.class_salt 
        FROM assignments a
        JOIN templates t ON a.template_id = t.id
        LEFT JOIN classes c ON a.class_id = c.id
        WHERE a.id = :assign_id
    ");
    $stmt->execute(['assign_id' => $assignId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($assignment) {
        $examTitle = $assignment['exam_name'] ?: $assignment['template_title'];
        $className = $assignment['class_name'] ?? '우리반';
        $classId   = (int)$assignment['class_id'];
        $sheetId   = $assignment['sheet_id'] ?? null;
        $classSalt = !empty($assignment['class_salt']) ? $assignment['class_salt'] : ($classId > 0 ? getOrCreateClassSalt($pdo, $classId) : '');
        
        $templatePages = json_decode($assignment['pages_data'] ?? '[]', true) ?: [];
        $totalPages = count($templatePages) > 0 ? count($templatePages) : 1;

        $privacyMaskRois = getOrDetectPrivacyMaskRois($pdo, (int)$assignment['template_id'], $assignment);
        
        // 1. 학생 목록 조회 (DB 가명 정보)
        $stuStmt = $pdo->prepare("SELECT id, student_hash FROM students WHERE class_id = :class_id AND is_active = 1 ORDER BY order_index ASC, id ASC");
        $stuStmt->execute(['class_id' => $classId]);
        $studentsList = $stuStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. 구글 스프레드시트에서 실시간 학생 실명 조회 (Phase 3 제로-지식 조인)
        $studentNameLookup = []; // hash => realName
        if (!empty($sheetId)) {
            $token = getValidAccessToken($pdo, $userId);
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
        
        // 3. 학생 데이터 조립
        $tempStudents = [];
        $seq = 1;
        foreach ($studentsList as $stu) {
            $sHash = $stu['student_hash'] ?? '';
            $sName = $studentNameLookup[$sHash] ?? ('학생 ' . $stu['id']);
            $tempStudents[] = [
                'student_id'   => (int)$stu['id'],
                'student_hash' => $sHash,
                'name'         => $sName,
                'status'       => 'pending',
                'pages'        => []
            ];
            $seq++;
        }
        
        // 4. 이름 가나다순(한국어 표준 사전식) 단일 정렬
        usort($tempStudents, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        $studentsArray = $tempStudents;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars($examTitle) ?> - 학급 스캔 PDF 일괄 등록</title>
    
    <!-- PDF.js CDN (Mozilla 공식) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    </script>

    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --success: #10b981;
            --success-light: #ecfdf5;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-400: #94a3b8;
            --slate-300: #cbd5e1;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
            --slate-50:  #f8fafc;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f1f5f9;
            color: var(--slate-900);
            font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, "Malgun Gothic", sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* 1. 상단 프로 고정 HUD */
        #top-hud {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            z-index: 100;
            background: rgba(15, 23, 42, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            color: #fff;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .hud-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-hud-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        .btn-hud-back:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .hud-title-box {
            display: flex;
            flex-direction: column;
        }
        .hud-exam-title {
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #f8fafc;
        }
        .hud-exam-subtitle {
            font-size: 0.78rem;
            color: var(--slate-400);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hud-badge-class {
            background: rgba(37, 99, 235, 0.3);
            color: #93c5fd;
            border: 1px solid rgba(147, 197, 253, 0.3);
            padding: 1px 7px;
            border-radius: 4px;
            font-weight: 700;
        }

        .hud-center-stats {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .stat-chip.stat-total { color: #e2e8f0; }
        .stat-chip.stat-matched { color: #34d399; background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.3); }
        .stat-chip.stat-absent { color: #f87171; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); }
        .stat-chip.stat-pages { color: #60a5fa; background: rgba(37, 99, 235, 0.15); border-color: rgba(37, 99, 235, 0.3); }

        .hud-right-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-change-pdf {
            background: rgba(255, 255, 255, 0.1);
            color: #e2e8f0;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-change-pdf:hover { background: rgba(255, 255, 255, 0.2); }

        .btn-submit-all {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-submit-all:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.5);
        }
        .btn-submit-all:disabled {
            background: #475569;
            box-shadow: none;
            cursor: not-allowed;
            opacity: 0.7;
        }

        /* 2. 메인 컨테이너 */
        #main-container {
            flex: 1;
            max-width: 1720px;
            width: 100%;
            margin: 0 auto;
            padding: 20px;
        }

        /* 3. 드래그 앤 드롭 업로드존 (초기 상태) */
        #dropzone-section {
            background: #ffffff;
            border: 2px dashed #94a3b8;
            border-radius: 20px;
            padding: 60px 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.04);
            margin: 30px 0;
        }
        #dropzone-section.dragover {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: scale(1.01);
            box-shadow: 0 12px 35px rgba(37, 99, 235, 0.15);
        }
        .dropzone-icon {
            font-size: 3.5rem;
            margin-bottom: 16px;
            animation: bounce 2s infinite ease-in-out;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        .dropzone-title {
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--slate-900);
            margin-bottom: 8px;
        }
        .dropzone-desc {
            font-size: 0.95rem;
            color: var(--slate-600);
            max-width: 650px;
            margin: 0 auto 24px auto;
            line-height: 1.6;
        }
        .dropzone-badges {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 24px;
        }
        .dropzone-badge {
            background: var(--slate-100);
            color: var(--slate-700);
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 700;
            border: 1px solid var(--slate-200);
        }
        .btn-choose-file {
            background: var(--primary);
            color: #fff;
            padding: 12px 28px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 800;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.35);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-choose-file:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
        }

        /* 4. 고밀도 학생 매칭 그리드 (Desktop 3열) */
        #workspace-section {
            display: none;
        }
        .workspace-header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding: 12px 18px;
            background: #ffffff;
            border-radius: 14px;
            border: 1px solid var(--slate-200);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }
        .workspace-guide-text {
            font-size: 0.88rem;
            color: var(--slate-600);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .workspace-guide-text b {
            color: var(--slate-900);
        }
        .workspace-tools {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-tool-sm {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid var(--slate-300);
            background: #fff;
            color: var(--slate-700);
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-tool-sm:hover {
            background: var(--slate-100);
            border-color: var(--slate-400);
        }

        /* 3열 고밀도 그리드 */
        .students-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        /* 학생 카드 컴포넌트 */
        .student-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1.5px solid var(--slate-200);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: all 0.2s ease;
            position: relative;
        }
        .student-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        }
        .student-card.is-matched {
            border-color: #a7f3d0;
        }
        .student-card.is-absent {
            background: #fafafa;
            border-color: #fca5a5;
            opacity: 0.88;
        }
        .student-card.is-partial {
            border-color: #fde68a;
            background: #fffdf5;
        }
        .student-card.is-dragging {
            opacity: 0.4;
            transform: scale(0.98);
        }
        .student-card.drag-over {
            border: 2px dashed var(--primary);
            background: var(--primary-light);
        }

        /* 카드 헤더 */
        .card-header {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--slate-50);
            border-bottom: 1px solid var(--slate-200);
        }
        .card-student-title {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .student-index-badge {
            font-size: 0.76rem;
            font-weight: 800;
            color: var(--slate-500);
            background: var(--slate-200);
            padding: 2px 6px;
            border-radius: 4px;
        }
        .student-name-text {
            font-size: 1.02rem;
            font-weight: 800;
            color: var(--slate-900);
            letter-spacing: -0.01em;
        }
        .card-status-badge {
            font-size: 0.74rem;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-matched { background: var(--success-light); color: #047857; border: 1px solid #a7f3d0; }
        .badge-absent { background: var(--danger-light); color: #b91c1c; border: 1px solid #fecaca; }
        .badge-partial { background: var(--warning-light); color: #b45309; border: 1px solid #fde68a; }
        .badge-unassigned { background: var(--slate-100); color: var(--slate-500); border: 1px solid var(--slate-200); }

        /* 카드 바디 */
        .card-body {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        /* 이름란 크롭 썸네일 (돋보기 진입점) */
        .name-crop-box {
            width: 140px;
            height: 64px;
            background: #f8fafc;
            border: 1.5px solid var(--slate-300);
            border-radius: 8px;
            position: relative;
            cursor: pointer;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .name-crop-box:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            transform: scale(1.02);
        }
        .name-crop-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #fff;
        }
        .name-crop-placeholder {
            font-size: 0.75rem;
            color: var(--slate-400);
            text-align: center;
            padding: 4px;
        }
        .name-crop-hover-pill {
            position: absolute;
            bottom: 3px;
            right: 4px;
            background: rgba(15, 23, 42, 0.75);
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 4px;
            pointer-events: none;
            opacity: 0.85;
        }

        /* 페이지 썸네일 스트립 */
        .page-thumbs-strip {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .page-thumb-item {
            width: 48px;
            height: 64px;
            background: #fff;
            border: 1px solid var(--slate-300);
            border-radius: 4px;
            position: relative;
            cursor: pointer;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.15s;
        }
        .page-thumb-item:hover {
            border-color: var(--primary);
            transform: scale(1.05);
        }
        .page-thumb-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .page-num-pill {
            position: absolute;
            top: 2px;
            left: 2px;
            background: rgba(15, 23, 42, 0.75);
            color: #fff;
            font-size: 0.62rem;
            font-weight: 700;
            padding: 1px 4px;
            border-radius: 3px;
        }
        .btn-page-rotate {
            position: absolute;
            bottom: 2px;
            right: 2px;
            background: rgba(255, 255, 255, 0.9);
            color: var(--slate-800);
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            font-size: 0.62rem;
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.15s;
        }
        .page-thumb-item:hover .btn-page-rotate {
            opacity: 1;
        }

        /* 카드 푸터 (액션 툴바) */
        .card-footer {
            padding: 8px 12px;
            background: var(--slate-50);
            border-top: 1px solid var(--slate-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .footer-left {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-card-action {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            border: 1px solid var(--slate-300);
            background: #fff;
            color: var(--slate-700);
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-card-action:hover {
            background: var(--slate-100);
            border-color: var(--slate-400);
        }
        .btn-card-action.btn-absent-toggle {
            color: #dc2626;
            border-color: #fca5a5;
        }
        .btn-card-action.btn-absent-toggle:hover {
            background: #fef2f2;
            border-color: #f87171;
        }
        .btn-card-action.btn-absent-restore {
            color: #2563eb;
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .footer-right {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .btn-card-move {
            width: 26px;
            height: 26px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: 0.72rem;
            border: 1px solid var(--slate-300);
            background: #fff;
            color: var(--slate-600);
            cursor: pointer;
        }
        .btn-card-move:hover:not(:disabled) {
            background: var(--slate-100);
            color: var(--slate-900);
        }
        .btn-card-move:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .drag-handle {
            cursor: grab;
            color: var(--slate-400);
            padding: 2px 4px;
            font-size: 0.9rem;
            user-select: none;
        }

        /* 5. 스티키 캔버스 돋보기 (Pan & Zoom Lightbox Modal) */
        #lightbox-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        #lightbox-modal.is-open {
            display: flex;
        }

        .lightbox-window {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 18px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.5);
            width: 100%;
            max-width: 960px;
            height: 82vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to   { opacity: 1; transform: scale(1); }
        }

        /* 돋보기 상단 바 */
        .lightbox-header {
            padding: 12px 18px;
            background: rgba(30, 41, 59, 0.95);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #fff;
        }
        .lightbox-title-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .lightbox-title {
            font-size: 1.05rem;
            font-weight: 800;
        }
        .lightbox-page-tabs {
            display: flex;
            gap: 4px;
        }
        .lightbox-tab-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #cbd5e1;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }
        .lightbox-tab-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .lightbox-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-lightbox-tool {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-lightbox-tool:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        .zoom-level-badge {
            font-size: 0.8rem;
            font-weight: 700;
            color: #94a3b8;
            min-width: 45px;
            text-align: center;
        }
        .btn-lightbox-close {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.15s;
            margin-left: 6px;
        }
        .btn-lightbox-close:hover {
            background: rgba(239, 68, 68, 0.4);
            color: #fff;
        }

        /* 돋보기 뷰포트 (자유 Pan & Zoom 캔버스) */
        .lightbox-viewport {
            flex: 1;
            background: #090d16;
            position: relative;
            overflow: hidden;
            cursor: grab;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .lightbox-viewport.is-dragging {
            cursor: grabbing;
        }
        #lightbox-canvas {
            position: absolute;
            transform-origin: center center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8);
            border-radius: 4px;
            background: #fff;
        }

        /* 돋보기 하단 가이드 팁 */
        .lightbox-footer-tips {
            padding: 8px 16px;
            background: rgba(15, 23, 42, 0.95);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            font-size: 0.76rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* 6. 알림 토스트 (Toast) */
        #toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .toast-msg {
            background: rgba(15, 23, 42, 0.95);
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
            border: 1px solid rgba(255, 255, 255, 0.15);
            animation: toastIn 0.25s ease-out;
            pointer-events: auto;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* 7. 로딩 오버레이 */
        #loading-overlay {
            position: fixed;
            top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(8px);
            z-index: 3000;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.2);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .loading-title {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .loading-subtitle {
            font-size: 0.9rem;
            color: #94a3b8;
        }

        /* 반응형 모바일 (768px 이하) */
        @media (max-width: 1024px) {
            .students-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 640px) {
            #top-hud {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 10px 14px;
            }
            .hud-center-stats {
                overflow-x: auto;
                padding-bottom: 4px;
            }
            .students-grid {
                grid-template-columns: 1fr;
            }
            .lightbox-window {
                height: 95vh;
                border-radius: 10px;
            }
            .name-crop-box {
                width: 105px;
                height: 56px;
            }
        }
    </style>
</head>
<body>

    <!-- 1. 상단 HUD -->
    <header id="top-hud">
        <div class="hud-left">
            <a href="/_tmp_server_main.php" class="btn-hud-back">⬅ 대시보드</a>
            <div class="hud-title-box">
                <div class="hud-exam-title"><?= htmlspecialchars($examTitle) ?></div>
                <div class="hud-exam-subtitle">
                    <span class="hud-badge-class"><?= htmlspecialchars($className) ?></span>
                    <span>시험지 1인당 <b><?= $totalPages ?>쪽</b></span>
                </div>
            </div>
        </div>

        <div class="hud-center-stats">
            <div class="stat-chip stat-total" title="학급 등록 학생">
                👥 전체 <b id="stat-total-count"><?= count($studentsArray) ?></b>명
            </div>
            <div class="stat-chip stat-matched" title="시험지 매칭 완료 학생">
                ✓ 매칭 <b id="stat-matched-count">0</b>명
            </div>
            <div class="stat-chip stat-absent" title="결석/응시 제외 학생">
                🚫 결석 <b id="stat-absent-count">0</b>명
            </div>
            <div class="stat-chip stat-pages" title="스캔 PDF 총 페이지 수">
                📄 스캔 <b id="stat-scanned-pages">0</b>쪽
            </div>
        </div>

        <div class="hud-right-actions">
            <input type="file" id="pdf-file-input" accept="application/pdf" style="display:none;">
            <button type="button" class="btn-change-pdf" id="btn-reupload-pdf" style="display:none;" onclick="document.getElementById('pdf-file-input').click();">
                📁 다른 PDF 선택
            </button>
            <button type="button" class="btn-submit-all" id="btn-final-submit" disabled onclick="handleFinalSubmit();">
                🚀 채점 전송 (0명)
            </button>
        </div>
    </header>

    <!-- 2. 본문 영역 -->
    <main id="main-container">

        <!-- 초기 PDF 드래그 & 드롭 영역 -->
        <section id="dropzone-section" onclick="document.getElementById('pdf-file-input').click();">
            <div class="dropzone-icon">📑</div>
            <h2 class="dropzone-title">학급별 스캔 PDF 파일을 이곳에 끌어다 놓으세요</h2>
            <p class="dropzone-desc">
                복합기에서 학생 시험지를 <b>가나다 순</b>으로 스캔한 PDF 파일을 첨부하면 자동으로 학생별 1:1 슬롯에 배치됩니다.
                중간에 결석자가 있거나 순서가 뒤바뀐 경우 <b>원클릭으로 밀어내기/순서 교환</b>이 가능합니다.
            </p>
            <div class="dropzone-badges">
                <span class="dropzone-badge">🔒 제로-PII 안심: 학생 이름은 서버로 전송되지 않고 브라우저에서 안전하게 마스킹</span>
                <span class="dropzone-badge">📄 1인당 <?= $totalPages ?>쪽 자동 분할</span>
                <span class="dropzone-badge">🔍 손글씨 이름 돋보기 지원 (단순 클릭으로 즉시 확인)</span>
            </div>
            <button type="button" class="btn-choose-file">
                📁 PC에서 PDF 파일 선택하기
            </button>
        </section>

        <!-- 3열 고밀도 학생 매칭 워크스페이스 -->
        <section id="workspace-section">
            <div class="workspace-header-bar">
                <div class="workspace-guide-text">
                    💡 <b>초고속 매칭 검수 팁:</b> 이름 칸 썸네일을 <b>클릭</b>하면 고화질 돋보기가 열립니다. 마우스를 드래그하여 시험지 전체를 찾아볼 수 있으며, <b>`Esc` 키</b>나 <b>배경 클릭</b>으로 즉시 닫힙니다.
                </div>
                <div class="workspace-tools">
                    <button type="button" class="btn-tool-sm" onclick="autoDetectBlankPages();">
                        🧹 백지 자동 점검
                    </button>
                    <button type="button" class="btn-tool-sm" onclick="resetAllAssignments();">
                        🔄 초기 가나다순 재배치
                    </button>
                </div>
            </div>

            <!-- 학생 카드 3열 그리드 컨테이너 -->
            <div class="students-grid" id="students-grid"></div>
        </section>

    </main>

    <!-- 3. 스티키 캔버스 돋보기 (Sticky Pan & Zoom Lightbox Modal) -->
    <div id="lightbox-modal">
        <div class="lightbox-window">
            <div class="lightbox-header">
                <div class="lightbox-title-box">
                    <div class="lightbox-title" id="lightbox-student-title">🔍 학생 시험지 정밀 검수</div>
                    <div class="lightbox-page-tabs" id="lightbox-page-tabs"></div>
                </div>
                <div class="lightbox-toolbar">
                    <button type="button" class="btn-lightbox-tool" onclick="lightboxFitNameRoi();" title="이름 기재란 영역으로 즉시 초점 이동">🎯 이름란 맞춤</button>
                    <button type="button" class="btn-lightbox-tool" onclick="lightboxResetView();" title="전체 시험지 100% 배율로 초기화">↩️ 원본 보기</button>
                    <button type="button" class="btn-lightbox-tool" onclick="lightboxRotatePage();" title="90도 회전">🔄 회전</button>
                    <span class="zoom-level-badge" id="lightbox-zoom-badge">100%</span>
                    <button type="button" class="btn-lightbox-close" onclick="closeLightbox();" title="닫기 (Esc 키)">✕ 닫기</button>
                </div>
            </div>

            <!-- 마우스 다회 드래그 (Grab & Drag) 지원 뷰포트 -->
            <div class="lightbox-viewport" id="lightbox-viewport">
                <canvas id="lightbox-canvas"></canvas>
            </div>

            <div class="lightbox-footer-tips">
                <span>🖱️ <b>드래그:</b> 시험지 자유 이동 (마우스를 뗐다 다시 잡아도 유지) | <b>휠 스크롤:</b> 확대/축소 | <b>Esc:</b> 닫기</span>
                <span>🔒 개인정보 보호: 1쪽 인적사항은 채점 전송 시 단색으로 자동 가림 처리됩니다.</span>
            </div>
        </div>
    </div>

    <!-- 4. 로딩 오버레이 -->
    <div id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-title" id="loading-title">PDF 시험지 분석 중...</div>
        <div class="loading-subtitle" id="loading-subtitle">고해상도 페이지 렌더링을 진행하고 있습니다.</div>
    </div>

    <!-- 5. 토스트 메시지 -->
    <div id="toast-container"></div>

    <script>
    // ==========================================
    // [1단계] 서버 주입 데이터 및 글로벌 상태
    // ==========================================
    const assignId        = <?= $assignId ?>;
    const totalPages      = <?= (int)$totalPages ?>;
    const templatePages   = <?= json_encode($templatePages, JSON_UNESCAPED_UNICODE) ?>;
    const privacyMaskRois = <?= json_encode($privacyMaskRois, JSON_UNESCAPED_UNICODE) ?>;
    
    // 학생 원본 목록 (이름 가나다순 단일 정렬 완료 상태)
    let students = <?= json_encode($studentsArray, JSON_UNESCAPED_UNICODE) ?>;
    // JS 로케일 기준 완벽한 한국어 가나다순 보정
    students.sort((a, b) => a.name.localeCompare(b.name, 'ko'));

    // 스캔된 원본 PDF 페이지 풀 (배열: { pageIndex, canvas, isBlankCandidate, rotation })
    let scannedPages = [];
    
    // 돋보기(Lightbox) 활성 상태
    let activeLightbox = {
        studentIdx: null,
        pageIdx: 0,
        scale: 1.0,
        panX: 0,
        panY: 0,
        isDragging: false,
        startX: 0,
        startY: 0
    };

    // ==========================================
    // [2단계] 토스트 알림 헬퍼
    // ==========================================
    function showToast(msg, duration = 3000) {
        const container = document.getElementById('toast-container');
        const el = document.createElement('div');
        el.className = 'toast-msg';
        el.innerHTML = msg;
        container.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.3s';
            setTimeout(() => el.remove(), 300);
        }, duration);
    }

    // ==========================================
    // [3단계] PDF 파일 업로드 및 클라이언트 파싱
    // ==========================================
    const dropzoneSection = document.getElementById('dropzone-section');
    const pdfFileInput = document.getElementById('pdf-file-input');

    // 드래그 앤 드롭 이벤트 리스너
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzoneSection.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzoneSection.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropzoneSection.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzoneSection.classList.remove('dragover');
        });
    });
    dropzoneSection.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        if (files && files.length > 0) {
            handlePdfFile(files[0]);
        }
    });
    pdfFileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
            handlePdfFile(e.target.files[0]);
        }
    });

    async function handlePdfFile(file) {
        if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
            alert('PDF 파일만 등록할 수 있습니다.');
            return;
        }

        const loadingOverlay = document.getElementById('loading-overlay');
        const loadingTitle   = document.getElementById('loading-title');
        const loadingSub     = document.getElementById('loading-subtitle');
        loadingOverlay.style.display = 'flex';
        loadingTitle.innerText = 'PDF 시험지 렌더링 중...';
        loadingSub.innerText = '페이지를 분석하고 있습니다.';

        try {
            const arrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: new Uint8Array(arrayBuffer) }).promise;
            const totalPdfPages = pdf.numPages;

            scannedPages = [];

            // A4 최적 해상도 스케일 (가로 1400~1600px 수준)
            const renderScale = 1.6;

            for (let pageNum = 1; pageNum <= totalPdfPages; pageNum++) {
                loadingSub.innerText = `${pageNum} / ${totalPdfPages}쪽 고화질 변환 중...`;
                const page = await pdf.getPage(pageNum);
                const viewport = page.getViewport({ scale: renderScale });

                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                const ctx = canvas.getContext('2d');

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                // 비파괴적 백지(Blank Page) 감지
                const isBlank = detectBlankPageCanvas(canvas);

                scannedPages.push({
                    pageIndex: pageNum - 1,
                    pageNum: pageNum,
                    canvas: canvas,
                    rotation: 0,
                    isBlankCandidate: isBlank,
                    assignedStudentId: null
                });
            }

            loadingOverlay.style.display = 'none';
            document.getElementById('dropzone-section').style.display = 'none';
            document.getElementById('workspace-section').style.display = 'block';
            document.getElementById('btn-reupload-pdf').style.display = 'inline-flex';

            // 초기 가나다순 1:1 슬롯 자동 매핑 실행
            mapScannedPagesToStudents();
            renderStudentsGrid();

            // 분량 비교 안내
            const expectedPages = students.length * totalPages;
            if (scannedPages.length !== expectedPages) {
                showToast(`ℹ️ 안내: 학급 인원(${students.length}명) 기준 예상은 ${expectedPages}쪽이나, 스캔된 PDF는 ${scannedPages.length}쪽입니다. 결석자 또는 스캔 누락 여부를 확인해주세요.`, 5000);
            } else {
                showToast(`✓ PDF 총 ${scannedPages.length}쪽이 학생 명단(가나다순)에 정확히 1:1 자동 매핑되었습니다!`);
            }

        } catch (err) {
            console.error('PDF 로드 실패:', err);
            loadingOverlay.style.display = 'none';
            alert('PDF 파일을 처리하는 도중 오류가 발생했습니다: ' + err.message);
        }
    }

    // ==========================================
    // [4단계] 비파괴적 백지(Blank Page) 감지 알고리즘
    // ==========================================
    function detectBlankPageCanvas(canvas) {
        // 성능을 위해 작은 캔버스(100x140)로 샘플링
        const sampleW = 100;
        const sampleH = 140;
        const sCanvas = document.createElement('canvas');
        sCanvas.width = sampleW;
        sCanvas.height = sampleH;
        const sCtx = sCanvas.getContext('2d');
        sCtx.drawImage(canvas, 0, 0, sampleW, sampleH);

        const imgData = sCtx.getImageData(0, 0, sampleW, sampleH);
        const data = imgData.data;
        let whitePixels = 0;
        const total = data.length / 4;

        for (let i = 0; i < data.length; i += 4) {
            const r = data[i], g = data[i+1], b = data[i+2];
            // 밝기(Luminance) 계산
            const lum = 0.299 * r + 0.587 * g + 0.114 * b;
            if (lum > 242) { // 95% 이상 밝기
                whitePixels++;
            }
        }
        const whiteRatio = whitePixels / total;
        return whiteRatio > 0.993; // 99.3% 이상 백색이면 백지 후보로 판정
    }

    function autoDetectBlankPages() {
        let blankCount = 0;
        scannedPages.forEach(p => {
            if (p.isBlankCandidate) blankCount++;
        });
        if (blankCount === 0) {
            showToast('✓ 백지 의심 페이지가 발견되지 않았습니다.');
        } else {
            showToast(`📄 백지 의심 페이지가 총 ${blankCount}쪽 감지되어 있습니다.`);
        }
    }

    // ==========================================
    // [5단계] 가나다순 1:1 슬롯 매핑 & 리플 시프트
    // ==========================================
    function mapScannedPagesToStudents() {
        // 백지 후보는 기본적으로 유효 스캔 목록에서 건너뜀 (단, 필요 시 토글)
        const validPages = scannedPages.filter(p => !p.isBlankCandidate);

        let pageCursor = 0;
        students.forEach((stu) => {
            if (stu.status === 'absent') {
                stu.pages = [];
                return;
            }

            stu.pages = [];
            for (let p = 0; p < totalPages; p++) {
                if (pageCursor < validPages.length) {
                    stu.pages.push(validPages[pageCursor]);
                    pageCursor++;
                }
            }

            if (stu.pages.length === totalPages) {
                stu.status = 'matched';
            } else if (stu.pages.length > 0) {
                stu.status = 'partial';
            } else {
                stu.status = 'unassigned';
            }
        });

        updateStatsHUD();
    }

    function resetAllAssignments() {
        if (!confirm('모든 결석 및 순서 변경을 초기화하고, 스캔 순서대로 가나다순 1:1 재배치를 진행하시겠습니까?')) return;
        students.forEach(s => s.status = 'pending');
        mapScannedPagesToStudents();
        renderStudentsGrid();
        showToast('✓ 초기 가나다순 매칭으로 재배치되었습니다.');
    }

    // ==========================================
    // [6단계] 결석 원클릭 리플 시프트 (Ripple Shift)
    // ==========================================
    function toggleStudentAbsent(studentIndex) {
        const target = students[studentIndex];
        if (!target) return;

        if (target.status === 'absent') {
            // [결석 취소] -> 다시 매칭 상태로 복원하고, 이후 학생들의 시험지를 1슬롯씩 위로 당김 (Ripple Up)
            target.status = 'pending';
            recalculateRippleShift();
            showToast(`↩️ <b>${target.name}</b> 학생의 결석이 취소되고 시험지가 재정렬되었습니다.`);
        } else {
            // [결석 처리] -> 해당 슬롯 비우고, 이후 학생들의 시험지를 1슬롯씩 아래로 밀어냄 (Ripple Down)
            target.status = 'absent';
            recalculateRippleShift();
            showToast(`🚫 <b>${target.name}</b> 학생이 결석 처리되어 이후 시험지가 한 칸씩 자동으로 밀려났습니다.`);
        }
        renderStudentsGrid();
    }

    function recalculateRippleShift() {
        // 유효 스캔 페이지 목록 순서대로, 결석이 아닌 학생들에게만 순차 재할당
        const validPages = scannedPages.filter(p => !p.isBlankCandidate);
        let pageCursor = 0;

        students.forEach((stu) => {
            if (stu.status === 'absent') {
                stu.pages = [];
                return;
            }

            stu.pages = [];
            for (let p = 0; p < totalPages; p++) {
                if (pageCursor < validPages.length) {
                    stu.pages.push(validPages[pageCursor]);
                    pageCursor++;
                }
            }

            if (stu.pages.length === totalPages) {
                stu.status = 'matched';
            } else if (stu.pages.length > 0) {
                stu.status = 'partial';
            } else {
                stu.status = 'unassigned';
            }
        });

        updateStatsHUD();
    }

    // 두 학생의 시험지 순서 맞바꾸기 (Swap)
    function swapStudentPages(idxA, idxB) {
        if (idxA < 0 || idxA >= students.length || idxB < 0 || idxB >= students.length) return;
        const tempPages  = students[idxA].pages;
        const tempStatus = students[idxA].status;

        students[idxA].pages  = students[idxB].pages;
        students[idxA].status = students[idxB].status;

        students[idxB].pages  = tempPages;
        students[idxB].status = tempStatus;

        renderStudentsGrid();
        updateStatsHUD();
        showToast(`⇅ ${students[idxA].name} ↔ ${students[idxB].name} 시험지 위치가 맞바뀌었습니다.`);
    }

    // ==========================================
    // [7단계] 3열 고밀도 그리드 렌더링
    // ==========================================
    function renderStudentsGrid() {
        const grid = document.getElementById('students-grid');
        grid.innerHTML = '';

        students.forEach((s, idx) => {
            const card = document.createElement('div');
            card.className = `student-card is-${s.status}`;
            card.id = `student-card-${idx}`;
            card.draggable = true;

            // 드래그 앤 드롭 스왑 리스너
            card.ondragstart = (e) => {
                e.dataTransfer.setData('text/plain', idx);
                card.classList.add('is-dragging');
            };
            card.ondragend = () => card.classList.remove('is-dragging');
            card.ondragover = (e) => { e.preventDefault(); card.classList.add('drag-over'); };
            card.ondragleave = () => card.classList.remove('drag-over');
            card.ondrop = (e) => {
                e.preventDefault();
                card.classList.remove('drag-over');
                const srcIdx = parseInt(e.dataTransfer.getData('text/plain'), 10);
                if (!isNaN(srcIdx) && srcIdx !== idx) {
                    swapStudentPages(srcIdx, idx);
                }
            };

            // 상태 뱃지 라벨
            let statusBadgeHtml = '';
            if (s.status === 'matched') {
                statusBadgeHtml = `<span class="card-status-badge badge-matched">✓ 정상</span>`;
            } else if (s.status === 'absent') {
                statusBadgeHtml = `<span class="card-status-badge badge-absent">🚫 결석</span>`;
            } else if (s.status === 'partial') {
                statusBadgeHtml = `<span class="card-status-badge badge-partial">⚠️ ${s.pages.length}/${totalPages}쪽</span>`;
            } else {
                statusBadgeHtml = `<span class="card-status-badge badge-unassigned">대기</span>`;
            }

            // 이름란 크롭 썸네일 생성 (1쪽의 인적사항 기재란 크롭)
            let nameCropHtml = '';
            if (s.pages && s.pages[0]) {
                const nameCropDataUrl = extractNameRoiCrop(s.pages[0].canvas);
                nameCropHtml = `
                    <div class="name-crop-box" onclick="openLightbox(${idx}, 0)" title="클릭하여 고화질 돋보기 열기">
                        <img src="${nameCropDataUrl}" alt="${s.name} 이름란">
                        <span class="name-crop-hover-pill">🔍 돋보기</span>
                    </div>
                `;
            } else {
                nameCropHtml = `
                    <div class="name-crop-box" style="background:#f1f5f9; cursor:default;">
                        <span class="name-crop-placeholder">${s.status === 'absent' ? '결석으로 비움' : '시험지 미배정'}</span>
                    </div>
                `;
            }

            // 페이지별 미니 썸네일 스트립
            let pageThumbsHtml = '<div class="page-thumbs-strip">';
            if (s.pages && s.pages.length > 0) {
                s.pages.forEach((pObj, pIdx) => {
                    const thumbUrl = getThumbnailDataUrl(pObj.canvas);
                    pageThumbsHtml += `
                        <div class="page-thumb-item" onclick="openLightbox(${idx}, ${pIdx})" title="${pIdx + 1}쪽 검수">
                            <span class="page-num-pill">${pIdx + 1}쪽</span>
                            <img src="${thumbUrl}" alt="${pIdx + 1}쪽">
                            <button type="button" class="btn-page-rotate" onclick="event.stopPropagation(); rotatePage(${idx}, ${pIdx});" title="90도 회전">🔄</button>
                        </div>
                    `;
                });
            }
            pageThumbsHtml += '</div>';

            // 카드 조립
            card.innerHTML = `
                <div class="card-header">
                    <div class="card-student-title">
                        <span class="student-index-badge">${idx + 1}</span>
                        <span class="student-name-text">${s.name}</span>
                    </div>
                    ${statusBadgeHtml}
                </div>
                <div class="card-body">
                    ${nameCropHtml}
                    ${pageThumbsHtml}
                </div>
                <div class="card-footer">
                    <div class="footer-left">
                        ${s.status === 'absent' 
                            ? `<button type="button" class="btn-card-action btn-absent-restore" onclick="toggleStudentAbsent(${idx})">↩️ 결석 취소</button>`
                            : `<button type="button" class="btn-card-action btn-absent-toggle" onclick="toggleStudentAbsent(${idx})">🚫 결석</button>`
                        }
                    </div>
                    <div class="footer-right">
                        <button type="button" class="btn-card-move" onclick="swapStudentPages(${idx}, ${idx - 1})" ${idx === 0 ? 'disabled' : ''} title="위 학생과 맞바꾸기">▲</button>
                        <button type="button" class="btn-card-move" onclick="swapStudentPages(${idx}, ${idx + 1})" ${idx === students.length - 1 ? 'disabled' : ''} title="아래 학생과 맞바꾸기">▼</button>
                        <span class="drag-handle" title="드래그하여 원하는 위치로 맞바꾸기">⋮⋮</span>
                    </div>
                </div>
            `;
            grid.appendChild(card);
        });

        updateStatsHUD();
    }

    // 이름란 크롭 이미지 추출 헬퍼 (privacyMaskRois[0] 기반)
    function extractNameRoiCrop(srcCanvas) {
        if (!srcCanvas) return '';
        const tplW = (templatePages[0] && templatePages[0].width) ? templatePages[0].width : 1200;
        const tplH = (templatePages[0] && templatePages[0].height) ? templatePages[0].height : 1700;
        const scaleX = srcCanvas.width / tplW;
        const scaleY = srcCanvas.height / tplH;

        let roi = (privacyMaskRois && privacyMaskRois.length > 0) ? privacyMaskRois[0] : null;
        if (!roi) {
            // 기본 우측 상단 인적사항 영역 폴백
            roi = { x: tplW * 0.55, y: tplH * 0.02, w: tplW * 0.42, h: tplH * 0.09 };
        }

        // 여백을 약간 포함하여 크롭
        const padX = (roi.w || 300) * 0.1;
        const padY = (roi.h || 100) * 0.15;
        const rx = Math.max(0, ((roi.x || 0) - padX) * scaleX);
        const ry = Math.max(0, ((roi.y || 0) - padY) * scaleY);
        const rw = Math.min(srcCanvas.width - rx, ((roi.w || 300) + padX * 2) * scaleX);
        const rh = Math.min(srcCanvas.height - ry, ((roi.h || 100) + padY * 2) * scaleY);

        const cropCanvas = document.createElement('canvas');
        cropCanvas.width = rw;
        cropCanvas.height = rh;
        const ctx = cropCanvas.getContext('2d');
        ctx.drawImage(srcCanvas, rx, ry, rw, rh, 0, 0, rw, rh);

        return cropCanvas.toDataURL('image/jpeg', 0.82);
    }

    function getThumbnailDataUrl(canvas) {
        if (!canvas) return '';
        const thumbCanvas = document.createElement('canvas');
        thumbCanvas.width = 120;
        thumbCanvas.height = Math.round(120 * (canvas.height / canvas.width));
        const ctx = thumbCanvas.getContext('2d');
        ctx.drawImage(canvas, 0, 0, thumbCanvas.width, thumbCanvas.height);
        return thumbCanvas.toDataURL('image/jpeg', 0.7);
    }

    function rotatePage(studentIdx, pageIdx) {
        const pObj = students[studentIdx].pages[pageIdx];
        if (!pObj) return;

        pObj.rotation = (pObj.rotation + 90) % 360;

        // 캔버스 90도 회전 적용
        const oldCanvas = pObj.canvas;
        const newCanvas = document.createElement('canvas');
        newCanvas.width = oldCanvas.height;
        newCanvas.height = oldCanvas.width;
        const ctx = newCanvas.getContext('2d');
        ctx.translate(newCanvas.width / 2, newCanvas.height / 2);
        ctx.rotate(90 * Math.PI / 180);
        ctx.drawImage(oldCanvas, -oldCanvas.width / 2, -oldCanvas.height / 2);

        pObj.canvas = newCanvas;
        renderStudentsGrid();
        showToast(`✓ ${students[studentIdx].name} 학생의 ${pageIdx + 1}쪽이 90도 회전되었습니다.`);
    }

    // ==========================================
    // [8단계] 스티키 캔버스 돋보기 (Pan & Zoom Lightbox)
    // ==========================================
    const lightboxModal    = document.getElementById('lightbox-modal');
    const lightboxViewport = document.getElementById('lightbox-viewport');
    const lightboxCanvas   = document.getElementById('lightbox-canvas');

    function openLightbox(studentIdx, pageIdx = 0) {
        const student = students[studentIdx];
        if (!student || !student.pages || student.pages.length === 0) return;

        activeLightbox.studentIdx = studentIdx;
        activeLightbox.pageIdx = pageIdx;
        activeLightbox.scale = 1.0;
        activeLightbox.panX = 0;
        activeLightbox.panY = 0;

        document.getElementById('lightbox-student-title').innerText = `🔍 ${studentIdx + 1}. ${student.name} 학생 시험지 검수`;

        // 다쪽 시험지 탭 렌더링
        const tabsBox = document.getElementById('lightbox-page-tabs');
        tabsBox.innerHTML = '';
        if (student.pages.length > 1) {
            student.pages.forEach((p, idx) => {
                const btn = document.createElement('button');
                btn.className = `lightbox-tab-btn ${idx === pageIdx ? 'active' : ''}`;
                btn.innerText = `${idx + 1}쪽${idx === 0 ? ' (이름란)' : ''}`;
                btn.onclick = () => {
                    activeLightbox.pageIdx = idx;
                    renderLightboxCurrentPage();
                };
                tabsBox.appendChild(btn);
            });
        }

        lightboxModal.classList.add('is-open');
        renderLightboxCurrentPage();

        // 1쪽이면 자동으로 이름 기재란 영역으로 즉시 초점 이동 (사용자 편의)
        if (pageIdx === 0) {
            setTimeout(lightboxFitNameRoi, 50);
        }
    }

    function closeLightbox() {
        lightboxModal.classList.remove('is-open');
    }

    // ESC 키로 닫기 지원
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && lightboxModal.classList.contains('is-open')) {
            closeLightbox();
        }
    });

    // 배경(Backdrop) 단순 클릭 시 즉시 닫기
    lightboxModal.addEventListener('click', (e) => {
        if (e.target === lightboxModal) {
            closeLightbox();
        }
    });

    function renderLightboxCurrentPage() {
        const student = students[activeLightbox.studentIdx];
        const pageObj = student.pages[activeLightbox.pageIdx];
        if (!pageObj) return;

        const srcCanvas = pageObj.canvas;
        lightboxCanvas.width = srcCanvas.width;
        lightboxCanvas.height = srcCanvas.height;
        const ctx = lightboxCanvas.getContext('2d');
        ctx.drawImage(srcCanvas, 0, 0);

        applyLightboxTransform();
    }

    function applyLightboxTransform() {
        lightboxCanvas.style.transform = `translate(${activeLightbox.panX}px, ${activeLightbox.panY}px) scale(${activeLightbox.scale})`;
        document.getElementById('lightbox-zoom-badge').innerText = `${Math.round(activeLightbox.scale * 100)}%`;
    }

    // 이름 기재란 영역으로 즉시 포커스 이동
    function lightboxFitNameRoi() {
        const student = students[activeLightbox.studentIdx];
        const pageObj = student.pages[activeLightbox.pageIdx];
        if (!pageObj) return;

        const tplW = (templatePages[0] && templatePages[0].width) ? templatePages[0].width : 1200;
        const tplH = (templatePages[0] && templatePages[0].height) ? templatePages[0].height : 1700;
        let roi = (privacyMaskRois && privacyMaskRois.length > 0) ? privacyMaskRois[0] : { x: tplW * 0.6, y: tplH * 0.05, w: 300, h: 100 };

        activeLightbox.scale = 2.2; // 220% 확대
        const roiCenterX = (roi.x + (roi.w / 2)) * (pageObj.canvas.width / tplW);
        const roiCenterY = (roi.y + (roi.h / 2)) * (pageObj.canvas.height / tplH);

        // 뷰포트 중앙과 ROI 중심 일치 계산
        const vW = lightboxViewport.clientWidth;
        const vH = lightboxViewport.clientHeight;
        const cW = pageObj.canvas.width;
        const cH = pageObj.canvas.height;

        activeLightbox.panX = (vW / 2) - ((cW / 2) + (roiCenterX - (cW / 2))) * activeLightbox.scale;
        activeLightbox.panY = (vH / 2) - ((cH / 2) + (roiCenterY - (cH / 2))) * activeLightbox.scale;

        applyLightboxTransform();
    }

    function lightboxResetView() {
        activeLightbox.scale = 1.0;
        activeLightbox.panX = 0;
        activeLightbox.panY = 0;
        applyLightboxTransform();
    }

    function lightboxRotatePage() {
        rotatePage(activeLightbox.studentIdx, activeLightbox.pageIdx);
        renderLightboxCurrentPage();
    }

    // 마우스 다회 드래그(Grab & Drag) & 마우스 휠 줌 이벤트
    lightboxViewport.addEventListener('mousedown', (e) => {
        if (e.button !== 0) return; // 좌클릭만
        activeLightbox.isDragging = true;
        activeLightbox.startX = e.clientX - activeLightbox.panX;
        activeLightbox.startY = e.clientY - activeLightbox.panY;
        lightboxViewport.classList.add('is-dragging');
    });

    window.addEventListener('mousemove', (e) => {
        if (!activeLightbox.isDragging) return;
        activeLightbox.panX = e.clientX - activeLightbox.startX;
        activeLightbox.panY = e.clientY - activeLightbox.startY;
        applyLightboxTransform();
    });

    window.addEventListener('mouseup', () => {
        if (activeLightbox.isDragging) {
            activeLightbox.isDragging = false;
            lightboxViewport.classList.remove('is-dragging');
        }
    });

    lightboxViewport.addEventListener('wheel', (e) => {
        e.preventDefault();
        const zoomDelta = e.deltaY < 0 ? 0.2 : -0.2;
        let newScale = Math.min(4.0, Math.max(0.6, activeLightbox.scale + zoomDelta));
        activeLightbox.scale = newScale;
        applyLightboxTransform();
    }, { passive: false });

    // ==========================================
    // [9단계] 제로-지식 단말기 선-마스킹 및 청크 전송
    // ==========================================
    function updateStatsHUD() {
        const totalCount   = students.length;
        const matchedCount = students.filter(s => s.status === 'matched').length;
        const absentCount  = students.filter(s => s.status === 'absent').length;

        document.getElementById('stat-total-count').innerText = totalCount;
        document.getElementById('stat-matched-count').innerText = matchedCount;
        document.getElementById('stat-absent-count').innerText = absentCount;
        document.getElementById('stat-scanned-pages').innerText = scannedPages.length;

        const submitBtn = document.getElementById('btn-final-submit');
        submitBtn.disabled = (matchedCount === 0);
        submitBtn.innerText = `🚀 채점 전송 (${matchedCount}명)`;
    }

    // 단말기 캔버스 선-마스킹 (privacy_mask_data 덮어씌움)
    function applyClientPrivacyMask(canvas, pageNum) {
        if (pageNum !== 1 || !privacyMaskRois || !Array.isArray(privacyMaskRois) || privacyMaskRois.length === 0) {
            return false;
        }
        const tplW = (templatePages[0] && templatePages[0].width) ? templatePages[0].width : 1200;
        const tplH = (templatePages[0] && templatePages[0].height) ? templatePages[0].height : 1700;
        const scaleX = canvas.width / tplW;
        const scaleY = canvas.height / tplH;

        const ctx = canvas.getContext('2d');
        ctx.save();
        ctx.fillStyle = '#1e293b'; // 단색 불투명 직사각형 마스킹

        let masked = false;
        privacyMaskRois.forEach(roi => {
            if ((roi.page || 1) === 1) {
                const rx = Math.max(0, Math.round((roi.x || 0) * scaleX));
                const ry = Math.max(0, Math.round((roi.y || 0) * scaleY));
                const rw = Math.min(canvas.width - rx, Math.round((roi.w || 0) * scaleX));
                const rh = Math.min(canvas.height - ry, Math.round((roi.h || 0) * scaleY));
                if (rw > 5 && rh > 5) {
                    ctx.fillRect(rx, ry, rw, rh);
                    masked = true;
                }
            }
        });
        ctx.restore();
        return masked;
    }

    function canvasToBlob(canvas, quality = 0.88) {
        return new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
    }

    async function handleFinalSubmit() {
        const matchedStudents = students.filter(s => s.status === 'matched');
        if (matchedStudents.length === 0) {
            alert('전송할 수 있는 시험지 매칭 완료 학생이 없습니다.');
            return;
        }

        const confirmMsg = `총 ${matchedStudents.length}명의 시험지를 전송하시겠습니까?\n\n🔒 개인정보 보호: 학생 인적사항 영역은 단말기에서 단색으로 자동 선-마스킹되어 서버로 안전하게 전송됩니다.`;
        if (!confirm(confirmMsg)) return;

        const submitBtn = document.getElementById('btn-final-submit');
        submitBtn.disabled = true;

        const loadingOverlay = document.getElementById('loading-overlay');
        const loadingTitle   = document.getElementById('loading-title');
        const loadingSub     = document.getElementById('loading-subtitle');
        loadingOverlay.style.display = 'flex';
        loadingTitle.innerText = '인적사항 선-마스킹 및 전송 중...';

        const CHUNK_SIZE = 4; // 안정적인 4명 분할 청크 전송
        let sentCount = 0;

        try {
            for (let i = 0; i < matchedStudents.length; i += CHUNK_SIZE) {
                const chunk = matchedStudents.slice(i, i + CHUNK_SIZE);
                loadingSub.innerText = `${sentCount} / ${matchedStudents.length}명 전송 중 ⏳`;

                const formData = new FormData();
                formData.append('assignment_id', assignId);

                const chunkPayload = [];

                for (const stu of chunk) {
                    const pageKeys = [];
                    for (let pIdx = 0; pIdx < stu.pages.length; pIdx++) {
                        const pageNum = pIdx + 1;
                        const pageObj = stu.pages[pIdx];

                        // 전송 전 복제 캔버스에서 1쪽 개인정보 마스킹 처리
                        const exportCanvas = document.createElement('canvas');
                        exportCanvas.width = pageObj.canvas.width;
                        exportCanvas.height = pageObj.canvas.height;
                        const expCtx = exportCanvas.getContext('2d');
                        expCtx.drawImage(pageObj.canvas, 0, 0);

                        if (pageNum === 1) {
                            applyClientPrivacyMask(exportCanvas, 1);
                        }

                        const pBlob = await canvasToBlob(exportCanvas, 0.88);
                        const fileKey = `file_${stu.student_id}_p${pIdx}`;
                        formData.append(fileKey, pBlob, `student_${stu.student_id}_p${pageNum}.jpg`);
                        pageKeys.push(fileKey);
                    }

                    chunkPayload.push({
                        student_id: stu.student_id,
                        file_keys: pageKeys,
                        page_files: pageKeys,
                        status: 'completed'
                    });
                }

                formData.append('students_json', JSON.stringify(chunkPayload));

                const response = await fetch('/api/grading/submit_images.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || '일부 시험지 전송에 실패했습니다.');
                }

                sentCount += chunk.length;
            }

            loadingTitle.innerText = '전송 완료! 🎉';
            loadingSub.innerText = '채점 검수 대시보드로 이동합니다.';
            alert(`총 ${matchedStudents.length}명의 시험지가 안전하게 전송되었습니다! 채점 검수 화면으로 이동합니다.`);
            window.location.href = `/views/grading/review.php?assign_id=${assignId}`;

        } catch (err) {
            console.error('전송 실패:', err);
            loadingOverlay.style.display = 'none';
            alert('전송 도중 오류가 발생했습니다: ' + err.message);
            submitBtn.disabled = false;
        }
    }
    </script>
</body>
</html>
