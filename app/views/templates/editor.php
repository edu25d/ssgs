<?php

/**

 * views/templates/editor.php

 * 고급 ROI 지정 및 배점/정답 설정 에디터 뷰 (N쪽 다면 평가지 탭 & page_uid 무결성 보존)

 */



require_once dirname(__DIR__, 2) . '/config/db.php';

require_once APP_ROOT . '/includes/common/auth.php';

require_once APP_ROOT . '/includes/common/utils.php';



$userId = requireTeacherAuth();



$id = (int)($_GET['id'] ?? 0);

if (!$id) { 

    header("Location: /views/dashboard/main.php"); 

    exit; 

}



$stmt = $pdo->prepare("SELECT * FROM templates WHERE id = :id AND user_id = :u");

$stmt->execute(['id' => $id, 'u' => $userId]);

$template = $stmt->fetch(PDO::FETCH_ASSOC);



if (!$template) { 

    die("존재하지 않거나 권한이 없는 템플릿입니다."); 

}



// SSOT Contract A: pages_data 로드 및 레거시 1쪽 폴백

$pagesData = [];

if (!empty($template['pages_data'])) {

    $pagesData = json_decode($template['pages_data'], true) ?: [];

}

if (empty($pagesData)) {

    $pagesData = [[

        'page_uid'   => 'p_1_default',

        'page_num'   => 1,

        'title'      => '1쪽',

        'image_path' => $template['image_path'],

        'width'      => 1200,

        'height'     => 1700

    ]];

}



// SSOT Contract B: roi_data 로드 및 안전 디코딩

$roiData = [];

if (!empty($template['roi_data'])) {

    $roiData = json_decode($template['roi_data'], true) ?: [];

}



// 개인정보 보호 마스킹 영역 로드 (없을 시 자가 치유 1회 탐지)

require_once APP_ROOT . '/includes/common/upstage.php';

$privacyMaskData = getOrDetectPrivacyMaskRois($pdo, $id, $template);

?>

<!DOCTYPE html>

<html lang="ko">

<head>

    <meta charset="UTF-8">

    <title><?= e($template['title']) ?> - ROI 지정 및 정답 설정</title>

    <style>

        * { box-sizing: border-box; }

        body { font-family: -apple-system, BlinkMacSystemFont, "Pretendard", "Segoe UI", Roboto, sans-serif; background: #1e1e1e; color: #fff; margin: 0; padding: 8px 14px; }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; background: #25282c; padding: 6px 14px; border-radius: 6px; border: 1px solid #333; }

        .header h2 { font-size: 15px; margin: 0; font-weight: 700; }

        .wrapper { display: flex; gap: 14px; height: calc(100vh - 58px); }

        

        .canvas-panel { flex: 1.2; display: flex; flex-direction: column; min-width: 0; }

        

        /* 상단 페이지 탭 바 */

        .page-nav-bar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            background: #25282c;

            padding: 8px 12px;

            border-radius: 8px 8px 0 0;

            border: 1px solid #444;

            border-bottom: none;

            gap: 10px;

        }

        .page-tabs {

            display: flex;

            gap: 6px;

            overflow-x: auto;

            align-items: center;

        }

        .page-tab-btn {

            background: #34373c;

            color: #bbb;

            border: 1px solid #555;

            padding: 7px 14px;

            border-radius: 6px;

            font-size: 13px;

            font-weight: 700;

            cursor: pointer;

            white-space: nowrap;

            transition: all 0.15s;

            display: flex;

            align-items: center;

            gap: 6px;

        }

        .page-tab-btn:hover { background: #42464c; color: #fff; }

        .page-tab-btn.active {

            background: #007bff;

            color: #fff;

            border-color: #007bff;

            box-shadow: 0 2px 8px rgba(0,123,255,0.3);

        }

        .tab-badge {

            background: rgba(0,0,0,0.25);

            font-size: 11px;

            padding: 1px 6px;

            border-radius: 10px;

            color: #ddd;

        }

        .page-tab-btn.active .tab-badge {

            background: rgba(255,255,255,0.25);

            color: #fff;

        }

        .btn-manage-pages {

            background: #3a3d42;

            color: #e2e8f0;

            border: 1px solid #555;

            padding: 6px 12px;

            border-radius: 6px;

            font-size: 12px;

            font-weight: 700;

            cursor: pointer;

            white-space: nowrap;

            transition: 0.15s;

        }

        .btn-manage-pages:hover { background: #4f535a; }



        .btn-ai-reextract {

            background: linear-gradient(135deg, #0d6efd 0%, #4f46e5 100%);

            color: #fff;

            border: 1px solid #6366f1;

            padding: 6px 12px;

            border-radius: 6px;

            font-size: 12px;

            font-weight: 800;

            cursor: pointer;

            white-space: nowrap;

            transition: 0.2s;

            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.3);

        }

        .btn-ai-reextract:hover { filter: brightness(1.1); }

        .btn-reset-rois {

            background: #2b2d31;

            color: #f87171;

            border: 1px solid #ef4444;

            padding: 6px 10px;

            border-radius: 6px;

            font-size: 12px;

            font-weight: 700;

            cursor: pointer;

            white-space: nowrap;

            transition: 0.15s;

        }

        .btn-reset-rois:hover { background: #dc2626; color: #fff; }



        /* 알림 토스트 */

        .toast-banner {

            position: fixed;

            top: 20px;

            left: 50%;

            transform: translateX(-50%);

            background: #1e293b;

            border: 1.5px solid #3b82f6;

            color: #f8fafc;

            padding: 12px 24px;

            border-radius: 10px;

            box-shadow: 0 8px 24px rgba(0,0,0,0.5);

            z-index: 10001;

            display: flex;

            align-items: center;

            gap: 12px;

            font-weight: 700;

            font-size: 14px;

            animation: fadeInDown 0.3s ease;

        }

        @keyframes fadeInDown {

            from { opacity: 0; transform: translate(-50%, -20px); }

            to { opacity: 1; transform: translate(-50%, 0); }

        }



        /* 캔버스 줌 & 뷰포트 맞춤 툴바 */

        .zoom-toolbar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            background: #1e2024;

            padding: 6px 12px;

            border: 1px solid #444;

            border-top: none;

            border-bottom: 1px solid #383a40;

            font-size: 12px;

        }

        .btn-zoom {

            background: #2b2e33;

            color: #cbd5e1;

            border: 1px solid #475569;

            padding: 4px 9px;

            border-radius: 4px;

            font-size: 11.5px;

            font-weight: 700;

            cursor: pointer;

            transition: all 0.15s;

        }

        .btn-zoom:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }

        .zoom-badge {

            background: #0f172a;

            color: #38bdf8;

            padding: 3px 8px;

            border-radius: 4px;

            font-size: 11px;

            font-weight: 800;

            border: 1px solid #1e293b;

        }



        .canvas-container { flex: 1; background: #111; overflow: auto; position: relative; border-radius: 0 0 8px 8px; border: 1px solid #444; border-top: none; text-align: center; }

        canvas { display: block; margin: 0 auto; transition: width 0.1s ease, height 0.1s ease; }

        

        .sidebar { flex: 0.9; background: #2d2d2d; padding: 20px; border-radius: 8px; display: flex; flex-direction: column; min-width: 580px; }

        

        .score-control-panel { background: #202225; border: 1px solid #3f4248; border-radius: 8px; padding: 12px 15px; margin-bottom: 15px; }

        .score-summary { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; font-size: 13.5px; font-weight: bold; border-bottom: 1px solid #333; padding-bottom: 10px; }

        .score-summary input { width: 55px; padding: 3px 5px; text-align: center; border-radius: 4px; border: 1px solid #555; background: #111; color: #fff; font-weight: bold; }

        .base-score { color: #28a745; }

        .base-score.exceeded { color: #dc3545; }



        .score-batch-actions { display: flex; align-items: center; gap: 12px; font-size: 13px; background: #2b2d31; padding: 8px 12px; border-radius: 6px; }

        .action-group { display: flex; align-items: center; gap: 6px; }

        .action-group input { padding: 4px 6px; border-radius: 4px; border: 1px solid #555; background: #111; color: #fff; width: 50px; text-align: center; }

        .action-divider { color: #555; font-weight: bold; }

        

        .btn-action { padding: 4px 8px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: bold; }

        .btn-action:hover { background: #0056b3; }



        .roi-list-box { flex: 1; overflow-y: auto; margin-bottom: 15px; padding-right: 5px; }

        

        .roi-item { 

            display: flex; 

            flex-direction: column;

            gap: 7px;

            background: #2b2e33; 

            padding: 9px 12px; 

            margin-bottom: 8px; 

            border-radius: 6px; 

            border: 1px solid #3c4048;

            border-left: 5px solid #0284c7; 

            cursor: pointer;

            transition: all 0.15s ease;

        }

        .roi-item:hover { background: #34383e; border-color: #4a4f58; }

        .roi-item.objective { border-left-color: #0284c7; }

        .roi-item.short_answer { border-left-color: #d97706; }

        .roi-item.matching_line { border-left-color: #0d9488; }

        .roi-item.essay { border-left-color: #7c3aed; }

        .roi-item.subjective { border-left-color: #d97706; }

        .roi-item.sub_item { border-left-color: #d97706; }



        .roi-item.selected-focus {

            background: #1e293b !important;

            border-color: #38bdf8 !important;

            border-left-color: #38bdf8 !important;

            border-left-width: 6px !important;

            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.3), 0 4px 12px rgba(0, 0, 0, 0.4) !important;

        }

        .roi-item.other-page { opacity: 0.65; background: #24262a; }

        .roi-item-main-row {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 8px;

            flex-wrap: wrap;

        }

        .roi-row-left {

            display: flex;

            align-items: center;

            gap: 8px;

        }

        .roi-row-right {

            display: flex;

            align-items: center;

            gap: 6px;

        }

        .roi-page-tag { font-size: 11px; padding: 2px 6px; border-radius: 4px; background: #18191c; color: #38bdf8; font-weight: 700; white-space: nowrap; }

        .roi-title { font-weight: bold; font-size: 14px; min-width: 25px; color: #ffc107; }

        

        .type-toggle { display: inline-flex; background: #18191c; border-radius: 4px; border: 1px solid #474b53; overflow: hidden; flex-shrink: 0; }

        .type-btn { padding: 3px 7px; font-size: 11.5px; border: none; background: transparent; color: #94a3b8; cursor: pointer; transition: all 0.15s; font-weight: 500; }

        .type-btn:hover { background: #2d3036; color: #f8fafc; }

        .type-btn.active.obj { background: #0284c7; color: #fff; font-weight: bold; }

        .type-btn.active.short { background: #d97706; color: #fff; font-weight: bold; }

        .type-btn.active.matching { background: #0d9488; color: #fff; font-weight: bold; }

        .type-btn.active.essay { background: #7c3aed; color: #fff; font-weight: bold; }

        .type-btn.active.subj { background: #d97706; color: #fff; font-weight: bold; }

        .type-btn.active.sub { background: #d97706; color: #fff; font-weight: bold; }

        .type-btn.active.comp { background: #f97316; color: #fff; font-weight: bold; }



        .btn-add-sub { font-size: 11px; padding: 3px 7px; border-radius: 4px; border: 1px solid #8b5cf6; background: rgba(139, 92, 246, 0.2); color: #c4b5fd; cursor: pointer; white-space: nowrap; font-weight: 700; transition: all 0.15s; }

        .btn-add-sub:hover { background: #8b5cf6; color: #fff; }



        .adding-sub-guide { font-size: 11.5px; color: #c4b5fd; font-weight: 700; margin-top: 3px; display: flex; align-items: center; gap: 4px; }

        .roi-item.adding-sub-mode {

            border-left: 5px solid #8b5cf6 !important;

            box-shadow: 0 0 0 2px #8b5cf6, 0 4px 12px rgba(139, 92, 246, 0.3) !important;

            animation: pulseSubBorder 1.2s infinite alternate;

        }

        @keyframes pulseSubBorder {

            0% { background: #2b2e33; }

            100% { background: #382c52; }

        }

        @keyframes pulseCompBorder {

            0% { background: #2b2e33; }

            100% { background: #4a2f1c; }

        }



        .roi-item-answer-row { display: flex; align-items: center; gap: 6px; width: 100%; }

        .input-answer { flex: 1; padding: 6px 10px; border-radius: 4px; border: 1px solid #4a4f58; background: #1c1d20; color: #fff; font-size: 12.5px; }

        .input-answer:focus { outline: none; border-color: #0284c7; background: #18191c; }

        .score-wrap { display: flex; align-items: center; gap: 4px; font-size: 12.5px; white-space: nowrap; color: #cbd5e1; }

        .input-score { width: 44px; padding: 5px 3px; text-align: center; border-radius: 4px; border: 1px solid #4a4f58; background: #1c1d20; color: #fff; font-size: 12.5px; font-weight: 700; }

        .input-score:focus { outline: none; border-color: #38bdf8; }

        

        .btn-delete { background: transparent; color: #ef4444; border: 1px solid #ef4444; padding: 3px 6px; border-radius: 4px; cursor: pointer; font-size: 12px; }

        .btn-delete:hover { background: #ef4444; color: white; }



        /* 선 잇기 전용 UI */

        .matching-box {

            width: 100%;

            margin-top: 3px;

            padding: 8px 10px;

            background: #172322;

            border-radius: 6px;

            border: 1px solid #115e59;

            box-sizing: border-box;

        }

        .matching-pairs-preview {

            display: flex;

            flex-wrap: wrap;

            gap: 5px;

            margin-top: 6px;

            align-items: center;

        }

        .pair-chip {

            display: inline-flex;

            align-items: center;

            gap: 4px;

            padding: 3px 7px;

            border-radius: 4px;

            background: rgba(13, 148, 136, 0.25);

            border: 1px solid #14b8a6;

            color: #5eead4;

            font-size: 11.5px;

            font-weight: 700;

        }

        .pair-chip-empty {

            color: #64748b;

            font-size: 11px;

            font-style: italic;

        }



        .matching-toolbar {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 6px;

            margin-top: 6px;

            padding-bottom: 5px;

            border-bottom: 1px dashed rgba(20, 184, 166, 0.35);

            flex-wrap: wrap;

        }

        .dir-btn-group {

            display: inline-flex;

            background: #111a19;

            border-radius: 4px;

            border: 1px solid #115e59;

            overflow: hidden;

        }

        .dir-btn {

            padding: 2px 7px;

            font-size: 11px;

            border: none;

            background: transparent;

            color: #94a3b8;

            cursor: pointer;

            transition: all 0.15s;

        }

        .dir-btn:hover { background: #134e4a; color: #fff; }

        .dir-btn.active {

            background: #0d9488;

            color: #fff;

            font-weight: 700;

        }

        .matching-status-bar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin: 6px 0 10px;

            padding: 6px 10px;

            background: rgba(15, 23, 42, 0.7);

            border: 1px solid rgba(94, 234, 212, 0.25);

            border-radius: 6px;

        }

        .badge-ai-status {

            font-size: 11.5px;

            font-weight: 700;

            color: #5eead4;

        }

        .badge-dot-hint {

            font-size: 11px;

            color: #94a3b8;

        }



        /* 선잇기 다이어그램 및 페어별 크롭 썸네일 미리보기 */

        .matching-diagram-thumb-wrap {

            margin-top: 6px;

            background: #111a19;

            border: 1px solid #134e4a;

            border-radius: 6px;

            padding: 6px;

            display: flex;

            flex-direction: column;

            gap: 4px;

        }

        .thumb-header {

            display: flex;

            justify-content: space-between;

            align-items: center;

            font-size: 11px;

            color: #5eead4;

            font-weight: 700;

        }

        .thumb-badge {

            font-size: 10px;

            padding: 1px 5px;

            border-radius: 3px;

            background: rgba(13, 148, 136, 0.4);

            color: #2dd4bf;

        }

        .matching-diagram-canvas {

            width: 100%;

            height: 120px;

            background: #ffffff;

            border: 1px solid #4a4f58;

            border-radius: 4px;

            object-fit: contain;

            display: block;

        }

        .crop-pairs-grid {

            display: flex;

            flex-direction: column;

            gap: 5px;

            margin-top: 6px;

        }

        .crop-pair-card {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 6px;

            padding: 4px 8px;

            background: rgba(13, 148, 136, 0.12);

            border: 1px solid rgba(20, 184, 166, 0.3);

            border-radius: 5px;

        }

        .crop-item {

            display: flex;

            align-items: center;

            gap: 6px;

        }

        .pair-mini-crop {

            width: 46px;

            height: 32px;

            border-radius: 3px;

            border: 1px solid rgba(255, 255, 255, 0.25);

            background: #fff;

            object-fit: cover;

        }

        .pair-chip-label {

            font-size: 11.5px;

            font-weight: 700;

            color: #e2e8f0;

        }

        .crop-pair-arrow {

            font-size: 14px;

            font-weight: bold;

        }



        /* 부분 점수 인정 토글 및 배점 영역 */

        .partial-score-box {

            margin-top: 6px;

            padding: 6px 10px;

            background: #1a1d22;

            border-radius: 6px;

            border: 1px solid #334155;

            box-sizing: border-box;

        }

        .partial-score-label {

            display: flex;

            align-items: center;

            gap: 5px;

            font-size: 11.5px;

            font-weight: 600;

            color: #e2e8f0;

            cursor: pointer;

            user-select: none;

        }

        .partial-score-label input[type="checkbox"] {

            accent-color: #0d9488;

            width: 14px;

            height: 14px;

            cursor: pointer;

        }

        .sub-scores-grid {

            display: grid;

            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));

            gap: 5px;

            margin-top: 6px;

            padding-top: 6px;

            border-top: 1px dashed #334155;

        }

        .sub-score-item {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 6px;

            font-size: 11.5px;

            color: #cbd5e1;

            background: #24272d;

            padding: 4px 8px;

            border-radius: 4px;

            border: 1px solid #3c4048;

        }

        .sub-score-item span.pair-label {

            font-weight: 700;

            color: #5eead4;

        }

        .sub-score-input {

            width: 38px;

            padding: 2px 3px;

            text-align: center;

            background: #15171a;

            border: 1px solid #475569;

            border-radius: 3px;

            color: #fff;

            font-size: 11.5px;

            font-weight: bold;

        }

        .sub-score-input:focus { outline: none; border-color: #0d9488; }

        .sub-score-total-bar {

            margin-top: 6px;

            font-size: 11px;

            font-weight: 700;

            color: #0d9488;

            display: flex;

            justify-content: space-between;

            align-items: center;

        }



        /* 서술형 채점기준 가이드 배너 */

        .criteria-banner {

            width: 100%;

            margin-top: 3px;

            padding: 6px 10px;

            background: rgba(124, 58, 237, 0.12);

            border: 1px solid rgba(139, 92, 246, 0.35);

            border-radius: 5px;

            font-size: 11.5px;

            line-height: 1.45;

            color: #ddd6fe;

            display: flex;

            gap: 6px;

            box-sizing: border-box;

        }

        .criteria-banner .criteria-icon {

            font-size: 13px;

            flex-shrink: 0;

        }

        .criteria-banner .criteria-text {

            flex: 1;

            word-break: break-all;

        }

        

        .btn-save { width: 100%; padding: 15px; font-size: 16px; font-weight: bold; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; }

        .btn-save:hover { background: #0056b3; }



        /* 페이지 관리 모달 */

        .modal-backdrop {

            position: fixed;

            top: 0; left: 0; width: 100vw; height: 100vh;

            background: rgba(0,0,0,0.7);

            display: flex;

            align-items: center;

            justify-content: center;

            z-index: 10000;

            display: none;

        }

        .modal-card {

            background: #2a2d32;

            width: 90%;

            max-width: 600px;

            border-radius: 10px;

            border: 1px solid #444;

            padding: 24px;

            box-shadow: 0 10px 30px rgba(0,0,0,0.5);

        }

        .modal-title { font-size: 1.2rem; font-weight: 800; margin-top: 0; margin-bottom: 16px; color: #fff; }

        .modal-page-list { max-height: 350px; overflow-y: auto; margin-bottom: 16px; }

        .modal-page-row {

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding: 10px 14px;

            background: #1e2024;

            border-radius: 6px;

            margin-bottom: 8px;

            border: 1px solid #3f4248;

        }

        .modal-page-info { display: flex; align-items: center; gap: 10px; }

        .modal-page-thumb { width: 45px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #555; }

        .modal-page-actions { display: flex; gap: 6px; }

        .btn-modal-action {

            background: #34373c;

            color: #ddd;

            border: 1px solid #555;

            padding: 5px 9px;

            border-radius: 4px;

            cursor: pointer;

            font-size: 12px;

        }

        .btn-modal-action:hover { background: #4a4e54; color: #fff; }

        .btn-modal-action.btn-del:hover { background: #dc3545; border-color: #dc3545; color: #fff; }

        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }

        .btn-modal-close { background: #007bff; color: white; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; }

    </style>

</head>

<body>



    <div class="header">

        <h2>🎯 ROI 지정 및 정답 설정: <?= e($template['title']) ?></h2>

        <a href="/views/dashboard/main.php" style="color: #ccc; text-decoration: none;">← 메인으로 돌아가기</a>

    </div>







    <div class="wrapper">

        <div class="canvas-panel">

            <div class="page-nav-bar">

                <div class="page-tabs" id="pageTabs"></div>

                <div class="page-actions-bar" style="display: flex; gap: 8px; align-items: center;">

                    <button type="button" class="btn-import-answer" onclick="openAnswerKeyModal()" style="background:#0284c7;color:#fff;border:none;padding:7px 12px;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;" title="이원목적분류표 또는 정답표를 불러와 정답·배점을 자동으로 채웁니다.">📋 정답표 자동입력</button>

                    <button type="button" class="btn-ai-reextract" onclick="reExtractAi()">✨ AI 자동 완성 다시 받기</button>

                    <button type="button" class="btn-reset-rois" onclick="resetAllRois()">🗑️ 전체 초기화</button>

                    <button type="button" class="btn-manage-pages" onclick="openPageManager()">⚙️ 페이지 관리</button>

                    <button type="button" class="btn-mask-tool" id="btnMaskTool" onclick="handleMaskToolClick()" style="background:#334155;color:#e2e8f0;border:1px solid #475569;padding:7px 12px;border-radius:6px;font-size:12.5px;font-weight:700;cursor:pointer;" title="1쪽 인적사항 마스킹 영역 확인 및 조절">🛡️ 개인정보 마스킹</button>

                </div>

            </div>

            <div class="zoom-toolbar">

                <div style="display: flex; gap: 6px; align-items: center;">

                    <span style="color: #94a3b8; font-weight: 700; margin-right: 4px;">🔍 화면 맞춤:</span>

                    <button type="button" class="btn-zoom" onclick="fitWidth()" title="캔버스 너비를 화면에 100% 맞춤">↔ 가로폭 맞춤</button>

                    <button type="button" class="btn-zoom" onclick="fitPage()" title="시험지 1쪽 전체가 화면에 들어오도록 맞춤">↕ 세로 맞춤</button>

                    <button type="button" class="btn-zoom" onclick="zoomReset()">100%</button>

                    <button type="button" class="btn-zoom" onclick="zoomIn()">＋ 확대</button>

                    <button type="button" class="btn-zoom" onclick="zoomOut()">－ 축소</button>

                    <span class="zoom-badge" id="zoomBadge">100%</span>

                </div>

                <div style="font-size: 11.5px; color: #94a3b8;">

                    <span>💡 번호 지정 중: <b style="color:#f59e0b">[Backspace]/[ESC] 취소</b> | <b style="color:#10b981">[Ctrl+Z] Undo</b> | 답안란 클릭: <b style="color:#38bdf8">객/주 전환</b></span>

                </div>

            </div>

            <div class="canvas-container">

                <canvas id="editorCanvas"></canvas>

            </div>

        </div>



        <div class="sidebar">

            <div class="score-control-panel">

                <div class="score-summary">

                    <span>총 문항: <span id="summaryCount">0</span>개</span>

                    <span>설정 배점: <span id="summaryTotalScore">0</span>점</span>

                    <span>기본점수: <span id="summaryBaseScore" class="base-score">100점</span></span>

                    <div class="action-group">

                        <span>만점:</span>

                        <input type="number" id="targetTotalScore" value="100" min="0"> 점

                        <button type="button" class="btn-action" onclick="applyTargetScore()">적용</button>

                    </div>

                </div>

                <div class="score-batch-actions">

                    <div class="action-group">

                        <span>일괄:</span>

                        <input type="number" id="batchAllVal" placeholder="점수" min="0"> 점

                        <button type="button" class="btn-action" onclick="applyBatchAll()">적용</button>

                    </div>

                    <span class="action-divider">|</span>

                    <div class="action-group">

                        <span>객관식:</span>

                        <input type="number" id="batchObjVal" placeholder="점수" min="0"> 점

                        <span>/ 주관식:</span>

                        <input type="number" id="batchSubjVal" placeholder="점수" min="0"> 점

                        <button type="button" class="btn-action" onclick="applyBatchType()">적용</button>

                    </div>

                </div>

            </div>



            <div class="roi-list-box" id="roiList"></div>

            <button type="button" class="btn-save" onclick="saveTemplate()">💾 템플릿 설정 저장하기</button>

        </div>

    </div>



    <!-- 페이지 관리 모달 -->

    <div class="modal-backdrop" id="pageModal">

        <div class="modal-card">

            <h3 class="modal-title">⚙️ 페이지 순서 변경 및 관리</h3>

            <div class="modal-page-list" id="modalPageList"></div>

            <div class="modal-footer">

                <button type="button" class="btn-modal-close" onclick="closePageManager()">확인 및 닫기</button>

            </div>

        </div>

    </div>



    <!-- [Phase 2] 정답표 / 이원목적분류표 자동입력 모달 -->

    <div class="modal-backdrop" id="answerKeyModal" style="display:none; align-items:center; justify-content:center;">

        <div class="modal-card" style="max-width: 560px; width: 90%;">

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">

                <h3 class="modal-title" style="margin: 0; font-size: 16px;">📋 정답표 / 이원목적분류표 자동입력</h3>

                <button type="button" onclick="closeAnswerKeyModal()" style="background:none; border:none; font-size:18px; cursor:pointer; color:#64748b;">✕</button>

            </div>

            

            <div style="display: flex; gap: 8px; margin-bottom: 14px; border-bottom: 1.5px solid #e2e8f0; padding-bottom: 8px;">

                <button type="button" id="tabBtnFile" onclick="switchAnsKeyTab('file')" style="background:#0284c7; color:#fff; border:none; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;">📁 파일 업로드 (PDF/이미지)</button>

                <button type="button" id="tabBtnText" onclick="switchAnsKeyTab('text')" style="background:#f1f5f9; color:#475569; border:none; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;">📝 텍스트/표 붙여넣기</button>

            </div>



            <!-- 탭 1: 파일 업로드 -->

            <div id="ansKeyFileTab">

                <input type="file" id="ansKeyModalFileInput" accept="application/pdf,image/jpeg,image/png,image/webp" style="display: none;">

                <div id="ansKeyModalDropzone" style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 24px 20px; text-align: center; cursor: pointer; background: #f8fafc; transition: all 0.2s;">

                    <div style="font-size: 32px; margin-bottom: 6px;">📑</div>

                    <div id="ansKeyModalFileText" style="font-weight: 700; color: #1e293b; font-size: 14px;">이원목적분류표 PDF 또는 사진 이미지 선택</div>

                    <div id="ansKeyModalFileSubtext" style="font-size: 12px; color: #64748b; margin-top: 4px;">클릭하거나 파일을 여기로 끌어다 놓으세요.</div>

                </div>

            </div>



            <!-- 탭 2: 텍스트 / 표 붙여넣기 -->

            <div id="ansKeyTextTab" style="display: none;">

                <textarea id="ansKeyModalTextarea" rows="7" placeholder="한글(HWP)이나 엑셀에서 정답표를 복사하여 여기에 붙여넣으세요.&#10;예시:&#10;1  ②  5&#10;2  ⑤  5&#10;7  아래 참조  5" style="width: 100%; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 10px; font-size: 13px; font-family: monospace; resize: vertical; box-sizing: border-box;"></textarea>

            </div>



            <div style="margin-top: 14px; font-size: 11.5px; color: #64748b; line-height: 1.5; background: #eff6ff; padding: 8px 12px; border-radius: 6px; border: 1px solid #bfdbfe;">

                💡 정답표의 <strong>문항 번호, 정답, 배점, 채점 기준(루브릭)</strong>이 현재 시험지의 각 문항과 소문항에 100% 자동 연결됩니다.

            </div>



            <div class="modal-footer" style="margin-top: 16px; display: flex; justify-content: flex-end; gap: 8px;">

                <button type="button" onclick="closeAnswerKeyModal()" style="background:#e2e8f0; color:#334155; border:none; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;">취소</button>

                <button type="button" id="btnSubmitAnsKey" onclick="submitAnswerKeyModal()" style="background:#0284c7; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;">🚀 정답표 분석 및 적용</button>

            </div>

        </div>

    </div>



    <script>

        const templateId = <?= $id ?>;

        const canvas = document.getElementById('editorCanvas');

        const ctx = canvas.getContext('2d');

        const roiListContainer = document.getElementById('roiList');

        const pageTabsContainer = document.getElementById('pageTabs');



        // SSOT Contract A: 페이지 목록

        let pages = <?= json_encode($pagesData, JSON_UNESCAPED_UNICODE) ?>;

        let activePageIndex = 0;

        let pageImages = {}; // 캐시: { [pageIndex]: HTMLImageElement }



        // SSOT Contract B: 문항 목록 (page_uid 영구 바인딩)

        let rawRois = <?= json_encode($roiData, JSON_UNESCAPED_UNICODE) ?>;

        let rois = (Array.isArray(rawRois) ? rawRois : []).map((r, i) => {

            const pageNum = parseInt(r.page) || 1;

            const matchedPage = pages.find(p => p.page_uid === r.page_uid) || pages[pageNum - 1] || pages[0];

            const rawId = r.id || (i + 1);

            const subId = r.sub_id || null;

            const displayNum = r.display_num || (subId ? `${rawId}-${subId}` : `${rawId}`);

            return {

                id: rawId,

                sub_id: subId,

                display_num: displayNum,

                page_uid: r.page_uid || (matchedPage ? matchedPage.page_uid : 'p_1_default'),

                page: matchedPage ? matchedPage.page_num : pageNum,

                num_roi: r.num_roi || { x: r.x || 50, y: r.y || 50, w: 50, h: 30 },

                ans_roi: r.ans_roi || { x: (r.x ? r.x + 60 : 110), y: r.y || 50, w: r.w || 60, h: r.h || 35 },

                ans_rois: Array.isArray(r.ans_rois) ? r.ans_rois : null,

                type: r.type || 'objective',

                sub_type: r.sub_type || r.type || 'objective',

                answer: r.answer || '',

                score: typeof r.score === 'number' ? r.score : 0,

                has_partial_score: Boolean(r.has_partial_score),

                sub_scores: (typeof r.sub_scores === 'object' && r.sub_scores !== null) ? r.sub_scores : {},

                criteria: r.criteria || '',

                matching_direction: r.matching_direction || 'auto',

                matching_info: r.matching_info || null

            };

        });



        let targetScore = 100;

        let currentStage = 'NUM';

        let tempNumRoi = null;



        let dragMode = null;

        let activeTarget = null;

        let dragStartPos = { x: 0, y: 0 };

        let initialRect = null;

        let selectedRoiIndex = null;

        let addAnsTargetIndex = null; // 추가 소문항/다답형 답안 드로잉 대기 대상 인덱스



        // 개인정보 보호 마스킹 영역 상태 관리 (단 1개의 통합 직사각형)

        let privacyMaskRois = <?= json_encode($privacyMaskData, JSON_UNESCAPED_UNICODE) ?> || [];

        let selectedMaskIndex = null;



        const HANDLE_SIZE = 8;



        // 미분석 선잇기 문항 자동 감지 및 백그라운드 Gemini 분석 트리거

        function checkAutoAnalyzeMatchingLines() {

            rois.forEach((r, idx) => {

                if (getDisplayType(r) === 'matching_line' && (!r.matching_info || !r.matching_info.source_dots || r.matching_info.source_dots.length === 0)) {

                    setTimeout(() => triggerGeminiMatchingAnalysis(idx), 200 * (idx + 1));

                }

            });

        }



        // 실행 취소(Undo) 히스토리 스택

        const undoStack = [];

        const MAX_UNDO = 30;

        let roiSnapshotBeforeDrag = null;



        function pushUndoState(snapshot = null) {

            const stateToPush = snapshot ? snapshot : JSON.parse(JSON.stringify(rois));

            undoStack.push(stateToPush);

            if (undoStack.length > MAX_UNDO) {

                undoStack.shift();

            }

        }



        function undo() {

            if (undoStack.length === 0) {

                showToast("더 이상 실행 취소할 작업이 없습니다.");

                return;

            }

            rois = undoStack.pop();

            selectedRoiIndex = null;

            tempNumRoi = null;

            currentStage = 'NUM';

            draw();

            renderSidebar();

            renderPageTabs();

            showToast("실행 취소(Undo)되었습니다.");

        }



        // 현재 활성 페이지 기준으로 신규 문항 생성 시 부여될 스마트 번호 계산

        function getNextQuestionNumberForActivePage() {

            const currentPg = pages[activePageIndex];

            if (!currentPg) return rois.length + 1;

            let insertIdx = -1;

            for (let i = rois.length - 1; i >= 0; i--) {

                if (rois[i].page_uid === currentPg.page_uid || rois[i].page === currentPg.page_num) {

                    insertIdx = i + 1;

                    break;

                }

            }

            if (insertIdx === -1) {

                for (let i = 0; i < rois.length; i++) {

                    const rPageIdx = pages.findIndex(p => p.page_uid === rois[i].page_uid || p.page_num === rois[i].page);

                    if (rPageIdx > activePageIndex) {

                        insertIdx = i;

                        break;

                    }

                }

                if (insertIdx === -1) insertIdx = rois.length;

            }

            return insertIdx + 1;

        }



        // 키보드 단축키 (문항 생성 취소, 문항 삭제, Undo 등)

        window.addEventListener('keydown', (e) => {

            if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;



            // 1. Ctrl + Z (또는 Cmd + Z) 실행 취소

            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {

                e.preventDefault();

                if (tempNumRoi !== null || currentStage === 'ANS') {

                    // 번호 상자만 그린 상태에서 Ctrl+Z: 번호 지정 취소

                    tempNumRoi = null;

                    currentStage = 'NUM';

                    draw();

                    showToast("문항 번호 지정이 취소되었습니다.");

                } else {

                    // 일반 Undo 실행

                    undo();

                }

                return;

            }



            // 2. ESC (Escape): 번호 상자 취소, 추가 답안 모드 종료 또는 선택 해제

            if (e.key === 'Escape') {

                e.preventDefault();

                if (addAnsTargetIndex !== null) {

                    addAnsTargetIndex = null;

                    currentStage = 'NUM';

                    reindexRois();

                    draw();

                    renderSidebar();

                } else if (tempNumRoi !== null || currentStage === 'ANS') {

                    tempNumRoi = null;

                    currentStage = 'NUM';

                    draw();

                    showToast("문항 번호 지정이 취소되었습니다.");

                } else if (selectedMaskIndex !== null && privacyMaskRois[selectedMaskIndex]) {

                    e.preventDefault();

                    privacyMaskRois.splice(selectedMaskIndex, 1);

                    selectedMaskIndex = null;

                    draw();

                    showToast('🛡️ 개인정보 마스킹 영역이 삭제되었습니다.');

                    return;

                }

                if (selectedRoiIndex !== null) {

                    selectedRoiIndex = null;

                    draw();

                    renderSidebar();

                }

                return;

            }



            // 3. Delete / Backspace: 번호 상자 취소 또는 선택 문항 삭제

            if (e.key === 'Delete' || e.key === 'Backspace') {

                if (tempNumRoi !== null || currentStage === 'ANS') {

                    e.preventDefault();

                    tempNumRoi = null;

                    currentStage = 'NUM';

                    draw();

                    showToast("문항 번호 지정이 취소되었습니다.");

                    return;

                }

                if (selectedMaskIndex !== null && privacyMaskRois[selectedMaskIndex]) {

                    e.preventDefault();

                    privacyMaskRois.splice(selectedMaskIndex, 1);

                    selectedMaskIndex = null;

                    draw();

                    showToast('🛡️ 개인정보 마스킹 영역이 삭제되었습니다.');

                    return;

                }

                if (selectedRoiIndex !== null) {

                    e.preventDefault();

                    deleteRoi(selectedRoiIndex);

                    selectedRoiIndex = null;

                    showToast("선택된 문항 영역이 삭제되었습니다.");

                    return;

                }

            }

        });



        // 페이지 네비게이션 렌더링 및 첫 페이지 로드

        renderPageTabs();

        loadPage(0);



        function renderPageTabs() {

            pageTabsContainer.innerHTML = "";

            pages.forEach((page, idx) => {

                const count = rois.filter(r => r.page_uid === page.page_uid).length;

                const btn = document.createElement('button');

                btn.type = 'button';

                btn.className = `page-tab-btn ${idx === activePageIndex ? 'active' : ''}`;

                btn.innerHTML = `📄 ${page.title || (page.page_num + '쪽')} <span class="tab-badge">${count}문항</span>`;

                btn.onclick = () => switchPage(idx);

                pageTabsContainer.appendChild(btn);

            });

        }



        function switchPage(idx) {

            if (idx < 0 || idx >= pages.length) return;

            activePageIndex = idx;

            currentStage = 'NUM';

            tempNumRoi = null;

            selectedRoiIndex = null;

            renderPageTabs();

            loadPage(idx);

        }



        function loadPage(idx) {

            const page = pages[idx];

            if (!page) return;



            if (pageImages[idx] && pageImages[idx].complete) {

                applyCanvasImage(pageImages[idx]);

            } else {

                const img = new Image();

                img.src = page.image_path;

                img.onload = () => {

                    pageImages[idx] = img;

                    applyCanvasImage(img);

                    checkAutoAnalyzeMatchingLines();

                };

                img.onerror = () => {

                    console.error("이미지 로드 실패:", page.image_path);

                    showToast(`⚠️ ${page.page_num || (idx + 1)}쪽 이미지를 불러오지 못했습니다. 경로를 확인해주세요.`, 5000);

                };

            }

        }



        let currentZoom = 1.0;



        function setZoom(scale) {

            currentZoom = Math.min(Math.max(scale, 0.3), 3.0);

            applyZoom();

        }



        function applyZoom() {

            if (!canvas.width) return;

            const displayW = Math.round(canvas.width * currentZoom);

            const displayH = Math.round(canvas.height * currentZoom);

            canvas.style.width = displayW + 'px';

            canvas.style.height = displayH + 'px';

            const badge = document.getElementById('zoomBadge');

            if (badge) badge.innerText = Math.round(currentZoom * 100) + '%';

        }



        function zoomIn() {

            setZoom(currentZoom + 0.1);

        }



        function zoomOut() {

            setZoom(currentZoom - 0.1);

        }



        function zoomReset() {

            setZoom(1.0);

        }



        function fitWidth() {

            const container = document.querySelector('.canvas-container');

            if (!container || !canvas.width) return;

            const availableW = container.clientWidth - 16;

            if (availableW > 0) {

                const scale = availableW / canvas.width;

                setZoom(scale);

            }

        }



        function fitPage() {

            const container = document.querySelector('.canvas-container');

            if (!container || !canvas.width || !canvas.height) return;

            const availableW = container.clientWidth - 20;

            const availableH = container.clientHeight - 20;

            if (availableW > 0 && availableH > 0) {

                const scale = Math.min(availableW / canvas.width, availableH / canvas.height);

                setZoom(scale);

            }

        }



        function applyCanvasImage(img) {

            canvas.width = img.naturalWidth || 1200;

            canvas.height = img.naturalHeight || 1700;

            fitWidth();

            draw();

            renderSidebar();

        }



        canvas.addEventListener('mousedown', onMouseDown);

        canvas.addEventListener('mousemove', onMouseMove);

        canvas.addEventListener('mouseup', onMouseUp);

        canvas.addEventListener('mouseleave', () => { 

            if (!dragMode) {

                canvas.style.cursor = 'default';

                draw(); 

            }

        });

        canvas.addEventListener('wheel', onWheel, { passive: false });



        function onMouseDown(e) {

            const pos = getCanvasPos(e);

            dragStartPos = { x: pos.x, y: pos.y };



            const target = findRoiAtPos(pos.x, pos.y);



            if (target) {

                roiSnapshotBeforeDrag = JSON.parse(JSON.stringify(rois));

                activeTarget = target;



                if (target.isAnchorDot) {

                    selectedRoiIndex = target.itemIndex;

                    dragMode = 'ANCHOR_DOT';

                    draw();

                    renderSidebar();

                    return;

                }



                const rect = getTargetRect(target);

                initialRect = { ...rect };



                if (target.handle) {

                    selectedRoiIndex = target.itemIndex;

                    dragMode = 'RESIZE';

                    draw();

                    renderSidebar();

                } else {

                    dragMode = 'MOVE';

                }

                return;

            }



            selectedRoiIndex = null;

            renderSidebar();

            dragMode = 'DRAW';

        }



        function onMouseMove(e) {

            const pos = getCanvasPos(e);



            if (dragMode === 'ANCHOR_DOT' && activeTarget) {

                canvas.style.cursor = 'grabbing';

                const item = rois[activeTarget.itemIndex];

                if (item && item.ans_roi && activeTarget.dot) {

                    const ar = item.ans_roi;

                    const relX = Math.max(0, Math.min(ar.w, pos.x - ar.x));

                    const relY = Math.max(0, Math.min(ar.h, pos.y - ar.y));

                    activeTarget.dot.roi_norm_x = (ar.w > 0) ? (relX / ar.w) : 0;

                    activeTarget.dot.roi_norm_y = (ar.h > 0) ? (relY / ar.h) : 0;

                    activeTarget.dot.abs_x = ar.x + relX;

                    activeTarget.dot.abs_y = ar.y + relY;

                    draw();

                    renderMatchingCropPreviews();

                }

                return;

            }



            if (dragMode === 'RESIZE' && activeTarget) {

                const rect = getTargetRect(activeTarget);

                const dx = pos.x - dragStartPos.x;

                const dy = pos.y - dragStartPos.y;

                const minSize = 15;



                switch (activeTarget.handle) {

                    case 'br':

                        rect.w = Math.max(minSize, initialRect.w + dx);

                        rect.h = Math.max(minSize, initialRect.h + dy);

                        break;

                    case 'bl':

                        const newW_bl = Math.max(minSize, initialRect.w - dx);

                        rect.x = initialRect.x + (initialRect.w - newW_bl);

                        rect.w = newW_bl;

                        rect.h = Math.max(minSize, initialRect.h + dy);

                        break;

                    case 'tr':

                        rect.w = Math.max(minSize, initialRect.w + dx);

                        const newH_tr = Math.max(minSize, initialRect.h - dy);

                        rect.y = initialRect.y + (initialRect.h - newH_tr);

                        rect.h = newH_tr;

                        break;

                    case 'tl':

                        const newW_tl = Math.max(minSize, initialRect.w - dx);

                        rect.x = initialRect.x + (initialRect.w - newW_tl);

                        rect.w = newW_tl;

                        const newH_tl = Math.max(minSize, initialRect.h - dy);

                        rect.y = initialRect.y + (initialRect.h - newH_tl);

                        rect.h = newH_tl;

                        break;

                }

                draw();

                return;

            }



            if (dragMode === 'MOVE' && activeTarget) {

                const rect = getTargetRect(activeTarget);

                rect.x = initialRect.x + (pos.x - dragStartPos.x);

                rect.y = initialRect.y + (pos.y - dragStartPos.y);

                draw();

                return;

            }



            const isNumStage = (currentStage === 'NUM');

            const defaultW = isNumStage ? 50 : 60;

            const defaultH = isNumStage ? 30 : 35;



            if (dragMode === 'DRAW') {

                draw();

                const rawW = pos.x - dragStartPos.x;

                const rawH = pos.y - dragStartPos.y;

                const w = Math.abs(rawW);

                const h = Math.abs(rawH);



                ctx.lineWidth = 2;

                ctx.strokeStyle = isNumStage ? '#ffc107' : '#007bff';



                const rectX = (w < 10 && h < 10) ? (pos.x - defaultW / 2) : Math.min(dragStartPos.x, pos.x);

                const rectY = (w < 10 && h < 10) ? (pos.y - defaultH / 2) : Math.min(dragStartPos.y, pos.y);

                const rectW = (w < 10 && h < 10) ? defaultW : w;

                const rectH = (w < 10 && h < 10) ? defaultH : h;



                ctx.strokeRect(rectX, rectY, rectW, rectH);



                // 드래그 중인 상자 상단에 프리뷰 번호 렌더링

                const nextQNum = getNextQuestionNumberForActivePage();

                ctx.fillStyle = isNumStage ? '#ffc107' : '#007bff';

                ctx.font = isNumStage ? 'bold 16px sans-serif' : 'bold 14px sans-serif';

                ctx.fillText(isNumStage ? `문항 ${nextQNum}` : `문항 ${nextQNum} 답안`, rectX, rectY - 5);

                return;

            }



            if (addAnsTargetIndex !== null && rois[addAnsTargetIndex]) {

                const targetItem = rois[addAnsTargetIndex];

                canvas.style.cursor = 'crosshair';

                draw();



                const themeColor = '#8b5cf6';

                const fillColor = 'rgba(139, 92, 246, 0.2)';



                const boxW = 60, boxH = 35;

                const boxX = pos.x - boxW / 2;

                const boxY = pos.y - boxH / 2;



                ctx.save();

                ctx.lineWidth = 2;

                ctx.strokeStyle = themeColor;

                ctx.setLineDash([4, 4]);

                ctx.fillStyle = fillColor;

                ctx.fillRect(boxX, boxY, boxW, boxH);

                ctx.strokeRect(boxX, boxY, boxW, boxH);

                ctx.setLineDash([]);



                let label = '';

                if (isComp) {

                    const ansCount = Array.isArray(targetItem.ans_rois) ? targetItem.ans_rois.length : 1;

                    label = `[Q${targetItem.id} 답-${ansCount + 1} 드래그]`;

                } else {

                    const pk = targetItem.num_roi ? `${targetItem.page_uid}_${Math.round(targetItem.num_roi.x)}_${Math.round(targetItem.num_roi.y)}` : null;

                    const subCount = pk ? rois.filter(rx => rx.num_roi && `${rx.page_uid}_${Math.round(rx.num_roi.x)}_${Math.round(rx.num_roi.y)}` === pk).length : 1;

                    label = `[Q${targetItem.id}-${subCount + 1} 소문항 드래그]`;

                }



                ctx.font = 'bold 12px sans-serif';

                const txtW = ctx.measureText(label).width;

                const pillX = boxX;

                const pillY = boxY - 22;

                const pillW = txtW + 12;

                const pillH = 18;



                ctx.fillStyle = 'rgba(20, 20, 24, 0.88)';

                if (ctx.roundRect) {

                    ctx.beginPath();

                    ctx.roundRect(pillX, pillY, pillW, pillH, 4);

                    ctx.fill();

                    ctx.strokeStyle = themeColor;

                    ctx.lineWidth = 1;

                    ctx.stroke();

                } else {

                    ctx.fillRect(pillX, pillY, pillW, pillH);

                    ctx.strokeStyle = themeColor;

                    ctx.strokeRect(pillX, pillY, pillW, pillH);

                }



                ctx.fillStyle = themeColor;

                ctx.fillText(label, pillX + 6, pillY + 13);

                ctx.restore();

                return;

            }



            const hoveredTarget = findRoiAtPos(pos.x, pos.y);

            if (hoveredTarget) {

                if (hoveredTarget.isAnchorDot) {

                    canvas.style.cursor = 'grab';

                } else if (hoveredTarget.handle) {

                    canvas.style.cursor = (hoveredTarget.handle === 'tl' || hoveredTarget.handle === 'br') ? 'nwse-resize' : 'nesw-resize';

                } else {

                    canvas.style.cursor = 'pointer';

                }

                draw();

                if (hoveredTarget.isAnchorDot && hoveredTarget.dot) {
                    const item = rois[hoveredTarget.itemIndex];
                    if (item && item.ans_roi) {
                        const pt = getMatchingDotCanvasPos(item, hoveredTarget.dot);
                        ctx.save();
                        ctx.beginPath();
                        ctx.arc(pt.x, pt.y, 11, 0, Math.PI * 2);
                        ctx.strokeStyle = "#38bdf8";
                        ctx.lineWidth = 2.5;
                        ctx.shadowColor = "#38bdf8";
                        ctx.shadowBlur = 8;
                        ctx.stroke();
                        ctx.restore();
                    }
                }

            } else {

                canvas.style.cursor = 'crosshair';

                draw();

                ctx.lineWidth = 2;

                ctx.strokeStyle = isNumStage ? '#ffc107' : '#007bff';

                ctx.setLineDash([4, 4]);

                ctx.strokeRect(pos.x - defaultW / 2, pos.y - defaultH / 2, defaultW, defaultH);

                ctx.setLineDash([]);

            }

        }



        function onMouseUp(e) {

            const pos = getCanvasPos(e);

            const distMoved = Math.hypot(pos.x - dragStartPos.x, pos.y - dragStartPos.y);



            if (dragMode === 'ANCHOR_DOT' && activeTarget) {

                const item = rois[activeTarget.itemIndex];

                if (item && item.ans_roi && activeTarget.dot) {

                    snapDotToNearestDarkBullet(item, activeTarget.dot);

                }

                if (roiSnapshotBeforeDrag) {

                    pushUndoState(roiSnapshotBeforeDrag);

                    roiSnapshotBeforeDrag = null;

                }

                dragMode = null;

                activeTarget = null;

                draw();

                renderSidebar();

                renderMatchingCropPreviews();

                return;

            }



            if (dragMode === 'MOVE' && activeTarget) {

                if (distMoved < 5) {

                    // Click without drag: 단순 선택/포커싱만 수행 (정답 영역 클릭 시 문제 유형 변경 방지)

                    if (activeTarget.roiType === 'num_roi') {

                        selectedRoiIndex = (selectedRoiIndex === activeTarget.itemIndex) ? null : activeTarget.itemIndex;

                    } else if (activeTarget.roiType === 'ans_roi' || activeTarget.roiType === 'ans_rois') {

                        selectedRoiIndex = activeTarget.itemIndex;

                    }

                    renderSidebar();

                    draw();

                } else {

                    // 드래그 이동 완료

                    if (roiSnapshotBeforeDrag) {

                        pushUndoState(roiSnapshotBeforeDrag);

                        roiSnapshotBeforeDrag = null;

                    }

                    selectedRoiIndex = activeTarget.itemIndex;

                }

            }



            if (dragMode === 'RESIZE' && activeTarget) {

                if (roiSnapshotBeforeDrag) {

                    pushUndoState(roiSnapshotBeforeDrag);

                    roiSnapshotBeforeDrag = null;

                }

            }



            if (dragMode === 'DRAW') {

                const w = Math.abs(pos.x - dragStartPos.x);

                const h = Math.abs(pos.y - dragStartPos.y);



                let x, y, finalW, finalH;

                const isNumStage = (currentStage === 'NUM' && addAnsTargetIndex === null);

                const defaultW = isNumStage ? 50 : 60;

                const defaultH = isNumStage ? 30 : 35;



                if (w < 10 && h < 10) {

                    finalW = defaultW;

                    finalH = defaultH;

                    x = pos.x - defaultW / 2;

                    y = pos.y - defaultH / 2;

                } else {

                    finalW = w;

                    finalH = h;

                    x = Math.min(dragStartPos.x, pos.x);

                    y = Math.min(dragStartPos.y, pos.y);

                }



                if (addAnsTargetIndex !== null && rois[addAnsTargetIndex]) {

                    // 기존 문항에 추가 답안 영역 또는 소문항 등록

                    pushUndoState();

                    const targetItem = rois[addAnsTargetIndex];

                    const targetId = targetItem.id;

                    const newRect = { x, y, w: finalW, h: finalH };



                    // 소문항(sub_item) 추가

                    targetItem.type = 'sub_item';

                    targetItem.sub_type = targetItem.sub_type || 'objective';



                        let insertPos = addAnsTargetIndex + 1;

                        while (insertPos < rois.length && rois[insertPos].type === 'sub_item' && rois[insertPos].id === targetId) {

                            insertPos++;

                        }

                        const newSub = {

                            id: targetId,

                            page_uid: targetItem.page_uid,

                            page: targetItem.page,

                            num_roi: targetItem.num_roi,

                            ans_roi: newRect,

                            ans_rois: null,

                            type: 'sub_item',

                            sub_type: targetItem.sub_type || 'objective',

                            answer: '',

                            score: 0

                        };

                        rois.splice(insertPos, 0, newSub);

                    }

                    // ponytail: 연속 추가 모드 유지 (휠 또는 ESC로 종료 시까지 대기)

                    reindexRois();

                    const refreshedIdx = rois.findIndex(rx => rx.page_uid === targetItem.page_uid && rx.id === targetId);

                    if (refreshedIdx !== -1) {

                        addAnsTargetIndex = refreshedIdx;

                        selectedRoiIndex = refreshedIdx;

                    }

                } else if (currentStage === 'NUM') {

                    tempNumRoi = { x, y, w: finalW, h: finalH };

                    currentStage = 'ANS';

                } else {

                    pushUndoState(); // 신규 문항 추가 직전 상태 저장

                    const currentPg = pages[activePageIndex];

                    // 스마트 번호 매김: 현재 페이지의 마지막 문항 바로 뒤에 삽입

                    let insertIdx = -1;

                    for (let i = rois.length - 1; i >= 0; i--) {

                        if (rois[i].page_uid === currentPg.page_uid || rois[i].page === currentPg.page_num) {

                            insertIdx = i + 1;

                            break;

                        }

                    }

                    if (insertIdx === -1) {

                        for (let i = 0; i < rois.length; i++) {

                            const rPageIdx = pages.findIndex(p => p.page_uid === rois[i].page_uid || p.page_num === rois[i].page);

                            if (rPageIdx > activePageIndex) {

                                insertIdx = i;

                                break;

                            }

                        }

                        if (insertIdx === -1) insertIdx = rois.length;

                    }



                    const newRoi = {

                        id: insertIdx + 1,

                        page_uid: currentPg.page_uid,

                        page: currentPg.page_num,

                        num_roi: tempNumRoi,

                        ans_roi: { x, y, w: finalW, h: finalH },

                        type: 'objective',

                        answer: '',

                        score: 0

                    };

                    rois.splice(insertIdx, 0, newRoi);

                    reindexRois();

                    selectedRoiIndex = insertIdx;

                    tempNumRoi = null;

                    currentStage = 'NUM';

                }

            }



            dragMode = null;

            activeTarget = null;

            initialRect = null;

            draw();

            renderSidebar();

            renderPageTabs();

        }



        function onWheel(e) {

            // ponytail: 추가 소문항/답안 모드 중 마우스 휠을 굴리면 모드 종료 -> 새 문항 번호(NUM) 모드로 복귀

            if (addAnsTargetIndex !== null) {

                e.preventDefault();

                addAnsTargetIndex = null;

                currentStage = 'NUM';

                reindexRois();

                draw();

                renderSidebar();

                return;

            }



            const pos = getCanvasPos(e);

            const target = findRoiAtPos(pos.x, pos.y);

            if (target && (target.roiType === 'ans_roi' || target.roiType === 'ans_rois')) {

                e.preventDefault();

                cycleRoiType(target.itemIndex);

            }

        }



        // Phase 3 헬퍼 함수 정의

        function escapeHtml(str) {

            if (!str) return '';

            return String(str)

                .replace(/&/g, '&amp;')

                .replace(/</g, '&lt;')

                .replace(/>/g, '&gt;')

                .replace(/"/g, '&quot;')

                .replace(/'/g, '&#039;');

        }



        // 선 잇기 정답 문자열 실시간 파싱 ((1)-㉢, (2)-㉡, (3)-㉠ 또는 1-ㄷ, 2-ㄴ, 3-ㄱ 또는 1 ➔ ㉢)

        function parseMatchingPairs(ansText) {

            if (!ansText || typeof ansText !== 'string') return [];

            const text = ansText.trim();

            let pairs = [];

            const tokens = text.split(/[,;\n]+/).map(s => s.trim()).filter(Boolean);

            if (tokens.length > 1) {

                tokens.forEach(tok => {

                    const m = tok.match(/^[\(（]?(\d+)[\)）]?[\s*\-➔→=~:>]*([㉠-㉭ㄱ-ㅎ가-힣\w]+)$/u);

                    if (m) pairs.push({ from: '(' + m[1] + ')', to: m[2], raw: tok });

                });

            }

            if (pairs.length < 2) {

                pairs = [];

                const regex = /[\(（]?(\d+)[\)）]?[\s*\-➔→=~:>]+([㉠-㉭ㄱ-ㅎ가-힣\w]+)/gu;

                let m;

                while ((m = regex.exec(text)) !== null) {

                    pairs.push({ from: '(' + m[1] + ')', to: m[2], raw: m[0] });

                }

            }

            return pairs;

        }



        // 4대 문항 표준 유형 매핑 (하위 호환 지원)

        function getDisplayType(r) {
            if (!r) return 'objective';
            // QUESTION TYPE SYSTEM 기준: type을 재변환하지 않고 그대로 표시한다.
            // 단답형/주관식 type 통일(short_answer/subjective) 이후이므로
            // type 값을 그대로 노출한다.
            return r.type || 'objective';
        }



        function handleAnswerInput(idx, val) {

            const r = rois[idx];

            if (!r) return;

            r.answer = val;



            if (getDisplayType(r) === 'matching_line') {

                const previewEl = document.getElementById(`matching-preview-${idx}`);

                const pairs = parseMatchingPairs(r.answer);

                if (previewEl) {

                    if (pairs.length > 0) {

                        previewEl.innerHTML = pairs.map(p => `<span class="pair-chip">🔗 ${escapeHtml(p.from)} ➔ ${escapeHtml(p.to)}</span>`).join('');

                    } else {

                        previewEl.innerHTML = `<span class="pair-chip-empty">정답 예시: (1)-㉢, (2)-㉡, (3)-㉠ 또는 1-ㄷ, 2-ㄴ, 3-ㄱ</span>`;

                    }

                }

                draw(); // 선잇기 실시간 캔버스 오버레이 즉시 리드로우

            }

        }



        function handleAnswerChange(idx, val) {

            const r = rois[idx];

            if (!r) return;

            r.answer = val.trim();

            if (getDisplayType(r) === 'matching_line' && r.has_partial_score) {

                const pairs = parseMatchingPairs(r.answer);

                refreshSubScoresForPairs(idx, pairs);

            }

            draw();

            renderSidebar();

        }



        function refreshSubScoresForPairs(idx, pairs) {

            const r = rois[idx];

            if (!r || !pairs || pairs.length === 0) return;

            if (!r.sub_scores) r.sub_scores = {};

            const base = Math.max(1, Math.floor((r.score || pairs.length) / pairs.length));

            let currentSum = 0;

            pairs.forEach((p, i) => {

                const k = String(i + 1);

                if (r.sub_scores[k] === undefined) {

                    r.sub_scores[k] = base;

                }

                currentSum += Number(r.sub_scores[k]);

            });

            r.score = currentSum;

        }



        function updateSubScore(idx, pairKey, val) {

            const r = rois[idx];

            if (!r) return;

            if (!r.sub_scores) r.sub_scores = {};

            r.sub_scores[pairKey] = parseFloat(val) || 0;



            let sum = 0;

            for (let k in r.sub_scores) {

                sum += Number(r.sub_scores[k]) || 0;

            }

            r.score = sum;

            renderSidebar();

        }



        function togglePartialScore(idx, isChecked) {

            const r = rois[idx];

            if (!r) return;

            pushUndoState();

            r.has_partial_score = isChecked;

            if (isChecked) {

                if (!r.sub_scores) r.sub_scores = {};

                if (getDisplayType(r) === 'matching_line') {

                    const pairs = parseMatchingPairs(r.answer || '');

                    if (pairs.length > 0) {

                        refreshSubScoresForPairs(idx, pairs);

                    }

                } else {

                    const tokens = (r.answer || '').split(/[,;]+/).map(s => s.trim()).filter(Boolean);

                    const count = Math.max(tokens.length, (Array.isArray(r.ans_rois) ? r.ans_rois.length : 1));

                    const base = Math.max(1, Math.floor((r.score || count) / count));

                    let sum = 0;

                    for (let t = 0; t < count; t++) {

                        const k = String(t + 1);

                        if (r.sub_scores[k] === undefined) {

                            r.sub_scores[k] = base;

                        }

                        sum += Number(r.sub_scores[k]);

                    }

                    r.score = sum;

                }

            }

            renderSidebar();

        }



        // 선 잇기 방향 설정 (세로형 / 가로형)

        function setMatchingDirection(idx, dir) {

            const r = rois[idx];

            if (!r) return;

            pushUndoState();

            r.matching_direction = dir;

            if (!r.matching_info) r.matching_info = {};

            r.matching_info.direction = dir;

            draw();

            renderSidebar();

        }        // 다양한 AI 반환 스케일(0.0~1.0, 0~100%, 0~1000)을 [0.0, 1.0]으로 정규화하는 안전 헬퍼



        function normCoord(val) {



            const v = parseFloat(val);



            if (isNaN(v) || v <= 0) return 0.0;



            if (v <= 1.0) return v;



            if (v <= 100.0) return v / 100.0;



            if (v <= 1000.0) return v / 1000.0;



            return 1.0;



        }







        // 선 잇기 문항 Gemini Vision 정밀 분석 트리거



        async function triggerGeminiMatchingAnalysis(idx) {



            const r = rois[idx];



            if (!r || !r.ans_roi) return;







            // 해당 문항의 페이지로 전환 확인



            const targetPageIdx = pages.findIndex(p => p.page_uid === r.page_uid || p.page_num === r.page);



            if (targetPageIdx >= 0 && targetPageIdx !== activePageIndex) {



                switchPage(targetPageIdx);



            }







            const pageImg = pageImages[activePageIndex];



            if (!pageImg || !pageImg.complete || pageImg.naturalWidth === 0) {



                showToast('시험지 이미지를 로드 중입니다. 잠시 후 다시 시도해주세요.');



                return;



            }







            const btnEl = document.getElementById(`btn-ai-${idx}`);



            const origBtnHtml = btnEl ? btnEl.innerHTML : '';



            if (btnEl) {



                btnEl.disabled = true;



                btnEl.innerHTML = '⏳ AI 분석 중...';



            }







            try {



                // 문항 영역 크롭 계산 (문항 시작점부터 다음 문항 바로 위까지)



                const topY = r.num_roi ? Math.max(0, r.num_roi.y - 10) : Math.max(0, r.ans_roi.y - 30);



                



                // 같은 페이지의 다른 문항들 중 아래쪽에 위치한 첫 문항 탐색



                const samePageRois = rois.filter((other, oIdx) => oIdx !== idx && (other.page_uid === r.page_uid || other.page === r.page));



                const thisColLeft = (r.num_roi ? r.num_roi.x : r.ans_roi.x) < pageImg.naturalWidth * 0.5;



                const nextQuestions = samePageRois.filter(other => {



                    const otherX = other.num_roi ? other.num_roi.x : other.ans_roi.x;



                    const otherIsLeft = (otherX < pageImg.naturalWidth * 0.5);



                    if (otherIsLeft !== thisColLeft) return false;



                    const otherY = (other.num_roi ? other.num_roi.y : other.ans_roi.y);



                    return otherY > (r.num_roi ? r.num_roi.y + 15 : r.ans_roi.y);



                }).sort((a, b) => {



                    const ay = a.num_roi ? a.num_roi.y : a.ans_roi.y;



                    const by = b.num_roi ? b.num_roi.y : b.ans_roi.y;



                    return ay - by;



                });







                let bottomY = (nextQuestions.length > 0)



                    ? (nextQuestions[0].num_roi ? nextQuestions[0].num_roi.y - 5 : nextQuestions[0].ans_roi.y - 5)



                    : Math.min(pageImg.naturalHeight - 15, topY + 550);



                



                bottomY = Math.min(pageImg.naturalHeight, Math.max(topY + 100, bottomY));







                const isLeftCol = (r.num_roi ? r.num_roi.x : r.ans_roi.x) < pageImg.naturalWidth * 0.5;



                const cropX = isLeftCol ? Math.max(0, Math.round(pageImg.naturalWidth * 0.04)) : Math.round(pageImg.naturalWidth * 0.50);



                let cropW = Math.round(pageImg.naturalWidth * 0.46);



                if (cropX + cropW > pageImg.naturalWidth) {



                    cropW = pageImg.naturalWidth - cropX;



                }



                const cropY = topY;



                let cropH = bottomY - topY;



                if (cropY + cropH > pageImg.naturalHeight) {



                    cropH = pageImg.naturalHeight - cropY;



                }







                // 오프스크린 캔버스에 크롭 렌더링



                const offCanvas = document.createElement('canvas');



                offCanvas.width = cropW;



                offCanvas.height = cropH;



                const offCtx = offCanvas.getContext('2d');



                offCtx.drawImage(pageImg, cropX, cropY, cropW, cropH, 0, 0, cropW, cropH);



                const base64Data = offCanvas.toDataURL('image/jpeg', 0.88);







                const resp = await fetch('/api/templates/analyze_matching.php', {



                    method: 'POST',



                    headers: { 'Content-Type': 'application/json' },



                    body: JSON.stringify({



                        image_base64: base64Data,



                        answer_key: r.answer || ''



                    })



                });







                const json = await resp.json();



                if (!json.success || !json.data) {



                    throw new Error(json.message || 'AI 분석에 실패했습니다.');



                }







                const resData = json.data;



                pushUndoState();                // 1. matching_box가 감지되었으면 ans_roi를 통합 영역으로 자동 업데이트 (스케일 자동 판정 및 Strict Clamping)



                if (resData.matching_box && typeof resData.matching_box.ymin !== 'undefined') {



                    const box = resData.matching_box;



                    const rawYmin = parseFloat(box.ymin || 0);



                    const rawXmin = parseFloat(box.xmin || 0);



                    const rawYmax = parseFloat(box.ymax || 1);



                    const rawXmax = parseFloat(box.xmax || 1);







                    const maxCoord = Math.max(rawYmin, rawXmin, rawYmax, rawXmax);



                    let boxDivisor = 1.0;



                    if (maxCoord > 100.0) {



                        boxDivisor = 1000.0;



                    } else if (maxCoord > 1.0) {



                        boxDivisor = 100.0;



                    }







                    let mYmin = Math.max(0.0, Math.min(1.0, rawYmin / boxDivisor));



                    let mXmin = Math.max(0.0, Math.min(1.0, rawXmin / boxDivisor));



                    let mYmax = Math.max(0.0, Math.min(1.0, rawYmax / boxDivisor));



                    let mXmax = Math.max(0.0, Math.min(1.0, rawXmax / boxDivisor));







                    if (mXmax <= mXmin) { mXmin = 0.05; mXmax = 0.95; }



                    if (mYmax <= mYmin) { mYmin = 0.15; mYmax = 0.95; }







                    const rawX = Math.round(cropX + mXmin * cropW);



                    const rawY = Math.round(cropY + mYmin * cropH);



                    const rawW = Math.round((mXmax - mXmin) * cropW);



                    const rawH = Math.round((mYmax - mYmin) * cropH);







                    // 단(Column) 경계 및 다음 문항 침범 엄격 클램핑 (Strict Clamping)



                    const colRight = cropX + cropW;



                    const newX = Math.max(cropX, Math.min(colRight - 40, rawX));



                    const newW = Math.max(40, Math.min(colRight - newX, rawW));







                    const newY = Math.max(cropY, Math.min(bottomY - 30, rawY));



                    const newH = Math.max(40, Math.min(bottomY - newY, rawH));







                    r.ans_roi.x = newX;



                    r.ans_roi.y = newY;



                    r.ans_roi.w = newW;



                    r.ans_roi.h = newH;



                }







                // 2. dot들을 r.ans_roi 기준 정규화 좌표(0.0~1.0)로 변환하여 matching_info에 저장



                const allDots = [...(resData.source_dots || []), ...(resData.target_dots || [])];



                let maxDotCoord = 0.0;



                allDots.forEach(d => {



                    maxDotCoord = Math.max(maxDotCoord, parseFloat(d.norm_x || 0), parseFloat(d.norm_y || 0));



                });



                let dotDivisor = 1.0;



                if (maxDotCoord > 100.0) {



                    dotDivisor = 1000.0;



                } else if (maxDotCoord > 1.0) {



                    dotDivisor = 100.0;



                }







                const curAr = r.ans_roi;



                const normSourceDots = (resData.source_dots || []).map(d => {



                    const dnx = Math.max(0.0, Math.min(1.0, parseFloat(d.norm_x || 0) / dotDivisor));



                    const dny = Math.max(0.0, Math.min(1.0, parseFloat(d.norm_y || 0) / dotDivisor));



                    const absX = cropX + dnx * cropW;



                    const absY = cropY + dny * cropH;



                    const roiNormX = (curAr.w > 0) ? Math.max(0.0, Math.min(1.0, (absX - curAr.x) / curAr.w)) : dnx;



                    const roiNormY = (curAr.h > 0) ? Math.max(0.0, Math.min(1.0, (absY - curAr.y) / curAr.h)) : dny;



                    return {



                        label: d.label || '',



                        abs_x: absX,



                        abs_y: absY,



                        roi_norm_x: roiNormX,



                        roi_norm_y: roiNormY,



                        item_box: d.item_box || null



                    };



                });







                const normTargetDots = (resData.target_dots || []).map(d => {



                    const dnx = Math.max(0.0, Math.min(1.0, parseFloat(d.norm_x || 0) / dotDivisor));



                    const dny = Math.max(0.0, Math.min(1.0, parseFloat(d.norm_y || 0) / dotDivisor));



                    const absX = cropX + dnx * cropW;



                    const absY = cropY + dny * cropH;



                    const roiNormX = (curAr.w > 0) ? Math.max(0.0, Math.min(1.0, (absX - curAr.x) / curAr.w)) : dnx;



                    const roiNormY = (curAr.h > 0) ? Math.max(0.0, Math.min(1.0, (absY - curAr.y) / curAr.h)) : dny;



                    return {



                        label: d.label || '',



                        abs_x: absX,



                        abs_y: absY,



                        roi_norm_x: roiNormX,



                        roi_norm_y: roiNormY,



                        item_box: d.item_box || null



                    };



                });







                r.matching_info = {



                    direction: resData.direction || 'vertical',



                    source_dots: normSourceDots,



                    target_dots: normTargetDots,



                    analyzed_at: new Date().toISOString()



                };



                r.matching_direction = r.matching_info.direction;







                showToast(`✨ Q${r.display_num || r.id} 선잇기 정밀 인식 완료 (${r.matching_info.direction === 'vertical' ? '세로형' : '가로형'}, 점 ${normSourceDots.length + normTargetDots.length}개)`);



            } catch (err) {

                console.error('[Gemini Matching Analysis Error]', err);

                showToast('선잇기 AI 분석 안내: ' + err.message);

            } finally {

                if (btnEl) {

                    btnEl.disabled = false;

                    btnEl.innerHTML = origBtnHtml;

                }

                draw();

                renderSidebar();

            }

        }



        // ponytail: 답안 영역 클릭/휠 시 유형 순환 (1개일 때 4대 유형 순환, 2개 이상일 때 소문항 ⇄ 복합 제한)

        function cycleRoiType(idx) {

            const item = rois[idx];

            if (!item) return;



            const parentKey = item.num_roi ? `${item.page_uid}_${Math.round(item.num_roi.x)}_${Math.round(item.num_roi.y)}` : '';

            const subCount = parentKey ? rois.filter(x => x.num_roi && `${x.page_uid}_${Math.round(x.num_roi.x)}_${Math.round(x.num_roi.y)}` === parentKey).length : 1;

            const hasMultipleAns = subCount > 1;



            if (hasMultipleAns) {

                // 답안 영역이 이미 2개 이상: 유형 변경 없이 현재 상태 유지

            } else {

                // 답안 영역이 1개: 4대 유형 순환 (객관식 ➔ 단답/OX ➔ 선잇기 ➔ 서술형)

                const order = ['objective', 'short_answer', 'matching_line', 'subjective'];

                const curType = getDisplayType(item);

                const curIdx = order.indexOf(curType);

                const nextType = order[(curIdx + 1) % order.length];

                setType(idx, nextType, false);

            }

            selectedRoiIndex = idx;

        }



        function startAddAnsRoiMode(idx) {

            addAnsTargetIndex = idx;

            selectedRoiIndex = idx;

            const item = rois[idx];

            if (item) {

                const targetPageIdx = pages.findIndex(p => p.page_uid === item.page_uid || p.page_num === item.page);

                if (targetPageIdx >= 0 && targetPageIdx !== activePageIndex) {

                    switchPage(targetPageIdx);

                }

            }

            draw();

            renderSidebar();

        }



        function getTargetRect(target) {

            if (!target) return null;

            if (target.isMask && privacyMaskRois[target.maskIndex]) {

                return privacyMaskRois[target.maskIndex];

            }

            const item = rois[target.itemIndex];

            if (!item) return null;

            if (target.roiType === 'ans_rois' && Array.isArray(item.ans_rois) && typeof target.subAnsIndex === 'number') {

                return item.ans_rois[target.subAnsIndex];

            }

            return item[target.roiType];

        }



        // 현재 활성 페이지에 속한 ROI만 타겟팅

                // 선잇기 앵커 점의 캔버스 절대 좌표 단일 계산 함수 (SSOT)

        function getMatchingDotCanvasPos(r, dot) {

            if (!r || !r.ans_roi || !dot) return { x: 0, y: 0 };

            const ar = r.ans_roi;

            const nx = (dot.roi_norm_x !== undefined) ? dot.roi_norm_x : (dot.norm_x !== undefined ? dot.norm_x : 0.5);

            const ny = (dot.roi_norm_y !== undefined) ? dot.roi_norm_y : (dot.norm_y !== undefined ? dot.norm_y : 0.5);

            return {

                x: ar.x + nx * ar.w,

                y: ar.y + ny * ar.h

            };

        }



        // 선잇기 문항의 모든 페어가 고유한 앵커 객체를 갖도록 보장하는 동기화 헬퍼

        function ensureMatchingDotsInitialized(r) {

            if (!r.matching_info) {

                r.matching_info = {

                    direction: (r.ans_roi && r.ans_roi.h > r.ans_roi.w * 0.75) ? 'vertical' : 'horizontal',

                    source_dots: [],

                    target_dots: [],

                    pairs: []

                };

            }

            const pairs = parseMatchingPairs(r.answer || '');

            const isVertical = (r.matching_info.direction === 'vertical');

            if (!Array.isArray(r.matching_info.source_dots)) r.matching_info.source_dots = [];

            if (!Array.isArray(r.matching_info.target_dots)) r.matching_info.target_dots = [];



            const cleanLabel = (s) => String(s || '').replace(/[\(\)\（\）\s]/g, '');



            // 1. source_dots 확인 및 누락분 자동 생성

            pairs.forEach((p, pIdx) => {

                const fromCl = cleanLabel(p.from);

                let found = r.matching_info.source_dots.find(d => cleanLabel(d.label) === fromCl || cleanLabel(d.label) === String(pIdx + 1));

                if (!found) {

                    const normX = isVertical ? ((pIdx + 0.5) / Math.max(1, pairs.length)) : 0.42;

                    const normY = isVertical ? 0.35 : ((pIdx + 0.5) / Math.max(1, pairs.length));

                    const newDot = {

                        label: p.from,

                        roi_norm_x: normX,

                        roi_norm_y: normY,

                        abs_x: r.ans_roi ? (r.ans_roi.x + normX * r.ans_roi.w) : 0,

                        abs_y: r.ans_roi ? (r.ans_roi.y + normY * r.ans_roi.h) : 0

                    };

                    r.matching_info.source_dots.push(newDot);

                }

            });



            // 2. target_dots 확인 및 누락분 자동 생성

            const allTargets = Array.from(new Set(pairs.map(item => item.to))).sort();

            pairs.forEach((p, pIdx) => {

                const toCl = cleanLabel(p.to);

                let found = r.matching_info.target_dots.find(d => cleanLabel(d.label) === toCl);

                if (!found) {

                    let tIdx = allTargets.indexOf(p.to);

                    if (tIdx < 0) tIdx = pIdx;

                    const tCount = Math.max(allTargets.length, pairs.length);

                    const normX = isVertical ? ((tIdx + 0.5) / Math.max(1, tCount)) : 0.78;

                    const normY = isVertical ? 0.85 : ((tIdx + 0.5) / Math.max(1, tCount));

                    const newDot = {

                        label: p.to,

                        roi_norm_x: normX,

                        roi_norm_y: normY,

                        abs_x: r.ans_roi ? (r.ans_roi.x + normX * r.ans_roi.w) : 0,

                        abs_y: r.ans_roi ? (r.ans_roi.y + normY * r.ans_roi.h) : 0

                    };

                    r.matching_info.target_dots.push(newDot);

                }

            });

        }



        // 드래그된 점 근처(반경 15px)에 실제 시험지 이미지의 인쇄된 흑색 점(•)이 있으면 자석처럼 무게중심으로 스냅

        function snapDotToNearestDarkBullet(r, dot) {

            if (!r || !r.ans_roi || !dot) return;

            const targetPageIdx = pages.findIndex(p => p.page_uid === r.page_uid || p.page_num === r.page);

            const pageImg = (targetPageIdx >= 0 && pageImages[targetPageIdx] && pageImages[targetPageIdx].complete)

                ? pageImages[targetPageIdx]

                : pageImages[activePageIndex];

            if (!pageImg || !pageImg.complete || pageImg.naturalWidth === 0) return;



            const ar = r.ans_roi;

            const pt = getMatchingDotCanvasPos(r, dot);

            const radius = 15;



            const off = document.createElement('canvas');

            const offSize = radius * 2 + 1;

            off.width = offSize;

            off.height = offSize;

            const oCtx = off.getContext('2d');

            try {

                oCtx.drawImage(pageImg, pt.x - radius, pt.y - radius, offSize, offSize, 0, 0, offSize, offSize);

                const imgData = oCtx.getImageData(0, 0, offSize, offSize);

                const data = imgData.data;



                let sumX = 0, sumY = 0, darkCount = 0;

                for (let py = 0; py < offSize; py++) {

                    for (let px = 0; px < offSize; px++) {

                        const idx = (py * offSize + px) * 4;

                        const red = data[idx];

                        const green = data[idx + 1];

                        const blue = data[idx + 2];

                        const dist = Math.hypot(px - radius, py - radius);

                        if (dist <= radius && red < 95 && green < 95 && blue < 95) {

                            sumX += px;

                            sumY += py;

                            darkCount++;

                        }

                    }

                }



                if (darkCount >= 4) {

                    const snapCanvasX = (pt.x - radius) + (sumX / darkCount);

                    const snapCanvasY = (pt.y - radius) + (sumY / darkCount);

                    const relX = Math.max(0, Math.min(ar.w, snapCanvasX - ar.x));

                    const relY = Math.max(0, Math.min(ar.h, snapCanvasY - ar.y));

                    dot.roi_norm_x = (ar.w > 0) ? (relX / ar.w) : 0;

                    dot.roi_norm_y = (ar.h > 0) ? (relY / ar.h) : 0;

                    dot.abs_x = ar.x + relX;

                    dot.abs_y = ar.y + relY;

                }

            } catch (e) {

                // cross-origin 등 예외 발생 시 원래 위치 유지

            }

        }



        function findRoiAtPos(x, y) {

            const currentPg = pages[activePageIndex];

            if (!currentPg) return null;



            // 1쪽인 경우 개인정보 마스킹 영역 히트테스트

            if (activePageIndex === 0 && Array.isArray(privacyMaskRois)) {

                for (let m = privacyMaskRois.length - 1; m >= 0; m--) {

                    const mask = privacyMaskRois[m];

                    if (!mask) continue;

                    const handles = getHandles(mask);

                    for (let hKey in handles) {

                        const h = handles[hKey];

                        if (x >= h.x && x <= h.x + h.w && y >= h.y && y <= h.y + h.h) {

                            selectedMaskIndex = m;

                            selectedRoiIndex = null;

                            return { isMask: true, maskIndex: m, handle: hKey };

                        }

                    }

                    if (x >= mask.x && x <= mask.x + mask.w && y >= mask.y && y <= mask.y + mask.h) {

                        selectedMaskIndex = m;

                        selectedRoiIndex = null;

                        return { isMask: true, maskIndex: m, handle: null };

                    }

                }

            }



            // 0. 선잇기(Matching Line) 문항의 기준점(Anchor Dots) 히트테스트 (우선순위 최고, 반경 16px)

            for (let i = rois.length - 1; i >= 0; i--) {

                const item = rois[i];

                if (!item || getDisplayType(item) !== 'matching_line') continue;

                if (item.page_uid !== currentPg.page_uid && item.page !== currentPg.page_num) continue;

                if (!item.ans_roi) continue;



                ensureMatchingDotsInitialized(item);



                const sDots = item.matching_info.source_dots || [];

                for (let sIdx = 0; sIdx < sDots.length; sIdx++) {

                    const d = sDots[sIdx];

                    const pt = getMatchingDotCanvasPos(item, d);

                    if (Math.hypot(x - pt.x, y - pt.y) <= 16) {

                        return { itemIndex: i, isAnchorDot: true, dotType: 'source', dotIndex: sIdx, dot: d };

                    }

                }



                const tDots = item.matching_info.target_dots || [];

                for (let tIdx = 0; tIdx < tDots.length; tIdx++) {

                    const d = tDots[tIdx];

                    const pt = getMatchingDotCanvasPos(item, d);

                    if (Math.hypot(x - pt.x, y - pt.y) <= 16) {

                        return { itemIndex: i, isAnchorDot: true, dotType: 'target', dotIndex: tIdx, dot: d };

                    }

                }

            }



            for (let i = rois.length - 1; i >= 0; i--) {

                const item = rois[i];

                if (!item) continue;

                if (item.page_uid !== currentPg.page_uid && item.page !== currentPg.page_num) continue;






                // 2. 일반 ans_roi 및 num_roi 검사

                const roiTypes = ['ans_roi', 'num_roi'];



                for (let type of roiTypes) {

                    const rect = item[type];

                    if (!rect) continue;



                    const handles = getHandles(rect);

                    for (let hKey in handles) {

                        const h = handles[hKey];

                        if (x >= h.x && x <= h.x + h.w && y >= h.y && y <= h.y + h.h) {

                            return { itemIndex: i, roiType: type, handle: hKey };

                        }

                    }



                    if (x >= rect.x && x <= rect.x + rect.w && y >= rect.y && y <= rect.y + rect.h) {

                        return { itemIndex: i, roiType: type, handle: null };

                    }

                }

            }

            return null;

        }



        function getHandles(rect) {

            const s = HANDLE_SIZE;

            return {

                tl: { x: rect.x - s / 2, y: rect.y - s / 2, w: s, h: s },

                tr: { x: rect.x + rect.w - s / 2, y: rect.y - s / 2, w: s, h: s },

                bl: { x: rect.x - s / 2, y: rect.y + rect.h - s / 2, w: s, h: s },

                br: { x: rect.x + rect.w - s / 2, y: rect.y + rect.h - s / 2, w: s, h: s }

            };

        }



        // ponytail: 문항 유형 변경 (4대 표준 유형 및 소문항/복합 호환)

        function setType(idx, type, startDrawMode = true) {

            const r = rois[idx];

            if (!r) return;



            // 해당 문항의 페이지로 이동 및 포커스 설정

            const targetPageIdx = pages.findIndex(p => p.page_uid === r.page_uid || p.page_num === r.page);

            if (targetPageIdx >= 0 && targetPageIdx !== activePageIndex) {

                switchPage(targetPageIdx);

            }

            selectedRoiIndex = idx;



            // 이미 동일한 타입인 경우: 소문항 또는 복합이면 추가 답안 모드로 진입

            if (r.type === type) {

                if (type === 'sub_item') {

                    startAddAnsRoiMode(idx);

                }

                return;

            }



            // 이미 다중 답안을 가진 문항을 단일 문항으로 변경하려는 경우

            const pk = r.num_roi ? `${r.page_uid}_${Math.round(r.num_roi.x)}_${Math.round(r.num_roi.y)}` : null;

            const sameGroupSubs = (r.type === 'sub_item' && pk)

                ? rois.filter(rx => rx.num_roi && `${rx.page_uid}_${Math.round(rx.num_roi.x)}_${Math.round(rx.num_roi.y)}` === pk)

                : [];

            const isSubMulti = sameGroupSubs.length > 1;



            const isSingleTarget = ['objective', 'short_answer', 'matching_line', 'subjective'].includes(type);



            if (isSingleTarget && isSubMulti) {

                let targetName = '단일 문항';

                if (type === 'objective') targetName = '객관식';

                else if (type === 'short_answer') targetName = '단답/OX';

                else if (type === 'matching_line') targetName = '선잇기';

                else if (type === 'essay') targetName = '서술형';



                if (!confirm(`이 문항에는 여러 개의 답안 영역이 설정되어 있습니다.\n단일 ${targetName}(으)로 변경하면 첫 번째 답안 영역만 남고 나머지는 삭제됩니다. 계속하시겠습니까?`)) {

                    renderSidebar();

                    return;

                }

                pushUndoState();

                if (isSubMulti) {

                    const keepRoi = sameGroupSubs[0];

                    const removeIndices = [];

                    rois.forEach((item, i) => {

                        if (sameGroupSubs.includes(item) && item !== keepRoi) {

                            removeIndices.push(i);

                        }

                    });

                    for (let i = removeIndices.length - 1; i >= 0; i--) {

                        rois.splice(removeIndices[i], 1);

                    }

                    selectedRoiIndex = rois.indexOf(keepRoi);

                }

            } else {

                pushUndoState();

            }



            const targetRoi = rois[selectedRoiIndex];

            if (!targetRoi) return;



            targetRoi.type = type;

            targetRoi.sub_type = type;

            if (type === 'sub_item') {

                targetRoi.ans_rois = null;

            } else {

                targetRoi.ans_rois = null;

                targetRoi.sub_id = null;

            }



            // 선잇기 전환 시 기본값 보장 및 Gemini 자동 분석 트리거

            if (type === 'matching_line') {

                if (targetRoi.has_partial_score === undefined) targetRoi.has_partial_score = false;

                if (!targetRoi.sub_scores) targetRoi.sub_scores = {};

                // 사용자가 선잇기로 유형 전환 시 자동으로 Gemini 정밀 분석 수행

                if (!targetRoi.matching_info) {

                    setTimeout(() => triggerGeminiMatchingAnalysis(idx), 60);

                }

            }



            // 소문항 버튼 클릭 시 reindexRois 전에 추가 모드 타깃을 미리 등록하여 단일 객관식 강등 방지

            if (startDrawMode && type === 'sub_item') {

                addAnsTargetIndex = selectedRoiIndex;

            } else {

                addAnsTargetIndex = null;

            }



            reindexRois();

            draw();

            renderSidebar();



            if (startDrawMode && type === 'sub_item') {

                startAddAnsRoiMode(selectedRoiIndex);

            }

        }



        function deleteRoi(idx) {

            pushUndoState();

            rois.splice(idx, 1);

            reindexRois();

            draw();

            renderSidebar();

            renderPageTabs();

        }



        // ponytail: 소문항/복합 답안이 1개만 남으면 단일 문항(객관식)으로 자동 승격 및 재색인

        function reindexRois() {

            // 1. 각 num_roi 기준 그룹 개수 집계

            const groupCounts = {};

            rois.forEach(r => {

                if (r.num_roi) {

                    const pk = `${r.page_uid}_${Math.round(r.num_roi.x)}_${Math.round(r.num_roi.y)}`;

                    groupCounts[pk] = (groupCounts[pk] || 0) + 1;

                }

            });



            let currentMainNum = 0;

            let lastParentKey = null;

            let currentSubNum = 0;



            for (let i = 0; i < rois.length; i++) {

                const r = rois[i];

                const parentKey = r.num_roi ? `${r.page_uid}_${Math.round(r.num_roi.x)}_${Math.round(r.num_roi.y)}` : null;

                const countInGroup = parentKey ? (groupCounts[parentKey] || 1) : 1;



                // ponytail: 현재 사용자가 추가 작업 중인 문항인지 확인 (추가 모드 중에는 1개여도 강등 방지)

                const targetRoi = (addAnsTargetIndex !== null) ? rois[addAnsTargetIndex] : null;

                const isUnderAddition = targetRoi && (r.id === targetRoi.id || i === addAnsTargetIndex || (r.num_roi && targetRoi.num_roi && r.page_uid === targetRoi.page_uid && Math.round(r.num_roi.x) === Math.round(targetRoi.num_roi.x) && Math.round(r.num_roi.y) === Math.round(targetRoi.num_roi.y)));




                // 소문항이었으나 그룹 내 1개만 남은 경우 (단, 추가 모드 중이면 유지)

                const isActualSubItem = (r.type === 'sub_item' || r.sub_id) && (countInGroup > 1 || isUnderAddition);



                if (isActualSubItem) {

                    if (parentKey !== lastParentKey) {

                        currentMainNum++;

                        lastParentKey = parentKey;

                        currentSubNum = 1;

                    } else {

                        currentSubNum++;

                    }

                    r.id = currentMainNum;

                    r.sub_id = currentSubNum;

                    r.display_num = `${currentMainNum}-${currentSubNum}`;

                    r.type = 'sub_item';

                } else {

                    // 단일 문항으로 승격 또는 유지

                    if (r.type === 'sub_item') {

                        r.type = (r.sub_type === 'subjective') ? 'subjective' : 'objective';

                    }

                    currentMainNum++;

                    lastParentKey = null;

                    currentSubNum = 0;

                    r.id = currentMainNum;

                    r.sub_id = null;

                    r.display_num = `${currentMainNum}`;

                }

            }

        }



        function drawHandles(rect) {

            const handles = getHandles(rect);

            ctx.fillStyle = "#ffffff";

            ctx.strokeStyle = "#000000";

            ctx.lineWidth = 1;

            for (let hKey in handles) {

                const h = handles[hKey];

                ctx.fillRect(h.x, h.y, h.w, h.h);

                ctx.strokeRect(h.x, h.y, h.w, h.h);

            }

        }



        function getCanvasPos(e) {

            const rect = canvas.getBoundingClientRect();

            const scaleX = canvas.width / rect.width;

            const scaleY = canvas.height / rect.height;

            return {

                x: (e.clientX - rect.left) * scaleX,

                y: (e.clientY - rect.top) * scaleY

            };

        }



        // 선잇기(Matching Line) 문항의 연결선 오버레이 실시간 렌더링



        function drawMatchingLinesOverlay(r, isSelected) {



            if (!r || !r.ans_roi) return;



            ensureMatchingDotsInitialized(r);



            const pairs = parseMatchingPairs(r.answer || '');



            const dir = (r.matching_info && r.matching_info.direction) ? r.matching_info.direction : ((r.matching_direction && r.matching_direction !== 'auto') ? r.matching_direction : ((r.ans_roi.h > r.ans_roi.w * 0.75) ? 'vertical' : 'horizontal'));



            const isVertical = (dir === 'vertical');







            const sDots = (r.matching_info && Array.isArray(r.matching_info.source_dots)) ? r.matching_info.source_dots : [];



            const tDots = (r.matching_info && Array.isArray(r.matching_info.target_dots)) ? r.matching_info.target_dots : [];







            const cleanLabel = (s) => String(s || '').replace(/[\(\)\（\）\s]/g, '');



            const pairColors = ['#0284c7', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#ea580c'];







            if (pairs.length === 0) {



                // 답안 미입력 시: 감지된 점들의 위치 안내



                [...sDots, ...tDots].forEach(d => {



                    const pt = getMatchingDotCanvasPos(r, d);



                    ctx.save();



                    ctx.beginPath();



                    ctx.arc(pt.x, pt.y, 4.5, 0, Math.PI * 2);



                    ctx.fillStyle = "#0d9488";



                    ctx.fill();



                    ctx.lineWidth = 1.5;



                    ctx.strokeStyle = "#ffffff";



                    ctx.stroke();







                    ctx.font = "bold 10.5px sans-serif";



                    ctx.fillStyle = "#5eead4";



                    ctx.fillText(d.label || '', pt.x + 6, pt.y + 3);



                    ctx.restore();



                });



                return;



            }







            pairs.forEach((p, pIdx) => {



                const pCol = pairColors[pIdx % pairColors.length];



                const fromCl = cleanLabel(p.from);



                const toCl = cleanLabel(p.to);







                // 1. 시작점(Source Dot) 위치 결정 (SSOT getMatchingDotCanvasPos 사용)



                let startPt = null;



                const matchedSDot = sDots.find(d => cleanLabel(d.label) === fromCl || cleanLabel(d.label) === String(pIdx + 1));



                if (matchedSDot) {



                    startPt = getMatchingDotCanvasPos(r, matchedSDot);



                } else {



                    startPt = isVertical



                        ? { x: r.ans_roi.x + r.ans_roi.w * ((pIdx + 0.5) / pairs.length), y: r.ans_roi.y + r.ans_roi.h * 0.35 }



                        : { x: r.ans_roi.x + r.ans_roi.w * 0.42, y: r.ans_roi.y + r.ans_roi.h * ((pIdx + 0.5) / pairs.length) };



                }







                // 2. 도착점(Target Dot) 위치 결정 (SSOT getMatchingDotCanvasPos 사용)



                let endPt = null;



                const matchedTDot = tDots.find(d => cleanLabel(d.label) === toCl);



                if (matchedTDot) {



                    endPt = getMatchingDotCanvasPos(r, matchedTDot);



                } else {



                    const allTargets = Array.from(new Set(pairs.map(item => item.to))).sort();



                    let tIdx = allTargets.indexOf(p.to);



                    if (tIdx < 0) tIdx = pIdx;



                    const tCount = Math.max(allTargets.length, pairs.length);



                    endPt = isVertical



                        ? { x: r.ans_roi.x + r.ans_roi.w * ((tIdx + 0.5) / tCount), y: r.ans_roi.y + r.ans_roi.h * 0.85 }



                        : { x: r.ans_roi.x + r.ans_roi.w * 0.78, y: r.ans_roi.y + r.ans_roi.h * ((tIdx + 0.5) / tCount) };



                }







                // 3. 연결선 렌더링

                ctx.save();

                ctx.strokeStyle = pCol;

                ctx.lineWidth = isSelected ? 3.5 : 2.5;

                if (isSelected) {

                    ctx.shadowColor = pCol;

                    ctx.shadowBlur = 8;

                }

                ctx.beginPath();

                ctx.moveTo(startPt.x, startPt.y);

                ctx.lineTo(endPt.x, endPt.y);

                ctx.stroke();

                ctx.restore();



                // 4. 시작점 앵커 원 (드래그 핸들)

                ctx.save();

                ctx.beginPath();

                ctx.arc(startPt.x, startPt.y, isSelected ? 5.5 : 4.5, 0, Math.PI * 2);

                ctx.fillStyle = pCol;

                ctx.fill();

                ctx.lineWidth = isSelected ? 2 : 1.5;

                ctx.strokeStyle = "#ffffff";

                ctx.stroke();

                if (isSelected) {

                    ctx.beginPath();

                    ctx.arc(startPt.x, startPt.y, 8.5, 0, Math.PI * 2);

                    ctx.strokeStyle = pCol;

                    ctx.lineWidth = 1.2;

                    ctx.stroke();

                }

                ctx.restore();



                // 5. 도착점 화살표 및 앵커 원 (드래그 핸들)

                const angle = Math.atan2(endPt.y - startPt.y, endPt.x - startPt.x);

                const headLen = isSelected ? 12 : 10;

                ctx.save();

                ctx.fillStyle = pCol;

                ctx.beginPath();

                ctx.moveTo(endPt.x, endPt.y);

                ctx.lineTo(endPt.x - headLen * Math.cos(angle - Math.PI / 7), endPt.y - headLen * Math.sin(angle - Math.PI / 7));

                ctx.lineTo(endPt.x - headLen * Math.cos(angle + Math.PI / 7), endPt.y - headLen * Math.sin(angle + Math.PI / 7));

                ctx.closePath();

                ctx.fill();

                ctx.lineWidth = 1.5;

                ctx.strokeStyle = "#ffffff";

                ctx.stroke();



                if (isSelected) {

                    ctx.beginPath();

                    ctx.arc(endPt.x, endPt.y, 8.5, 0, Math.PI * 2);

                    ctx.strokeStyle = pCol;

                    ctx.lineWidth = 1.2;

                    ctx.stroke();

                }

                ctx.restore();



                // 6. 중간 지점 페어 뱃지 ((1)➔㉢)

                const midX = (startPt.x + endPt.x) / 2;

                const midY = (startPt.y + endPt.y) / 2;

                const badgeText = `${p.from}➔${p.to}`;



                ctx.save();

                ctx.font = "bold 11px sans-serif";

                const textMetrics = ctx.measureText(badgeText);

                const bW = textMetrics.width + 10;

                const bH = 17;

                const bX = midX - bW / 2;

                const bY = midY - bH / 2;



                ctx.shadowColor = "rgba(0,0,0,0.35)";

                ctx.shadowBlur = 4;

                ctx.fillStyle = "rgba(255, 255, 255, 0.94)";

                ctx.strokeStyle = pCol;

                ctx.lineWidth = 1.5;



                if (ctx.roundRect) {

                    ctx.beginPath();

                    ctx.roundRect(bX, bY, bW, bH, 8);

                    ctx.fill();

                    ctx.stroke();

                } else {

                    ctx.fillRect(bX, bY, bW, bH);

                    ctx.strokeRect(bX, bY, bW, bH);

                }



                ctx.shadowColor = "transparent";

                ctx.fillStyle = pCol;

                ctx.textAlign = "center";

                ctx.textBaseline = "middle";

                ctx.fillText(badgeText, midX, midY);

                ctx.restore();

            });

        }



        function draw() {

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            const currentImg = pageImages[activePageIndex];

            if (currentImg && currentImg.complete && currentImg.naturalWidth > 0) {

                ctx.drawImage(currentImg, 0, 0);

            }



            const currentPg = pages[activePageIndex];

            if (!currentPg) return;



            // 1쪽인 경우 개인정보 보호 마스킹 영역 렌더링 (반투명 슬레이트 그레이)

            if (activePageIndex === 0 && Array.isArray(privacyMaskRois)) {

                privacyMaskRois.forEach((mask, mIdx) => {

                    if (!mask) return;

                    const isMaskSel = (selectedMaskIndex === mIdx);

                    ctx.save();

                    ctx.fillStyle = isMaskSel ? "rgba(71, 85, 105, 0.65)" : "rgba(100, 116, 139, 0.45)";

                    ctx.fillRect(mask.x, mask.y, mask.w, mask.h);

                    

                    ctx.lineWidth = isMaskSel ? 2.5 : 1.5;

                    ctx.strokeStyle = isMaskSel ? "#38bdf8" : "#94a3b8";

                    ctx.setLineDash([5, 4]);

                    ctx.strokeRect(mask.x, mask.y, mask.w, mask.h);

                    ctx.setLineDash([]);

                    ctx.restore();



                    // 상단 라벨 뱃지

                    ctx.save();

                    ctx.fillStyle = isMaskSel ? "#0284c7" : "#475569";

                    const badgeText = "🛡️ 개인정보 마스킹";

                    ctx.font = "bold 12px sans-serif";

                    const bW = ctx.measureText(badgeText).width + 12;

                    ctx.fillRect(mask.x, Math.max(0, mask.y - 20), bW, 20);

                    ctx.fillStyle = "#ffffff";

                    ctx.fillText(badgeText, mask.x + 6, Math.max(14, mask.y - 6));

                    ctx.restore();



                    if (isMaskSel) {

                        drawHandles(mask);

                    }

                });

            }



            // 현재 페이지의 문항만 캔버스에 렌더링

            rois.forEach((r, rIdx) => {

                if (!r || !r.num_roi || !r.ans_roi) return;

                if (r.page_uid !== currentPg.page_uid && r.page !== currentPg.page_num) return;



                const isSelected = (selectedRoiIndex === rIdx);



                const isGroupLeader = (!r.sub_id || r.sub_id === 1);

                const displayLabel = r.display_num ? `Q${r.display_num}` : `Q${r.id}`;




                // 1. 문항 번호 상자 (num_roi) - 소문항 그룹의 첫 번째 문항이거나 단일 문항일 때만 상자 렌더링

                if (isGroupLeader) {

                    ctx.save();

                    if (isSelected) {

                        ctx.shadowColor = "#ffc107";

                        ctx.shadowBlur = 12;

                        ctx.lineWidth = 3.5;

                    } else {

                        ctx.lineWidth = 2;

                    }

                    ctx.strokeStyle = "#ffc107";

                    ctx.strokeRect(r.num_roi.x, r.num_roi.y, r.num_roi.w, r.num_roi.h);

                    ctx.restore();



                    ctx.fillStyle = "#ffc107";

                    ctx.font = "bold 14px sans-serif";

                    ctx.fillText(`Q${r.id} 번호`, r.num_roi.x, r.num_roi.y - 5);

                    drawHandles(r.num_roi);

                }



                // 2. 답안 작성란 상자

                    // 4대 문항 표준 유형 렌더링

                    const dType = getDisplayType(r);

                    let ansColor = "#0284c7"; // 객관식: Blue

                    let typeTag = "[객관식]";

                    let glowColor = "#38bdf8";



                    if (dType === 'matching_line') {

                        ansColor = "#0d9488"; // 선잇기: Teal

                        typeTag = "[선잇기]";

                        glowColor = "#2dd4bf";

                    } else if (dType === 'subjective') {

                        ansColor = "#7c3aed"; // 주관식: Purple

                        typeTag = "[주관식]";

                        glowColor = "#a78bfa";

                    } else if (dType === 'short_answer') {

                        ansColor = "#d97706"; // 단답/OX: Amber

                        typeTag = (r.type === 'sub_item') ? "[소문항]" : "[단답/OX]";

                        glowColor = "#f59e0b";

                    }



                    ctx.save();

                    if (isSelected) {

                        ctx.shadowColor = glowColor;

                        ctx.shadowBlur = 14;

                        ctx.lineWidth = 3.5;

                    } else {

                        ctx.lineWidth = 2;

                    }

                    ctx.strokeStyle = ansColor;

                    ctx.fillStyle = isSelected

                        ? (dType === 'matching_line' ? "rgba(13, 148, 136, 0.35)" : (dType === 'essay' ? "rgba(124, 58, 237, 0.35)" : (dType === 'short_answer' ? "rgba(217, 119, 6, 0.35)" : "rgba(2, 132, 199, 0.35)")))

                        : (dType === 'matching_line' ? "rgba(13, 148, 136, 0.18)" : (dType === 'essay' ? "rgba(124, 58, 237, 0.18)" : (dType === 'short_answer' ? "rgba(217, 119, 6, 0.18)" : "rgba(2, 132, 199, 0.18)")));

                    ctx.fillRect(r.ans_roi.x, r.ans_roi.y, r.ans_roi.w, r.ans_roi.h);

                    ctx.strokeRect(r.ans_roi.x, r.ans_roi.y, r.ans_roi.w, r.ans_roi.h);

                    ctx.restore();



                    ctx.fillStyle = ansColor;

                    ctx.font = "bold 13px sans-serif";

                    ctx.fillText(`${displayLabel} 답안 ${typeTag}`, r.ans_roi.x, r.ans_roi.y - 5);

                    drawHandles(r.ans_roi);



                    // 선잇기 문항은 시험지 이미지 위에 연결선(화살표 및 기준점)을 실시간 오버레이

                    if (dType === 'matching_line') {

                        drawMatchingLinesOverlay(r, isSelected);

                    }

                }

            });



            if (tempNumRoi) {

                ctx.strokeStyle = "#ffc107";

                ctx.lineWidth = 2;

                ctx.strokeRect(tempNumRoi.x, tempNumRoi.y, tempNumRoi.w, tempNumRoi.h);

                ctx.fillStyle = "#ffc107";

                ctx.font = "bold 16px sans-serif";

                ctx.fillText(`문항 ${getNextQuestionNumberForActivePage()}`, tempNumRoi.x, tempNumRoi.y - 5);

                drawHandles(tempNumRoi);

            }

        }



        function renderSidebar() {

            roiListContainer.innerHTML = "";

            let currentTotal = 0;

            const currentPg = pages[activePageIndex];



            rois.forEach((r, idx) => {

                currentTotal += (r.score || 0);

                const displayType = getDisplayType(r);

                const isSubItem = (r.type === 'sub_item');


                const displayLabel = r.display_num ? `Q${r.display_num}` : `Q${r.id}`;

                const isCurrentPage = currentPg && (r.page_uid === currentPg.page_uid || r.page === currentPg.page_num);

                const isFocused = (selectedRoiIndex === idx);



                // 현재 추가 모드 진행 중인 문항인지 확인

                const targetAddRoi = (addAnsTargetIndex !== null) ? rois[addAnsTargetIndex] : null;

                const isAddingThisItem = targetAddRoi && (

                    targetAddRoi.id === r.id ||

                    (targetAddRoi.num_roi && r.num_roi && targetAddRoi.page_uid === r.page_uid && Math.round(targetAddRoi.num_roi.x) === Math.round(r.num_roi.x) && Math.round(targetAddRoi.num_roi.y) === Math.round(r.num_roi.y))

                );



                const div = document.createElement('div');

                div.className = `roi-item ${displayType} ${isCurrentPage ? '' : 'other-page'} ${isFocused ? 'selected-focus' : ''} ${isAddingThisItem ? 'adding-sub-mode' : ''}`;

                div.draggable = true;

                div.dataset.index = idx;

                if (isFocused || isAddingThisItem) {

                    setTimeout(() => div.scrollIntoView({ block: 'nearest', behavior: 'smooth' }), 50);

                }



                // 소문항 그룹의 마지막 소문항인지 확인

                const isLastSubInGroup = isSubItem && (

                    idx === rois.length - 1 || rois[idx + 1].type !== 'sub_item' || rois[idx + 1].id !== r.id

                );

                const subGroupCount = isSubItem ? rois.filter(rx => rx.type === 'sub_item' && rx.id === r.id).length : 0;



                const addSubBtn = (isSubItem && isLastSubInGroup)

                    ? `<button class="btn-add-sub" onclick="event.stopPropagation(); startAddAnsRoiMode(${idx})">➕ 소문항</button>`

                    : '';



                // 플레이스홀더 설정

                let answerPlaceholder = '정답 번호 (예: 3)';

                if (displayType === 'matching_line') {

                    answerPlaceholder = '선잇기 정답 (예: (1)-㉢, (2)-㉡, (3)-㉠ 또는 1-ㄷ, 2-ㄴ, 3-ㄱ)';

                } else if (displayType === 'essay') {

                    answerPlaceholder = '서술형 모범 답안 키워드 / 문장';

                } else if (displayType === 'short_answer') {

                    answerPlaceholder = isSubItem ? '소문항 정답' : '단답형 정답 또는 OX (예: 가치 또는 ○)';

                }



                const addingGuideHtml = isAddingThisItem

                    ? `<div class="adding-sub-guide">✏️ 시험지 위에 추가할 소문항을 드래그하세요 (완료: 휠 / ESC)</div>`

                    : '';



                // 4대 문항 유형 칩 HTML

                const typeToggleHtml = `

                    <div class="type-toggle">

                        <button class="type-btn ${displayType === 'objective' ? 'active obj' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'objective')">객관식</button>

                        <button class="type-btn ${displayType === 'short_answer' ? 'active short' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'short_answer')">단답/OX</button>

                        <button class="type-btn ${displayType === 'matching_line' ? 'active matching' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'matching_line')">선잇기</button>

						<button class="type-btn ${displayType === 'subjective' ? 'active subj' : ''}" onclick="event.stopPropagation(); setType(${idx}, 'subjective')">주관식</button>

                    </div>

                `;



                // 서술형 채점 기준 배너

                let criteriaHtml = '';

                if (displayType === 'subjective' && r.criteria && r.criteria.trim().length > 0) {

                    criteriaHtml = `

                        <div class="criteria-banner" onclick="event.stopPropagation()">

                            <span class="criteria-icon">📋</span>

                            <div class="criteria-text"><strong>채점 기준:</strong> ${escapeHtml(r.criteria)}</div>

                        </div>

                    `;

                }



                // 선 잇기 특화 섹션

                let matchingHtml = '';

                if (displayType === 'matching_line') {

                    const pairs = parseMatchingPairs(r.answer || '');

                    let pairsChipsHtml = '';

                    if (pairs.length > 0) {

                        pairsChipsHtml = pairs.map((p) => `<span class="pair-chip">🔗 ${escapeHtml(p.from)} ➔ ${escapeHtml(p.to)}</span>`).join('');

                    } else {

                        pairsChipsHtml = `<span class="pair-chip-empty">정답 예시: (1)-㉢, (2)-㉡, (3)-㉠ 또는 1-ㄷ, 2-ㄴ, 3-ㄱ</span>`;

                    }



                    let partialSection = '';

                    if (r.has_partial_score) {

                        const subScoreItems = pairs.map((p, pIdx) => {

                            const pairKey = String(pIdx + 1);

                            const pairScore = (r.sub_scores && r.sub_scores[pairKey] !== undefined)

                                ? r.sub_scores[pairKey]

                                : Math.max(1, Math.floor((r.score || pairs.length) / pairs.length));

                            return `

                                <div class="sub-score-item">

                                    <span class="pair-label">${escapeHtml(p.from)}➔${escapeHtml(p.to)}:</span>

                                    <div>

                                        <input type="number" min="0" step="0.5" class="sub-score-input"

                                               value="${pairScore}"

                                               onchange="updateSubScore(${idx}, '${pairKey}', this.value)"> 점

                                    </div>

                                </div>

                            `;

                        }).join('');



                        partialSection = `

                            <div class="partial-score-box">

                                <div class="sub-scores-grid">

                                    ${subScoreItems || '<span style="font-size:11px; color:#94a3b8;">정답을 입력하면 연결선별 배점란이 생성됩니다.</span>'}

                                </div>

                                <div class="sub-score-total-bar">

                                    <span>✨ 합산 총점: <strong>${r.score}점</strong></span>

                                    <span style="font-size:10.5px; color:#94a3b8;">(각 연결선 점수 합계가 문항 배점에 자동 반영)</span>

                                </div>

                            </div>

                        `;

                    } else {

                        partialSection = `

                            <div style="margin-top:5px; font-size:11px; color:#94a3b8;">

                                ℹ️ <strong>All-or-Nothing</strong>: 모든 연결선이 일치할 때만 만점(${r.score}점) 인정

                            </div>

                        `;

                    }



                    const curDir = (r.matching_info && r.matching_info.direction) ? r.matching_info.direction : ((r.matching_direction && r.matching_direction !== 'auto') ? r.matching_direction : ((r.ans_roi && r.ans_roi.h > r.ans_roi.w * 0.75) ? 'vertical' : 'horizontal'));

                    const aiAnalyzed = Boolean(r.matching_info && r.matching_info.source_dots && r.matching_info.source_dots.length > 0);



                    matchingHtml = `

                        <div class="matching-box" onclick="event.stopPropagation()">

                            <div style="display:flex; justify-content:space-between; align-items:center;">

                                <span style="font-size:11.5px; font-weight:700; color:#5eead4;">🔗 선잇기 연결선 미리보기</span>

                                <label class="partial-score-label">

                                    <input type="checkbox" ${r.has_partial_score ? 'checked' : ''} onchange="togglePartialScore(${idx}, this.checked)">

                                    <span>부분 점수 인정</span>

                                </label>

                            </div>

                            <div class="matching-status-bar">

                                <span class="badge-ai-status">✨ AI 분석 완료 (${curDir === 'vertical' ? '세로형: 위 ➔ 아래' : '가로형: 좌 ➔ 우'})</span>

                                <span class="badge-dot-hint">💡 캔버스에서 점 드래그 이동</span>

                            </div>

                            <div class="matching-diagram-thumb-wrap">

                                <div class="thumb-header">

                                    <span>🖼️ 문제 다이어그램 크롭 & 연결선</span>

                                    <span class="thumb-badge">${curDir === 'vertical' ? '세로형' : '가로형'}</span>

                                </div>

                                <canvas id="crop-diagram-${idx}" class="matching-diagram-canvas" width="280" height="120"></canvas>

                            </div>
                            ${partialSection}

                        </div>

                    `;

                }



                // 단답/OX 복수 답안 부분 점수 설정

                let shortPartialHtml = '';

                if (displayType === 'short_answer' && (r.answer && r.answer.includes(','))) {

                    const tokens = (r.answer || '').split(/[,;]+/).map(s => s.trim()).filter(Boolean);

                    if (tokens.length >= 2) {

                        const count = tokens.length;

                        let partialSection = '';

                        if (r.has_partial_score) {

                            const subItems = [];

                            for (let t = 0; t < count; t++) {

                                const subKey = String(t + 1);

                                const labelName = tokens[t] ? `(${t + 1}) ${tokens[t]}` : `답란 ${t + 1}`;

                                const subScore = (r.sub_scores && r.sub_scores[subKey] !== undefined)

                                    ? r.sub_scores[subKey]

                                    : Math.max(1, Math.floor((r.score || count) / count));

                                subItems.push(`

                                    <div class="sub-score-item">

                                        <span class="pair-label">${escapeHtml(labelName)}:</span>

                                        <div>

                                            <input type="number" min="0" step="0.5" class="sub-score-input"

                                                   value="${subScore}"

                                                   onchange="updateSubScore(${idx}, '${subKey}', this.value)"> 점

                                        </div>

                                    </div>

                                `);

                            }

                            partialSection = `

                                <div class="partial-score-box">

                                    <div class="sub-scores-grid">

                                        ${subItems.join('')}

                                    </div>

                                    <div class="sub-score-total-bar">

                                        <span>✨ 합산 총점: <strong>${r.score}점</strong></span>

                                    </div>

                                </div>

                            `;

                        } else {

                            partialSection = `

                                <div style="margin-top:5px; font-size:11px; color:#94a3b8;">

                                    ℹ️ <strong>All-or-Nothing</strong>: 모든 답란이 일치할 때만 만점(${r.score}점) 인정

                                </div>

                            `;

                        }



                        shortPartialHtml = `

                            <div class="partial-score-box" onclick="event.stopPropagation()">

                                <div style="display:flex; justify-content:space-between; align-items:center;">

                                    <span style="font-size:11.5px; font-weight:700; color:#fbbf24;">📝 복수 답안 설정</span>

                                    <label class="partial-score-label">

                                        <input type="checkbox" ${r.has_partial_score ? 'checked' : ''} onchange="togglePartialScore(${idx}, this.checked)">

                                        <span>부분 점수 인정</span>

                                    </label>

                                </div>

                                ${partialSection}

                            </div>

                        `;

                    }

                }



                div.innerHTML = `

                    <div class="roi-item-main-row">

                        <div class="roi-row-left">

                            <span class="roi-page-tag">${r.page}쪽</span>

                            <span class="roi-title">${displayLabel}</span>

                        </div>

                        ${typeToggleHtml}

                        <div class="roi-row-right">

                            <div class="score-wrap" onclick="event.stopPropagation()">

                                <input type="number" class="input-score" value="${r.score}" 

                                       onchange="updateIndividualScore(${idx}, this.value)" 

                                       ${r.has_partial_score ? 'title="부분 점수 합산 반영 중"' : ''}> 점

                            </div>

                            ${addSubBtn}

                            <button class="btn-delete" title="문항 삭제" onclick="event.stopPropagation(); deleteRoi(${idx})">🗑️</button>

                        </div>

                    </div>

                    ${addingGuideHtml}

                    <div class="roi-item-answer-row">

                        <input type="text" class="input-answer" 

                               placeholder="${answerPlaceholder}" 

                               value="${escapeHtml(r.answer || '')}" 

                               onclick="event.stopPropagation()"

                               oninput="handleAnswerInput(${idx}, this.value)"

                               onchange="handleAnswerChange(${idx}, this.value)">

                    </div>

                    ${matchingHtml}

                    ${shortPartialHtml}

                    ${criteriaHtml}

                `;



                // 문항 카드 클릭 시: 해당 문항 페이지로 이동 및 세트 포커스 토글

                div.onclick = () => {

                    if (addAnsTargetIndex !== null && !isAddingThisItem) {

                        addAnsTargetIndex = null;

                        reindexRois();

                    }

                    const targetPageIdx = pages.findIndex(p => p.page_uid === r.page_uid || p.page_num === r.page);

                    const willSelect = (selectedRoiIndex !== idx);

                    if (targetPageIdx >= 0 && targetPageIdx !== activePageIndex) {

                        switchPage(targetPageIdx);

                    }

                    selectedRoiIndex = willSelect ? idx : null;

                    draw();

                    renderSidebar();

                };



                div.addEventListener('dragstart', handleDragStart);

                div.addEventListener('dragover', handleDragOver);

                div.addEventListener('drop', handleDrop);



                roiListContainer.appendChild(div);

            });



            const baseScore = targetScore - currentTotal;

            const baseScoreEl = document.getElementById('summaryBaseScore');



            document.getElementById('summaryCount').innerText = rois.length;

            document.getElementById('summaryTotalScore').innerText = currentTotal;



            if (baseScore < 0) {

                baseScoreEl.className = 'base-score exceeded';

                baseScoreEl.innerText = `초과 (${baseScore}점)`;

            } else {

                baseScoreEl.className = 'base-score';

                baseScoreEl.innerText = `${baseScore}점`;

            }



            // 선잇기 문항 크롭 썸네일 및 미니 캔버스 비동기 렌더링

            setTimeout(renderMatchingCropPreviews, 60);

        }



        // 사이드바 내 선잇기 문항 다이어그램 크롭 및 소문항/답안 페어 미니 캔버스 렌더러

function renderMatchingCropPreviews() {



            rois.forEach((r, idx) => {



                if (getDisplayType(r) !== 'matching_line' || !r.ans_roi) return;







                // 해당 문항이 속한 페이지 이미지 정확히 획득



                const targetPageIdx = pages.findIndex(p => p.page_uid === r.page_uid || p.page_num === r.page);



                const roiImg = (targetPageIdx >= 0 && pageImages[targetPageIdx] && pageImages[targetPageIdx].complete && pageImages[targetPageIdx].naturalWidth > 0)



                    ? pageImages[targetPageIdx]



                    : ((pageImages[activePageIndex] && pageImages[activePageIndex].complete) ? pageImages[activePageIndex] : null);







                if (!roiImg) return;







                // 1. 다이어그램 전체 크롭 캔버스 렌더링



                const diagCanvas = document.getElementById(`crop-diagram-${idx}`);



                if (diagCanvas) {



                    const dCtx = diagCanvas.getContext('2d');



                    const ar = r.ans_roi;



                    diagCanvas.width = diagCanvas.clientWidth || 280;



                    diagCanvas.height = diagCanvas.clientHeight || 120;







                    // 배경을 깔끔한 흰색으로 초기화



                    dCtx.fillStyle = '#ffffff';



                    dCtx.fillRect(0, 0, diagCanvas.width, diagCanvas.height);







                    // 시험지 이미지에서 문항 ROI 영역을 안전하게 크롭하여 그리기



                    const imgW = roiImg.naturalWidth;



                    const imgH = roiImg.naturalHeight;



                    const sx = Math.max(0, Math.min(imgW - 10, ar.x));



                    const sy = Math.max(0, Math.min(imgH - 10, ar.y));



                    const sw = Math.max(10, Math.min(imgW - sx, ar.w));



                    const sh = Math.max(10, Math.min(imgH - sy, ar.h));







                    try {



                        dCtx.drawImage(roiImg, sx, sy, sw, sh, 0, 0, diagCanvas.width, diagCanvas.height);



                    } catch (e) {



                        console.warn('[renderMatchingCropPreviews] drawImage failed', e);



                    }







                    // 연결선들을 이 크롭 캔버스 비율에 맞추어 오버레이



                    const pairs = parseMatchingPairs(r.answer || '');



                    const dir = (r.matching_info && r.matching_info.direction) ? r.matching_info.direction : ((r.matching_direction && r.matching_direction !== 'auto') ? r.matching_direction : ((ar.h > ar.w * 0.75) ? 'vertical' : 'horizontal'));



                    const isVertical = (dir === 'vertical');



                    const pairColors = ['#0284c7', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#ea580c'];







                    const sDots = (r.matching_info && Array.isArray(r.matching_info.source_dots)) ? r.matching_info.source_dots : [];



                    const tDots = (r.matching_info && Array.isArray(r.matching_info.target_dots)) ? r.matching_info.target_dots : [];



                    const cleanLabel = (s) => String(s || '').replace(/[\(\)\（\）\s]/g, '');







                    pairs.forEach((p, pIdx) => {



                        const pCol = pairColors[pIdx % pairColors.length];



                        const fromCl = cleanLabel(p.from);



                        const toCl = cleanLabel(p.to);







                        let startPt = null;



                        const matchedSDot = sDots.find(d => cleanLabel(d.label) === fromCl || cleanLabel(d.label) === String(pIdx + 1));



                        if (matchedSDot) {



                            const nx = (matchedSDot.roi_norm_x !== undefined) ? matchedSDot.roi_norm_x : matchedSDot.norm_x;



                            const ny = (matchedSDot.roi_norm_y !== undefined) ? matchedSDot.roi_norm_y : matchedSDot.norm_y;



                            startPt = { x: nx * diagCanvas.width, y: ny * diagCanvas.height };



                        } else {



                            startPt = isVertical



                                ? { x: diagCanvas.width * ((pIdx + 0.5) / pairs.length), y: diagCanvas.height * 0.28 }



                                : { x: diagCanvas.width * 0.2, y: diagCanvas.height * ((pIdx + 0.5) / pairs.length) };



                        }







                        let endPt = null;



                        const matchedTDot = tDots.find(d => cleanLabel(d.label) === toCl);



                        if (matchedTDot) {



                            const nx = (matchedTDot.roi_norm_x !== undefined) ? matchedTDot.roi_norm_x : matchedTDot.norm_x;



                            const ny = (matchedTDot.roi_norm_y !== undefined) ? matchedTDot.roi_norm_y : matchedTDot.norm_y;



                            endPt = { x: nx * diagCanvas.width, y: ny * diagCanvas.height };



                        } else {



                            const allTargets = Array.from(new Set(pairs.map(item => item.to))).sort();



                            let tIdx = allTargets.indexOf(p.to);



                            if (tIdx < 0) tIdx = pIdx;



                            const tCount = Math.max(allTargets.length, pairs.length);



                            endPt = isVertical



                                ? { x: diagCanvas.width * ((tIdx + 0.5) / tCount), y: diagCanvas.height * 0.85 }



                                : { x: diagCanvas.width * 0.8, y: diagCanvas.height * ((tIdx + 0.5) / tCount) };



                        }







                        // 선 그리기



                        dCtx.save();



                        dCtx.strokeStyle = pCol;



                        dCtx.lineWidth = 2.2;



                        dCtx.beginPath();



                        dCtx.moveTo(startPt.x, startPt.y);



                        dCtx.lineTo(endPt.x, endPt.y);



                        dCtx.stroke();







                        // 시작점 원



                        dCtx.beginPath();



                        dCtx.arc(startPt.x, startPt.y, 3, 0, Math.PI * 2);



                        dCtx.fillStyle = pCol;



                        dCtx.fill();



                        dCtx.strokeStyle = "#fff";



                        dCtx.lineWidth = 1;



                        dCtx.stroke();







                        // 도착점 화살표



                        const angle = Math.atan2(endPt.y - startPt.y, endPt.x - startPt.x);



                        dCtx.beginPath();



                        dCtx.moveTo(endPt.x, endPt.y);



                        dCtx.lineTo(endPt.x - 7 * Math.cos(angle - Math.PI / 7), endPt.y - 7 * Math.sin(angle - Math.PI / 7));



                        dCtx.lineTo(endPt.x - 7 * Math.cos(angle + Math.PI / 7), endPt.y - 7 * Math.sin(angle + Math.PI / 7));



                        dCtx.closePath();



                        dCtx.fillStyle = pCol;



                        dCtx.fill();



                        dCtx.restore();



                    });



                }







                });



        }









        function updateIndividualScore(idx, val) {

            const r = rois[idx];

            if (!r) return;

            const newScore = parseInt(val) || 0;

            r.score = newScore;

            if (r.has_partial_score) {

                if (getDisplayType(r) === 'matching_line') {

                    const pairs = parseMatchingPairs(r.answer || '');

                    if (pairs.length > 0) {

                        const base = Math.max(1, Math.floor(newScore / pairs.length));

                        let rem = newScore;

                        pairs.forEach((p, i) => {

                            const k = String(i + 1);

                            const s = (i === pairs.length - 1) ? rem : base;

                            r.sub_scores[k] = s;

                            rem -= s;

                        });

                    }

                }

            }

            renderSidebar();

        }



        function applyTargetScore() {

            const val = parseInt(document.getElementById('targetTotalScore').value);

            if (isNaN(val) || val < 0) {

                alert('올바른 만점 점수를 입력해주세요.');

                return;

            }

            targetScore = val;

            renderSidebar();

        }



        function applyBatchAll() {

            const inputVal = document.getElementById('batchAllVal').value;

            if (inputVal === '') {

                alert('일괄 적용할 점수를 입력해주세요.');

                return;

            }

            const scoreVal = parseInt(inputVal) || 0;

            if (rois.length === 0) return;

            pushUndoState();

            rois.forEach(r => r.score = scoreVal);

            renderSidebar();

        }



        function applyBatchType() {

            const objInput = document.getElementById('batchObjVal').value;

            const subjInput = document.getElementById('batchSubjVal').value;



            if (objInput === '' && subjInput === '') {

                alert('적용할 객관식 또는 주관식 점수를 입력해주세요.');

                return;

            }



            if (rois.length === 0) return;



            pushUndoState();

            rois.forEach(r => {

                if (r.type === 'objective' && objInput !== '') {

                    r.score = parseInt(objInput) || 0;

                } else if (r.type === 'subjective' && subjInput !== '') {

                    r.score = parseInt(subjInput) || 0;

                }

            });

            renderSidebar();

        }



        let dragSrcIndex = null;

        function handleDragStart(e) {

            dragSrcIndex = Number(this.dataset.index);

            e.dataTransfer.effectAllowed = 'move';

        }



        function handleDragOver(e) {

            e.preventDefault();

            e.dataTransfer.dropEffect = 'move';

        }



        function handleDrop(e) {

            e.preventDefault();

            const dropTargetIndex = Number(this.dataset.index);



            if (dragSrcIndex !== null && dragSrcIndex !== dropTargetIndex) {

                pushUndoState();

                const movedItem = rois.splice(dragSrcIndex, 1)[0];

                rois.splice(dropTargetIndex, 0, movedItem);

                reindexRois();

                draw();

                renderSidebar();

            }

        }



        // 페이지 관리 모달 제어

        function openPageManager() {

            renderModalPageList();

            document.getElementById('pageModal').style.display = 'flex';

        }



        function closePageManager() {

            document.getElementById('pageModal').style.display = 'none';

            renderPageTabs();

            loadPage(activePageIndex);

            draw();

            renderSidebar();

        }



        function renderModalPageList() {

            const listEl = document.getElementById('modalPageList');

            listEl.innerHTML = "";



            pages.forEach((page, idx) => {

                const count = rois.filter(r => r.page_uid === page.page_uid).length;

                const row = document.createElement('div');

                row.className = "modal-page-row";



                row.innerHTML = `

                    <div class="modal-page-info">

                        <img src="${page.image_path}" class="modal-page-thumb" alt="${page.page_num}쪽">

                        <div>

                            <div style="font-weight:700; color:#fff;">${page.page_num}쪽 (${page.title || (page.page_num + '쪽')})</div>

                            <div style="font-size:12px; color:#888;">설정된 문항: ${count}개</div>

                        </div>

                    </div>

                    <div class="modal-page-actions">

                        ${idx > 0 ? `<button type="button" class="btn-modal-action" onclick="movePageOrder(${idx}, -1)">▲ 위로</button>` : ''}

                        ${idx < pages.length - 1 ? `<button type="button" class="btn-modal-action" onclick="movePageOrder(${idx}, 1)">▼ 아래로</button>` : ''}

                        ${pages.length > 1 ? `<button type="button" class="btn-modal-action btn-del" onclick="deleteModalPage(${idx})">🗑️ 삭제</button>` : ''}

                    </div>

                `;

                listEl.appendChild(row);

            });

        }



        // 페이지 순서 재정렬 (page_uid를 통해 ROI 무결성 100% 보존!)

        function movePageOrder(idx, dir) {

            const targetIdx = idx + dir;

            if (targetIdx < 0 || targetIdx >= pages.length) return;



            const moved = pages.splice(idx, 1)[0];

            pages.splice(targetIdx, 0, moved);



            // page_num 재정렬

            pages.forEach((p, pIdx) => {

                p.page_num = pIdx + 1;

                p.title = `${p.page_num}쪽`;

            });



            // rois의 표시용 page 번호 동기화 (page_uid는 영구 보존)

            rois.forEach(r => {

                const matched = pages.find(p => p.page_uid === r.page_uid);

                if (matched) {

                    r.page = matched.page_num;

                }

            });



            // 활성 페이지 인덱스 보정

            if (activePageIndex === idx) activePageIndex = targetIdx;

            else if (activePageIndex === targetIdx) activePageIndex = idx;



            pageImages = {}; // 이미지 캐시 리셋

            renderModalPageList();

        }



        function deleteModalPage(idx) {

            const targetPg = pages[idx];

            const qCount = rois.filter(r => r.page_uid === targetPg.page_uid).length;



            let msg = `${targetPg.page_num}쪽 페이지를 삭제하시겠습니까?`;

            if (qCount > 0) {

                msg = `⚠️ 주의: ${targetPg.page_num}쪽에 설정된 ${qCount}개의 문항이 함께 삭제됩니다. 정말 삭제하시겠습니까?`;

            }



            if (confirm(msg)) {

                // 해당 페이지의 ROI 제거

                rois = rois.filter(r => r.page_uid !== targetPg.page_uid);

                reindexRois();



                pages.splice(idx, 1);

                pages.forEach((p, pIdx) => {

                    p.page_num = pIdx + 1;

                    p.title = `${p.page_num}쪽`;

                });



                rois.forEach(r => {

                    const matched = pages.find(p => p.page_uid === r.page_uid);

                    if (matched) r.page = matched.page_num;

                });



                if (activePageIndex >= pages.length) {

                    activePageIndex = pages.length - 1;

                }



                pageImages = {};

                renderModalPageList();

            }

        }



        // 최종 템플릿 저장 (Contract A, B 동시 전송)

        function saveTemplate() {

            if (rois.length === 0) {

                alert('설정된 ROI가 없습니다. 영역을 먼저 지정해주세요.');

                return;

            }



            fetch('/api/templates/save.php', {

                method: 'POST',

                headers: { 'Content-Type': 'application/json' },

                body: JSON.stringify({

                    template_id: templateId,

                    roi_data: rois,

                    pages_data: pages,

                    privacy_mask_data: privacyMaskRois

                })

            })

            .then(res => res.json())

            .then(data => {

                if (data.success) {

                    alert('템플릿 설정(페이지 및 ROI)이 성공적으로 저장되었습니다.');

                    location.href = '/views/dashboard/main.php';

                } else {

                    alert('저장 실패: ' + (data.error || data.message || '알 수 없는 오류'));

                }

            })

            .catch(err => alert('서버 통신 오류가 발생했습니다: ' + err.message));

        }



        // AI 자동 완성 다시 받기 API 호출

        async function reExtractAi() {

            if (rois.length > 0) {

                if (!confirm("기존에 설정된 문항 및 ROI 영역이 Upstage AI 자동 인식 결과로 덮어씌워집니다. 계속하시겠습니까?")) {

                    return;

                }

            }



            showToast("✨ Upstage AI가 시험지 문항과 영역을 정밀하게 다시 추출하고 있습니다...", 6000);



            try {

                const response = await fetch('/api/templates/auto_extract.php', {

                    method: 'POST',

                    headers: { 'Content-Type': 'application/json' },

                    body: JSON.stringify({ template_id: templateId })

                });

                const data = await response.json();



                if (data.success) {

                    if (Array.isArray(data.privacy_mask_rois)) {

                        privacyMaskRois = data.privacy_mask_rois;

                    }

                    rois = (Array.isArray(data.rois) ? data.rois : []).map((r, i) => {

                        const pageNum = parseInt(r.page) || 1;

                        const matchedPage = pages.find(p => p.page_uid === r.page_uid) || pages[pageNum - 1] || pages[0];

                        const rawId = r.id || (i + 1);

                        const subId = r.sub_id || null;

                        const displayNum = r.display_num || (subId ? `${rawId}-${subId}` : `${rawId}`);

                        return {

                            id: rawId,

                            sub_id: subId,

                            display_num: displayNum,

                            page_uid: r.page_uid || (matchedPage ? matchedPage.page_uid : 'p_1_default'),

                            page: matchedPage ? matchedPage.page_num : pageNum,

                            num_roi: r.num_roi || { x: 50, y: 50, w: 50, h: 30 },

                            ans_roi: r.ans_roi || { x: 110, y: 50, w: 60, h: 35 },

                            ans_rois: Array.isArray(r.ans_rois) ? r.ans_rois : null,

                            type: r.type || 'objective',

                            sub_type: r.sub_type || r.type || 'objective',

                            answer: r.answer || '',

                            score: typeof r.score === 'number' ? r.score : 0,

                            has_partial_score: Boolean(r.has_partial_score),

                            sub_scores: (typeof r.sub_scores === 'object' && r.sub_scores !== null) ? r.sub_scores : {},

                            criteria: r.criteria || '',

                            matching_direction: r.matching_direction || 'auto'

                        };

                    });

                    draw();

                    renderSidebar();

                    renderPageTabs();

                    showToast(`🎉 총 ${rois.length}개 문항의 AI 자동 완성이 완료되었습니다!`, 4000);

                } else {

                    alert("AI 자동 완성 실패: " + (data.error || "문항을 추출하지 못했습니다."));

                }

            } catch (err) {

                alert("서버 통신 오류: " + err.message);

            }

        }



        // 전체 문항 ROI 초기화

        function resetAllRois() {

            if (rois.length === 0) return;

            if (confirm("모든 문항 사각형(ROI)을 초기화하시겠습니까? 처음부터 직접 그릴 수 있습니다.")) {

                pushUndoState();

                rois = [];

                tempNumRoi = null;

                currentStage = 'NUM';

                selectedRoiIndex = null;

                draw();

                renderSidebar();

                renderPageTabs();

                showToast("전체 문항 영역이 초기화되었습니다.");

            }

        }



        // 알림 토스트 표시

        function showToast(msg, duration = 3500) {

            const existing = document.querySelector('.toast-banner');

            if (existing) existing.remove();



            const toast = document.createElement('div');

            toast.className = 'toast-banner';

            toast.innerHTML = `<span>🔔</span> <span>${msg}</span>`;

            document.body.appendChild(toast);



            setTimeout(() => {

                if (toast.parentNode) {

                    toast.style.transition = 'opacity 0.4s';

                    toast.style.opacity = '0';

                    setTimeout(() => toast.remove(), 400);

                }

            }, duration);

        }



        // 초기 로드 시 ?ai=1 안내 토스트

        window.addEventListener('DOMContentLoaded', () => {

            const urlParams = new URLSearchParams(window.location.search);

            if (urlParams.get('ai') === '1' && rois.length > 0) {

                showToast(`✨ Upstage AI가 총 ${rois.length}개 문항과 정답 영역을 자동 완성했습니다! 영역 크기를 조절하거나 정답을 입력하세요.`, 6000);

            }

        });

    

        // [Phase 2] 정답표(이원목적분류표) 모달 관리

        let ansKeyActiveTab = 'file';

        let modalSelectedAnsKeyFile = null;



        function openAnswerKeyModal() {

            const m = document.getElementById('answerKeyModal');

            if (m) m.style.display = 'flex';

            initAnsKeyModalHandlers();

        }



        function closeAnswerKeyModal() {

            const m = document.getElementById('answerKeyModal');

            if (m) m.style.display = 'none';

        }



        function switchAnsKeyTab(tab) {

            ansKeyActiveTab = tab;

            const tabBtnFile = document.getElementById('tabBtnFile');

            const tabBtnText = document.getElementById('tabBtnText');

            const fileTab = document.getElementById('ansKeyFileTab');

            const textTab = document.getElementById('ansKeyTextTab');



            if (tab === 'file') {

                tabBtnFile.style.background = '#0284c7';

                tabBtnFile.style.color = '#fff';

                tabBtnText.style.background = '#f1f5f9';

                tabBtnText.style.color = '#475569';

                fileTab.style.display = 'block';

                textTab.style.display = 'none';

            } else {

                tabBtnText.style.background = '#0284c7';

                tabBtnText.style.color = '#fff';

                tabBtnFile.style.background = '#f1f5f9';

                tabBtnFile.style.color = '#475569';

                textTab.style.display = 'block';

                fileTab.style.display = 'none';

            }

        }



        let ansKeyDropzoneInit = false;

        function initAnsKeyModalHandlers() {

            if (ansKeyDropzoneInit) return;

            ansKeyDropzoneInit = true;



            const dz = document.getElementById('ansKeyModalDropzone');

            const fi = document.getElementById('ansKeyModalFileInput');

            if (!dz || !fi) return;



            dz.addEventListener('click', () => fi.click());

            fi.addEventListener('change', (e) => {

                if (e.target.files && e.target.files[0]) {

                    setModalAnsKeyFile(e.target.files[0]);

                }

            });



            dz.addEventListener('dragover', (e) => {

                e.preventDefault();

                dz.style.borderColor = '#0284c7';

                dz.style.background = '#f0f9ff';

            });

            dz.addEventListener('dragleave', () => {

                dz.style.borderColor = '#cbd5e1';

                dz.style.background = '#f8fafc';

            });

            dz.addEventListener('drop', (e) => {

                e.preventDefault();

                dz.style.borderColor = '#cbd5e1';

                dz.style.background = '#f8fafc';

                if (e.dataTransfer.files && e.dataTransfer.files[0]) {

                    setModalAnsKeyFile(e.dataTransfer.files[0]);

                }

            });

        }



        function setModalAnsKeyFile(file) {

            modalSelectedAnsKeyFile = file;

            const t = document.getElementById('ansKeyModalFileText');

            const st = document.getElementById('ansKeyModalFileSubtext');

            if (t) t.innerHTML = `✅ 선택된 파일: <strong style="color:#0284c7;">${file.name}</strong>`;

            if (st) st.innerHTML = `크기: ${(file.size / 1024).toFixed(1)} KB (준비 완료)`;

        }



        async function submitAnswerKeyModal() {

            const btn = document.getElementById('btnSubmitAnsKey');

            const formData = new FormData();

            formData.append('template_id', templateId);



            if (ansKeyActiveTab === 'file') {

                if (!modalSelectedAnsKeyFile) {

                    alert('이원목적분류표 PDF 또는 이미지 파일을 선택해주세요.');

                    return;

                }

                formData.append('answer_key_file', modalSelectedAnsKeyFile, modalSelectedAnsKeyFile.name);

            } else {

                const ta = document.getElementById('ansKeyModalTextarea');

                const txt = ta ? ta.value.trim() : '';

                if (!txt) {

                    alert('정답표 텍스트 또는 표 내용을 입력해주세요.');

                    return;

                }

                formData.append('answer_key_text', txt);

            }



            btn.disabled = true;

            btn.innerText = '⏳ AI 분석 중...';



            try {

                const res = await fetch('/api/templates/parse_answer_key.php', {

                    method: 'POST',

                    body: formData

                });

                const data = await res.json();



                if (!data.success) {

                    alert(data.error || '정답표 분석에 실패했습니다.');

                    return;

                }



                if (Array.isArray(data.data.bound_rois) && data.data.bound_rois.length > 0) {

                    rois = data.data.bound_rois;

                    pushUndoState();

                    draw();

                    renderSidebar();

                    closeAnswerKeyModal();

                    showToast(`✨ 총 ${data.data.parsed_count}개 문항의 정답과 배점이 100% 자동 적용되었습니다!`, 4500);

                } else {

                    alert('정답표에서 문항 데이터를 찾았으나 기존 시험지 문항 번호와 매칭되지 않았습니다.');

                }

            } catch (err) {

                alert('서버 통신 오류가 발생했습니다: ' + err.message);

            } finally {

                btn.disabled = false;

                btn.innerText = '🚀 정답표 분석 및 적용';

            }

        }



        // 상단 툴바 개인정보 마스킹 버튼 핸들러

        function handleMaskToolClick() {

            if (activePageIndex !== 0) {

                switchPage(0);

            }

            if (!privacyMaskRois || privacyMaskRois.length === 0) {

                const defW = Math.round(canvas.width * 0.38);

                const defH = 90;

                const defX = Math.round(canvas.width - defW - 30);

                const defY = 30;

                privacyMaskRois = [{

                    page: 1,

                    x: defX,

                    y: defY,

                    w: defW,

                    h: defH,

                    label: 'student_info'

                }];

                selectedMaskIndex = 0;

                selectedRoiIndex = null;

                draw();

                showToast("🛡️ 1쪽 상단에 개인정보 마스킹 영역이 생성되었습니다. 드래그하여 크기를 조절하세요.");

            } else {

                selectedMaskIndex = 0;

                selectedRoiIndex = null;

                draw();

                showToast("🛡️ 개인정보 마스킹 영역이 선택되었습니다. 드래그하여 위치/크기를 조절하거나 [Delete]로 삭제할 수 있습니다.");

            }

        }

    </script>

</body>

</html>

