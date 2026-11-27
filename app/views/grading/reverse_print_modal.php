<?php
/**
 * views/grading/reverse_print_modal.php
 * 쌤글라스(SsamGlass) 오버레이 투명 PDF 역인쇄(Overprinting) 3단계 안전 인터로킹 모달 컴포넌트
 */
?>
<!-- 역인쇄 안전 인터로킹 모달 통합 컨테이너 -->
<div id="rpModalBackdrop" class="rp-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="rpModalTitle">

    <!-- ======================================================================
         Stage 1: 손실 프레이밍 경고 모달 (Loss-Framing Warning)
         ====================================================================== -->
    <div id="rpCardStage1" class="rp-modal-container rp-card-stage1">
        <div class="rp-modal-header">
            <h3 class="rp-modal-title">
                <span>⚠️</span> 경고: 학생 원본 시험지 [ <span id="rpStudentCountStage1" class="rp-count-highlight">0장</span> ] 인쇄 대기 중
            </h3>
            <button type="button" class="rp-modal-close" onclick="ReversePrintEngine.closeModal()" title="닫기">&times;</button>
        </div>
        <div class="rp-modal-body">
            <div class="rp-danger-banner">
                <div class="rp-danger-alert-text">
                    급지 방향이 틀리면 [<span id="rpStudentCountText" style="font-weight: 800;">0명 전원</span>]의 원본 시험지가 돌이킬 수 없게 영구 훼손됩니다!
                </div>
                <div style="font-size: 13.5px; color: #7f1d1d; margin-top: 4px;">
                    (시험지에 빨간 펜 채점 자국이 반대로 찍히면 화학적으로 지울 수 없습니다)
                </div>
            </div>

            <p style="font-size: 15px; font-weight: 600; color: #0f172a; margin: 16px 0 6px 0;">
                👉 시험지를 프린터에 넣기 전에<br>
                <strong>10초 테스트</strong>로 복합기 급지 방향을 먼저 확인하시겠습니까?
            </p>
            <div class="rp-sub-instruction">
                ※ 일반 백지/이면지 1장으로 테스트 도장을 찍어 급지 방향을 안전하게 캘리브레이션합니다.
            </div>

            <div class="rp-recovery-section">
                <a href="javascript:void(0)" class="rp-link-recovery" onclick="ReversePrintEngine.openRecoveryModal()">
                    <span>🆘</span> 이미 시험지가 거꾸로 인쇄되었나요? [합성 복구 재인쇄]
                </a>
            </div>
        </div>
        <div class="rp-modal-footer">
            <button type="button" class="btn-rp-ghost" onclick="ReversePrintEngine.proceedSkipToPrint()">
                건너뛰기 (위험 감수)
            </button>
            <button type="button" class="btn-rp-primary" onclick="ReversePrintEngine.goToStage2()">
                <span>🧪</span> 테스트 시트 출력 (권장: 10초)
            </button>
        </div>
    </div>

    <!-- ======================================================================
         Stage 2: 트레이 비우기 물리 확인 모달 (Physical Tray Interlock)
         ====================================================================== -->
    <div id="rpCardStage2" class="rp-modal-container rp-card-stage2 rp-hidden">
        <div class="rp-modal-header">
            <h3 class="rp-modal-title" style="color: #c2410c;">
                <span>🛑</span> 잠깐! 프린터 급지함을 먼저 확인해주세요
            </h3>
            <button type="button" class="rp-modal-close" onclick="ReversePrintEngine.closeModal()" title="닫기">&times;</button>
        </div>
        <div class="rp-modal-body">
            <div class="rp-warning-box">
                <div style="font-weight: 800; font-size: 15px; margin-bottom: 6px; color: #9a3412;">
                    ⚠️ 학생 시험지가 트레이에 들어있다면 즉시 모두 빼내세요!
                </div>
                <div style="font-size: 14px; line-height: 1.5;">
                    지금 프린터 트레이(급지대)에 학생 시험지가 들어있는 상태로 테스트를 누르면,
                    <strong>1번 학생의 소중한 원본 시험지가 테스트 용지로 오염</strong>됩니다.
                </div>
            </div>

            <p style="margin: 12px 0 4px 0; font-size: 14.5px; color: #334155;">
                테스트 시트는 <strong>[일반 이면지 또는 A4 백지 1장]</strong>으로만 출력해야 합니다.
            </p>

            <!-- 인터록 체크박스 -->
            <label class="rp-interlock-checkbox-box" id="rpTrayInterlockBox">
                <input type="checkbox" id="rpTrayEmptyCheck" onchange="ReversePrintEngine.handleTrayCheckChange(this)">
                <span class="rp-interlock-checkbox-label">
                    급지함에 학생 시험지가 없음을 확인했습니다. (백지 1장만 투입)
                </span>
            </label>
        </div>
        <div class="rp-modal-footer">
            <button type="button" class="btn-rp-ghost" onclick="ReversePrintEngine.goToStage1()">
                뒤로
            </button>
            <button type="button" id="btnRpStartTestPrint" class="btn-rp-primary" style="background:#ea580c;" disabled onclick="ReversePrintEngine.executeTestSheetPrint()">
                <span>🖨️</span> 테스트 시트 1장 인쇄 시작
            </button>
        </div>
    </div>

    <!-- ======================================================================
         Stage 3: 테스트 도장 인쇄 및 지시문 도장 확인 (Stamped Instruction)
         ====================================================================== -->
    <div id="rpCardStage3" class="rp-modal-container rp-card-stage3 rp-hidden">
        <div class="rp-modal-header">
            <h3 class="rp-modal-title" style="color: #1d4ed8;">
                <span>📑</span> 출력된 테스트 시트를 다시 프린터에 넣어주세요
            </h3>
            <button type="button" class="rp-modal-close" onclick="ReversePrintEngine.closeModal()" title="닫기">&times;</button>
        </div>
        <div class="rp-modal-body">
            <div class="rp-instruction-steps">
                <div class="rp-step-item">
                    <div class="rp-step-num">1</div>
                    <div style="font-size: 14px; color: #1e293b;">
                        방금 출력된 종이를 그림처럼 손으로 잡고,<br>
                        <strong>[그림이 눈에 보이도록]</strong> 트레이에 다시 넣으세요.
                    </div>
                </div>

                <div class="rp-step-item" style="background: #fff1f2; border-color: #fecdd3;">
                    <div class="rp-step-num" style="background: #ef4444;">2</div>
                    <div style="flex: 1;">
                        <div style="font-size: 14px; color: #991b1b; font-weight: 700; margin-bottom: 8px;">
                            아래 [테스트 도장 인쇄] 버튼을 누르면 올바른 급지 방향 지시문에 빨간 박스가 인쇄됩니다.
                        </div>
                        <button type="button" class="btn-rp-stamp" onclick="ReversePrintEngine.executeStampPrint()">
                            <span>🔴</span> 테스트 도장 인쇄 실행
                        </button>
                    </div>
                </div>

                <div class="rp-step-item">
                    <div class="rp-step-num">3</div>
                    <div style="font-size: 14px; color: #1e293b;">
                        배출된 종이에서 <strong>빨간 도장 [ ■ ]이 찍힌 지시문을 확인</strong>하고,<br>
                        실제 학생 시험지를 그 지시문 방향대로 넣고 본 인쇄를 시작하세요.
                    </div>
                </div>
            </div>
        </div>
        <div class="rp-modal-footer">
            <button type="button" class="btn-rp-ghost" onclick="ReversePrintEngine.goToStage2()">
                이전 단계
            </button>
            <button type="button" class="btn-rp-primary" style="background: #2563eb;" onclick="ReversePrintEngine.proceedToFinalPrint()">
                <span>🚀</span> 본 인쇄 (<span id="rpStudentCountStage3">0</span>장) 시작 ➔
            </button>
        </div>
    </div>

    <!-- ======================================================================
         양면 넘김 선택 모달 (Duplex Orientation Modal)
         - 2페이지 양면 시험지일 때 긴변/짧은변 하드웨어 참사 방지
         ====================================================================== -->
    <div id="rpCardDuplex" class="rp-modal-container rp-hidden" style="border: 2px solid #3b82f6;">
        <div class="rp-modal-header" style="background: #eff6ff; border-bottom: 1px solid #bfdbfe;">
            <h3 class="rp-modal-title" style="color: #1d4ed8;">
                <span>📖</span> 양면 시험지 넘김 방식 선택
            </h3>
            <button type="button" class="rp-modal-close" onclick="ReversePrintEngine.closeModal()" title="닫기">&times;</button>
        </div>
        <div class="rp-modal-body">
            <p style="font-size: 14.5px; color: #334155; margin-top: 0;">
                복합기 드라이버는 제조사 기본값인 <strong>[긴변 넘김 (책자형)]</strong>으로 고정됩니다.<br>
                선생님의 시험지 형태에 맞추어 뒷면(2쪽)을 소프트웨어가 자동으로 정렬합니다.
            </p>

            <div class="rp-duplex-grid">
                <!-- 옵션 1: 책자형 -->
                <div id="rpDuplexBook" class="rp-duplex-card selected" onclick="ReversePrintEngine.selectDuplexMode('book')">
                    <div class="rp-duplex-icon">📖</div>
                    <div class="rp-duplex-title">책자형 (좌우 넘김)</div>
                    <div class="rp-duplex-sub">일반적인 시험지/책처럼 옆으로 넘겨보는 시험지</div>
                    <span class="rp-duplex-badge badge-book">뒷면 정방향 (0°)</span>
                </div>

                <!-- 옵션 2: 상철형 -->
                <div id="rpDuplexFlip" class="rp-duplex-card" onclick="ReversePrintEngine.selectDuplexMode('flip')">
                    <div class="rp-duplex-icon">🗓️</div>
                    <div class="rp-duplex-title">상철형 (위아래 넘김)</div>
                    <div class="rp-duplex-sub">달력처럼 위로 넘겨보는 시험지 (스테이플러 상단 철)</div>
                    <span class="rp-duplex-badge badge-flip">뒷면 180° 자동 회전</span>
                </div>
            </div>
            
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 13px; color: #64748b;">
                💡 <strong>Single-Pass 원스톱 인쇄:</strong> '상철형'을 선택하시면 뒷면을 180도 미리 뒤집어 전송하므로, 중간에 종이를 꺼내 뒤집을 필요 없이 1회 급지로 30장 전체가 완벽하게 양면 인쇄됩니다.
            </div>
        </div>
        <div class="rp-modal-footer">
            <button type="button" class="btn-rp-ghost" onclick="ReversePrintEngine.backFromDuplex()">
                뒤로
            </button>
            <button type="button" class="btn-rp-primary" style="background: #2563eb;" onclick="ReversePrintEngine.startOverprintExecution()">
                <span>🖨️</span> 최종 역인쇄 실행
            </button>
        </div>
    </div>

    <!-- ======================================================================
         비상 복구 모달: 합성 재인쇄 (Zero-Loss Synthetic Reprint)
         ====================================================================== -->
    <div id="rpCardRecovery" class="rp-modal-container rp-hidden" style="border: 2px solid #dc2626;">
        <div class="rp-modal-header" style="background: #fef2f2; border-bottom: 1px solid #fecaca;">
            <h3 class="rp-modal-title" style="color: #991b1b;">
                <span>🆘</span> 비상 복구: 시험지 원본 + 채점 결과 완전 합성 재인쇄
            </h3>
            <button type="button" class="rp-modal-close" onclick="ReversePrintEngine.closeModal()" title="닫기">&times;</button>
        </div>
        <div class="rp-modal-body">
            <div class="rp-danger-banner" style="background: #fff5f5;">
                <div style="font-weight: 700; color: #991b1b; margin-bottom: 6px;">
                    시험지가 반대로 인쇄되었거나 심하게 오염/훼손되었을 때 사용하는 최후의 안전망입니다.
                </div>
                <div style="font-size: 13.5px; color: #7f1d1d;">
                    프린터에 <strong>[새 A4 백지]</strong>를 넣고 출력하면, 학생의 손글씨 원본 스캔본과 빨간색 채점 결과가 통째로 1장의 완제품 컬러 시험지로 합성되어 새 종이에 출력됩니다.
                </div>
            </div>

            <div style="margin: 16px 0;">
                <label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 8px;">출력 대상 학생 선택:</label>
                <select id="rpRecoveryStudentSelect" style="width: 100%; padding: 10px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 14px;">
                    <option value="all">전체 학생 일괄 복구 재인쇄</option>
                </select>
            </div>
        </div>
        <div class="rp-modal-footer">
            <button type="button" class="btn-rp-ghost" onclick="ReversePrintEngine.closeModal()">
                닫기
            </button>
            <button type="button" class="btn-rp-primary" style="background: #dc2626;" onclick="ReversePrintEngine.executeSyntheticReprint()">
                <span>📄</span> 백지에 합성 복구 인쇄 시작
            </button>
        </div>
    </div>

    <!-- ======================================================================
         진행 중 로딩 / 프로그레스 카드
         ====================================================================== -->
    <div id="rpCardLoading" class="rp-modal-container rp-hidden" style="max-width: 440px; text-align: center; padding: 32px 24px;">
        <div style="font-size: 42px; margin-bottom: 12px;">⚙️</div>
        <h4 id="rpLoadingTitle" style="font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 8px 0;">
            투명 오버레이 PDF 생성 중...
        </h4>
        <div id="rpLoadingDesc" style="font-size: 13.5px; color: #64748b; line-height: 1.5;">
            학생들의 채점 기호와 점수를 정밀 좌표에 렌더링하고 있습니다.
        </div>
        <div class="rp-progress-bar">
            <div id="rpProgressFill" class="rp-progress-fill"></div>
        </div>
        <div id="rpProgressPercent" style="font-size: 12.5px; color: #64748b; margin-top: 8px; font-weight: 700;">
            0%
        </div>
    </div>

</div>
