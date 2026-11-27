/**
 * assets/js/reverse_print.js
 * 쌤글라스(SsamGlass) 오버레이 투명 PDF 역인쇄(Reverse Overprinting) 엔진
 * - 손실 프레이밍(Loss-Framing) 기반 3단계 안전 인터로킹
 * - 1인칭 프로브 테스트 시트 및 지시문 도장(Stamped Instruction) 생성
 * - 단 1회 급지(Single-Pass) 양면 180° 사전 회전(Pre-rotation)
 * - 보이지 않는 숨겨진 iframe 다이렉트 인쇄 스풀링
 * - 최후의 안전망: 비상 복구 시스템 (Zero-Loss Synthetic Reprint)
 */

const ReversePrintEngine = (function() {
    'use strict';

    // 내부 상태 변수
    let duplexMode = 'book'; // 'book' (책자형: 0°) | 'flip' (상철형: 180°)
    let currentFlowSource = 'stage1'; // 어디서 진입했는지 ('stage1_skip' | 'stage3')
    let cachedStudents = [];
    let isProcessing = false;

    // A4 표준 규격 (72 DPI 기준 포인트: 595.28 x 841.89 pt)
    const A4_PT_W = 595.28;
    const A4_PT_H = 841.89;

    // 캔버스 렌더링용 고해상도 픽셀 (약 150~200 DPI: 1240 x 1754 px)
    const CANVAS_W = 1240;
    const CANVAS_H = 1754;

    /**
     * 학생 목록 및 채점 결과 집계
     */
    function collectStudentData() {
        const studentMap = new Map();

        // 1. rawRowsData 우선 탐색
        if (Array.isArray(window.rawRowsData) && window.rawRowsData.length > 0) {
            window.rawRowsData.forEach(row => {
                const sId = row.student_id || row.db_id;
                if (!sId) return;
                if (!studentMap.has(sId)) {
                    studentMap.set(sId, {
                        db_id: row.db_id,
                        student_id: sId,
                        studentName: row.studentName || ('학생 ' + sId),
                        total_score: 0,
                        items: []
                    });
                }
                const entry = studentMap.get(sId);
                entry.items.push(row);
                entry.total_score += parseFloat(row.currentScore || 0);
            });
        } 
        // 2. initialDbRows 폴백
        else if (Array.isArray(window.initialDbRows) && window.initialDbRows.length > 0) {
            window.initialDbRows.forEach(dbRow => {
                const sId = dbRow.student_id || dbRow.id;
                let parsedItems = [];
                try {
                    const raw = typeof dbRow.raw_results === 'string' ? JSON.parse(dbRow.raw_results) : (dbRow.raw_results || []);
                    parsedItems = Array.isArray(raw) ? raw : (raw.items || []);
                } catch(e) { parsedItems = []; }

                const sHash = dbRow.student_hash || '';
                const name = (window.studentNameLookup && window.studentNameLookup[sHash]) || dbRow.student_name || ('학생 ' + sId);
                
                let totScore = parseFloat(dbRow.total_score || 0);
                if (!totScore && parsedItems.length > 0) {
                    parsedItems.forEach(it => {
                        if (it.status === 1 || it.status === 'correct') totScore += parseFloat(it.score || 0);
                        else if (it.status === 2 || it.status === 'partial') totScore += parseFloat(it.score_awarded || it.currentScore || 0);
                    });
                }

                studentMap.set(sId, {
                    db_id: dbRow.id,
                    student_id: sId,
                    studentName: name,
                    total_score: Math.round(totScore * 10) / 10,
                    items: parsedItems
                });
            });
        }

        cachedStudents = Array.from(studentMap.values());
        return cachedStudents;
    }

    /**
     * 시험지가 2페이지 이상(양면)인지 확인
     */
    function isDuplexExam() {
        if (Array.isArray(window.templatePages) && window.templatePages.length > 1) {
            return true;
        }
        // 문항 중 2페이지 이상이 있는지 확인
        if (Array.isArray(window.templateRois)) {
            const hasP2 = window.templateRois.some(r => parseInt(r.page || 1, 10) >= 2);
            if (hasP2) return true;
        }
        return false;
    }

    /**
     * 1단계 손실 프레이밍 모달 열기
     */
    function openModal() {
        const students = collectStudentData();
        const studentCount = students.length;

        // 동적 학생수 바인딩
        const countBadge = document.getElementById('rpStudentCountStage1');
        const countText = document.getElementById('rpStudentCountText');
        const countStage3 = document.getElementById('rpStudentCountStage3');

        if (countBadge) countBadge.textContent = studentCount + '장';
        if (countText) countText.textContent = studentCount + '명 전원';
        if (countStage3) countStage3.textContent = studentCount;

        // 모달 상태 초기화
        showCard('rpCardStage1');
        const backdrop = document.getElementById('rpModalBackdrop');
        if (backdrop) backdrop.classList.add('rp-active');
    }

    /**
     * 모달 닫기
     */
    function closeModal() {
        if (isProcessing) return;
        const backdrop = document.getElementById('rpModalBackdrop');
        if (backdrop) backdrop.classList.remove('rp-active');
        hideAllCards();
    }

    /**
     * 특정 카드만 노출하고 나머지 숨김
     */
    function showCard(cardId) {
        const cardIds = ['rpCardStage1', 'rpCardStage2', 'rpCardStage3', 'rpCardDuplex', 'rpCardRecovery', 'rpCardLoading'];
        cardIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                if (id === cardId) el.classList.remove('rp-hidden');
                else el.classList.add('rp-hidden');
            }
        });
    }

    function hideAllCards() {
        const cardIds = ['rpCardStage1', 'rpCardStage2', 'rpCardStage3', 'rpCardDuplex', 'rpCardRecovery', 'rpCardLoading'];
        cardIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.add('rp-hidden');
        });
    }

    /**
     * Stage 1 -> Stage 2 이동
     */
    function goToStage2() {
        // 체크박스 리셋
        const chk = document.getElementById('rpTrayEmptyCheck');
        const btn = document.getElementById('btnRpStartTestPrint');
        const box = document.getElementById('rpTrayInterlockBox');
        if (chk) chk.checked = false;
        if (btn) btn.disabled = true;
        if (box) box.classList.remove('checked');

        showCard('rpCardStage2');
    }

    function goToStage1() {
        showCard('rpCardStage1');
    }

    function goToStage3() {
        showCard('rpCardStage3');
    }

    /**
     * Stage 2: 트레이 비우기 체크박스 인터록 이벤트
     */
    function handleTrayCheckChange(checkbox) {
        const btn = document.getElementById('btnRpStartTestPrint');
        const box = document.getElementById('rpTrayInterlockBox');
        if (btn) btn.disabled = !checkbox.checked;
        if (box) {
            if (checkbox.checked) box.classList.add('checked');
            else box.classList.remove('checked');
        }
    }

    /**
     * Stage 1 [건너뛰기] 클릭 시 인터록
     */
    function proceedSkipToPrint() {
        currentFlowSource = 'stage1_skip';
        if (isDuplexExam()) {
            openDuplexModal();
        } else {
            startOverprintExecution();
        }
    }

    /**
     * Stage 3 [본 인쇄 시작] 클릭 시 인터록
     */
    function proceedToFinalPrint() {
        currentFlowSource = 'stage3';
        if (isDuplexExam()) {
            openDuplexModal();
        } else {
            startOverprintExecution();
        }
    }

    /**
     * 양면 넘김 모달 열기
     */
    function openDuplexModal() {
        selectDuplexMode('book'); // 기본값 책자형
        showCard('rpCardDuplex');
    }

    function selectDuplexMode(mode) {
        duplexMode = mode;
        const cardBook = document.getElementById('rpDuplexBook');
        const cardFlip = document.getElementById('rpDuplexFlip');
        if (cardBook) cardBook.classList.toggle('selected', mode === 'book');
        if (cardFlip) cardFlip.classList.toggle('selected', mode === 'flip');
    }

    function backFromDuplex() {
        if (currentFlowSource === 'stage3') {
            goToStage3();
        } else {
            goToStage1();
        }
    }

    /**
     * 프로그레스 모달 표시
     */
    function showLoading(title, desc, percent) {
        showCard('rpCardLoading');
        const t = document.getElementById('rpLoadingTitle');
        const d = document.getElementById('rpLoadingDesc');
        const f = document.getElementById('rpProgressFill');
        const p = document.getElementById('rpProgressPercent');
        if (t) t.textContent = title;
        if (d) d.textContent = desc;
        if (f) f.style.width = percent + '%';
        if (p) p.textContent = percent + '%';
    }

    // =========================================================================
    // 설계 3.3 규격: Hidden iframe Direct Spooling 인쇄
    // =========================================================================
    function executeSilentPdfPrint(pdfBlobUrl) {
        let printFrame = document.getElementById('ssam_print_frame');
        if (!printFrame) {
            printFrame = document.createElement('iframe');
            printFrame.id = 'ssam_print_frame';
            printFrame.style.position = 'fixed';
            printFrame.style.right = '0';
            printFrame.style.bottom = '0';
            printFrame.style.width = '0';
            printFrame.style.height = '0';
            printFrame.style.border = '0';
            document.body.appendChild(printFrame);
        }
        printFrame.src = pdfBlobUrl;
        printFrame.onload = function() {
            setTimeout(() => {
                try {
                    printFrame.contentWindow.focus();
                    printFrame.contentWindow.print();
                } catch(e) {
                    console.error('Hidden iframe print error:', e);
                    window.open(pdfBlobUrl, '_blank');
                }
            }, 350);
        };
    }

    // =========================================================================
    // 공식 프로브 테스트 시트 생성기 (설계 4절 규격 준수)
    // =========================================================================
    async function generateProbeSheetCanvas(pageNum) {
        const c = document.createElement('canvas');
        c.width = CANVAS_W;
        c.height = CANVAS_H;
        const ctx = c.getContext('2d');

        // 배경 흰색
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, CANVAS_W, CANVAS_H);

        // 외곽 안전 여백 테두리 (연한 회색 가이드)
        ctx.strokeStyle = '#cbd5e1';
        ctx.lineWidth = 2;
        ctx.strokeRect(30, 30, CANVAS_W - 60, CANVAS_H - 60);

        if (pageNum === 1) {
            // =================================================================
            // [Page 1 (앞면) - 공식 인쇄면]
            // =================================================================
            
            // 상단 타이틀
            ctx.fillStyle = '#64748b';
            ctx.font = 'bold 22px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('[ Page 1 (앞면) - 복합기 급지 방향 캘리브레이션 프로브 시트 ]', CANVAS_W / 2, 70);

            // [ 1. 테스트한 방향 그대로 프린터에 넣어주세요 ]
            drawProbeInstructionBox(ctx, {
                x: 60,
                y: 110,
                w: CANVAS_W - 120,
                h: 160,
                num: '1',
                text: '1. 테스트한 방향 그대로 프린터에 넣어주세요',
                sub: '(종이 앞면이 위를 향하고, 머리 방향이 급지대 안쪽으로 투입된 표준 방향)',
                isTargetTop: true
            });

            // 중앙 1인칭 두 손 일러스트
            drawFirstPersonHandsIllustration(ctx, CANVAS_W / 2, CANVAS_H / 2);

            // [ 2. 앞면은 그대로 둔 채 180도 돌려서 넣어주세요 ] (하단 - 180도 역회전 인쇄)
            ctx.save();
            ctx.translate(CANVAS_W / 2, CANVAS_H - 190);
            ctx.rotate(Math.PI); // 180도 회전
            drawProbeInstructionBox(ctx, {
                x: -(CANVAS_W - 120) / 2,
                y: -80,
                w: CANVAS_W - 120,
                h: 160,
                num: '2',
                text: '2. 앞면은 그대로 둔 채 180도 돌려서 넣어주세요',
                sub: '(종이가 거꾸로 출력된 상태이므로, 앞면을 유지한 채 180도 회전하여 투입)',
                isTargetTop: false
            });
            ctx.restore();

        } else {
            // =================================================================
            // [Page 2 (뒷면) - 양면 인쇄 시 배면]
            // =================================================================
            ctx.fillStyle = '#64748b';
            ctx.font = 'bold 22px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('[ Page 2 (뒷면) - 양면 인쇄 시 배면 가이드 ]', CANVAS_W / 2, 70);

            // [ 3. 시험지 앞면이 바닥을 향하도록 뒤집어 넣으세요 ]
            drawProbeInstructionBox(ctx, {
                x: 60,
                y: 130,
                w: CANVAS_W - 120,
                h: 160,
                num: '3',
                text: '3. 시험지 앞면이 바닥을 향하도록 뒤집어 넣으세요',
                sub: '(복합기가 배면으로 종이를 끌어당기므로, 시험지 앞면을 아래로 뒤집어 투입)',
                isTargetTop: false
            });

            // [ 4. 앞면이 바닥을 보게 뒤집고, 180도 돌려 넣으세요 ]
            ctx.save();
            ctx.translate(CANVAS_W / 2, CANVAS_H - 210);
            ctx.rotate(Math.PI); // 180도 회전
            drawProbeInstructionBox(ctx, {
                x: -(CANVAS_W - 120) / 2,
                y: -80,
                w: CANVAS_W - 120,
                h: 160,
                num: '4',
                text: '4. 앞면이 바닥을 보게 뒤집고, 180도 돌려 넣으세요',
                sub: '(시험지 앞면이 바닥을 향하게 뒤집은 후, 180도 회전시켜 투입)',
                isTargetTop: false
            });
            ctx.restore();
        }

        return c;
    }

    /**
     * 프로브 지시문 박스 렌더링
     */
    function drawProbeInstructionBox(ctx, opts) {
        const { x, y, w, h, num, text, sub, isTargetTop } = opts;

        // 외곽 박스
        ctx.fillStyle = '#f8fafc';
        ctx.fillRect(x, y, w, h);
        ctx.strokeStyle = '#0f172a';
        ctx.lineWidth = 3;
        ctx.strokeRect(x, y, w, h);

        // 도장 착탄용 타겟 체크박스 🔲
        const boxSize = 54;
        const targetBoxX = x + 30;
        const targetBoxY = y + (h - boxSize) / 2;

        ctx.strokeStyle = '#dc2626';
        ctx.lineWidth = 3.5;
        ctx.strokeRect(targetBoxX, targetBoxY, boxSize, boxSize);

        // 텍스트 안내
        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 30px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(text, targetBoxX + boxSize + 24, y + 68);

        ctx.fillStyle = '#64748b';
        ctx.font = 'bold 18px sans-serif';
        ctx.fillText(sub, targetBoxX + boxSize + 24, y + 112);
    }

    /**
     * 1인칭 두 손 일러스트레이션 (Canvas Vector Graphic)
     */
    function drawFirstPersonHandsIllustration(ctx, cx, cy) {
        ctx.save();

        // 1. 종이 형상 (두 손으로 잡고 있는 종이 시뮬레이션)
        const paperW = 540;
        const paperH = 420;
        const paperX = cx - paperW / 2;
        const paperY = cy - paperH / 2;

        ctx.fillStyle = '#ffffff';
        ctx.shadowColor = 'rgba(0, 0, 0, 0.08)';
        ctx.shadowBlur = 20;
        ctx.shadowOffsetY = 10;
        ctx.fillRect(paperX, paperY, paperW, paperH);
        ctx.shadowColor = 'transparent';

        ctx.strokeStyle = '#94a3b8';
        ctx.lineWidth = 2;
        ctx.strokeRect(paperX, paperY, paperW, paperH);

        // 종이 안쪽 화살표 및 안내 문구
        ctx.fillStyle = '#0284c7';
        ctx.font = 'bold 32px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('두 손으로 종이 양옆을 쥐고', cx, cy - 60);

        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 38px sans-serif';
        ctx.fillText('"이 그림이 눈에 보이게"', cx, cy + 5);

        ctx.fillStyle = '#dc2626';
        ctx.font = 'bold 32px sans-serif';
        ctx.fillText('프린터 트레이(급지대)에 투입', cx, cy + 70);

        // 급지 진행 화살표 (위쪽을 향하는 큰 화살표)
        drawThickArrow(ctx, cx, cy - 130, 40, '#0284c7');

        // 2. 왼쪽 손 (Left Hand grasping side)
        drawHand(ctx, paperX - 25, cy + 40, 'left');

        // 3. 오른쪽 손 (Right Hand grasping side)
        drawHand(ctx, paperX + paperW + 25, cy + 40, 'right');

        ctx.restore();
    }

    /**
     * 손 일러스트 (심플하고 직관적인 만화풍 손)
     */
    function drawHand(ctx, hx, hy, side) {
        ctx.save();
        ctx.translate(hx, hy);
        if (side === 'right') ctx.scale(-1, 1);

        ctx.fillStyle = '#fed7aa'; // 피부톤
        ctx.strokeStyle = '#c2410c'; // 외곽선
        ctx.lineWidth = 3;

        // 엄지손가락 (종이 앞면을 누름)
        ctx.beginPath();
        ctx.ellipse(35, -20, 26, 16, Math.PI / 6, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();

        // 손바닥 및 나머지 손가락 (종이 옆면/뒷면을 받침)
        ctx.beginPath();
        ctx.roundRect(-45, -50, 60, 110, [15, 15, 20, 20]);
        ctx.fill();
        ctx.stroke();

        ctx.restore();
    }

    function drawThickArrow(ctx, x, y, size, color) {
        ctx.save();
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.moveTo(x, y - size);
        ctx.lineTo(x + size, y + size * 0.4);
        ctx.lineTo(x + size * 0.4, y + size * 0.4);
        ctx.lineTo(x + size * 0.4, y + size);
        ctx.lineTo(x - size * 0.4, y + size);
        ctx.lineTo(x - size * 0.4, y + size * 0.4);
        ctx.lineTo(x - size, y + size * 0.4);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
    }

    /**
     * Stage 2: 테스트 시트 1장 인쇄 실행
     */
    async function executeTestSheetPrint() {
        if (!window.PDFLib) {
            alert('PDF 렌더링 라이브러리를 불러오지 못했습니다. 새로고침 후 다시 시도해주세요.');
            return;
        }

        try {
            showLoading('테스트 시트 생성 중...', '1인칭 급지 가이드 프로브 시트를 생성하고 있습니다.', 30);

            const { PDFDocument } = window.PDFLib;
            const pdfDoc = await PDFDocument.create();

            // 1페이지 생성
            const canvasP1 = await generateProbeSheetCanvas(1);
            const blobP1 = await new Promise(res => canvasP1.toBlob(res, 'image/png'));
            const bytesP1 = await blobP1.arrayBuffer();
            const imgP1 = await pdfDoc.embedPng(bytesP1);

            const page1 = pdfDoc.addPage([A4_PT_W, A4_PT_H]);
            page1.drawImage(imgP1, { x: 0, y: 0, width: A4_PT_W, height: A4_PT_H });

            showLoading('테스트 시트 생성 중...', '스풀러로 전송하고 있습니다.', 90);

            const pdfBytes = await pdfDoc.save();
            const blob = new Blob([pdfBytes], { type: 'application/pdf' });
            const blobUrl = URL.createObjectURL(blob);

            // Hidden iframe으로 인쇄
            executeSilentPdfPrint(blobUrl);

            // 인쇄 실행 후 자동으로 Stage 3 전환
            setTimeout(() => {
                goToStage3();
            }, 600);

        } catch(err) {
            console.error('테스트 시트 출력 오류:', err);
            alert('테스트 시트 생성 실패: ' + err.message);
            goToStage2();
        }
    }

    /**
     * Stage 3: 테스트 도장 인쇄 실행
     * - 최상단 고정 좌표에 빨간 체크박스 [ ■ ] 1회 인쇄
     */
    async function executeStampPdf() {
        if (!window.PDFLib) {
            alert('PDF 렌더링 라이브러리를 불러오지 못했습니다.');
            return;
        }

        try {
            const { PDFDocument } = window.PDFLib;
            const pdfDoc = await PDFDocument.create();

            // 도장 전용 캔버스 (배경 투명)
            const c = document.createElement('canvas');
            c.width = CANVAS_W;
            c.height = CANVAS_H;
            const ctx = c.getContext('2d');

            // 1번 체크박스 위치 정확 착탄:
            // x: 60 + 30 = 90px, y: 110 + (160 - 54)/2 = 163px, w: 54, h: 54
            const targetX = 90;
            const targetY = 163;
            const size = 54;

            // 빨간색 굵은 도장 [ ■ ]
            ctx.fillStyle = '#dc2626';
            ctx.fillRect(targetX + 6, targetY + 6, size - 12, size - 12);
            ctx.strokeStyle = '#991b1b';
            ctx.lineWidth = 4;
            ctx.strokeRect(targetX, targetY, size, size);

            const blobPng = await new Promise(res => c.toBlob(res, 'image/png'));
            const bytesPng = await blobPng.arrayBuffer();
            const imgPng = await pdfDoc.embedPng(bytesPng);

            const page = pdfDoc.addPage([A4_PT_W, A4_PT_H]);
            page.drawImage(imgPng, { x: 0, y: 0, width: A4_PT_W, height: A4_PT_H });

            const pdfBytes = await pdfDoc.save();
            const blob = new Blob([pdfBytes], { type: 'application/pdf' });
            const blobUrl = URL.createObjectURL(blob);

            // Hidden iframe 인쇄
            executeSilentPdfPrint(blobUrl);

            if (typeof window.showToast === 'function') {
                window.showToast('🔴 테스트 도장이 프린터로 전송되었습니다!');
            }

        } catch(err) {
            console.error('테스트 도장 출력 오류:', err);
            alert('테스트 도장 출력 실패: ' + err.message);
        }
    }

    // =========================================================================
    // 본 인쇄: 투명 오버레이 PDF 렌더러 (설계 3.2, 3.3 규격)
    // =========================================================================
    async function startOverprintExecution() {
        const students = collectStudentData();
        if (students.length === 0) {
            alert('채점 데이터가 없습니다.');
            return;
        }

        isProcessing = true;
        showLoading('투명 오버레이 PDF 생성 중...', `학생 ${students.length}명의 채점 오버레이를 렌더링하고 있습니다.`, 10);

        try {
            const { PDFDocument, degrees } = window.PDFLib;
            const pdfDoc = await PDFDocument.create();

            // 템플릿 메타데이터 파악
            const pagesMeta = (Array.isArray(window.templatePages) && window.templatePages.length > 0)
                ? window.templatePages
                : [{ page_num: 1, width: 1200, height: 1700 }];

            const totalStudents = students.length;

            for (let sIdx = 0; sIdx < totalStudents; sIdx++) {
                const student = students[sIdx];
                const pct = Math.round(((sIdx + 1) / totalStudents) * 85);
                showLoading('투명 오버레이 PDF 생성 중...', `[${sIdx + 1}/${totalStudents}] ${student.studentName} 채점 렌더링 중...`, pct);

                // 학생의 각 페이지 렌더링
                for (let pIdx = 0; pIdx < pagesMeta.length; pIdx++) {
                    const pMeta = pagesMeta[pIdx];
                    const pageNum = pMeta.page_num || (pIdx + 1);

                    // 투명 캔버스 생성
                    const canvas = document.createElement('canvas');
                    canvas.width = CANVAS_W;
                    canvas.height = CANVAS_H;
                    const ctx = canvas.getContext('2d');

                    // 1쪽이면 우측 상단에 총점 도장 렌더링
                    if (pageNum === 1) {
                        drawTotalScoreStamp(ctx, student.total_score, CANVAS_W - 240, 80);
                    }

                    // 해당 페이지 문항들의 채점 기호(O, /, △) 렌더링
                    renderPageGradingMarks(ctx, student, pageNum, pMeta);

                    // 캔버스 -> PNG 임베드
                    const blobPng = await new Promise(res => canvas.toBlob(res, 'image/png'));
                    const bytesPng = await blobPng.arrayBuffer();
                    const imgPng = await pdfDoc.embedPng(bytesPng);

                    const pdfPage = pdfDoc.addPage([A4_PT_W, A4_PT_H]);
                    pdfPage.drawImage(imgPng, { x: 0, y: 0, width: A4_PT_W, height: A4_PT_H });

                    // 설계 3.2: 상철형(flip) 선택 시 짝수 페이지(2쪽) 180° 사전 회전
                    if (pageNum % 2 === 0 && duplexMode === 'flip') {
                        pdfPage.setRotation(degrees(180));
                    }
                }
            }

            showLoading('인쇄 스풀링 중...', '프린터 스풀러로 전송하고 있습니다.', 95);

            const pdfBytes = await pdfDoc.save();
            const blob = new Blob([pdfBytes], { type: 'application/pdf' });
            const blobUrl = URL.createObjectURL(blob);

            // Hidden iframe 인쇄 호출
            executeSilentPdfPrint(blobUrl);

            setTimeout(() => {
                isProcessing = false;
                closeModal();
                if (typeof window.showToast === 'function') {
                    window.showToast(`🎉 학생 ${totalStudents}명의 시험지 역인쇄가 스풀링되었습니다!`);
                }
            }, 800);

        } catch(err) {
            console.error('역인쇄 PDF 생성 실패:', err);
            isProcessing = false;
            alert('역인쇄 PDF 생성 실패: ' + err.message);
            closeModal();
        }
    }

    /**
     * 문항별 채점 기호 렌더링 (투명 캔버스 위)
     */
    function renderPageGradingMarks(ctx, student, pageNum, pageMeta) {
        if (!Array.isArray(window.templateRois)) return;

        const baseW = pageMeta.width || 1200;
        const baseH = pageMeta.height || 1700;

        // 원본 해상도 대비 캔버스 스케일 팩터
        const scaleX = CANVAS_W / baseW;
        const scaleY = CANVAS_H / baseH;

        // 학생 채점 결과 맵 (item_id -> result)
        const itemResultMap = new Map();
        if (Array.isArray(student.items)) {
            student.items.forEach(it => {
                const k = String(it.item_id || it.display_num || it.id || '');
                itemResultMap.set(k, it);
            });
        }

        window.templateRois.forEach((roi, idx) => {
            const roiPage = parseInt(roi.page || 1, 10);
            if (roiPage !== pageNum) return;

            const rawId = String(roi.id || (idx + 1));
            const subId = (roi.sub_id !== undefined && roi.sub_id !== null && roi.sub_id !== '') ? String(roi.sub_id) : '';
            const dispNum = String(roi.display_num || (subId ? `${rawId}-${subId}` : rawId));

            const result = itemResultMap.get(dispNum) || itemResultMap.get(rawId) || null;
            if (!result) return;

            // 채점 상태 코드 (1: 정답, 0: 오답, 2: 부분점수)
            let status = result.status;
            if (status === 'correct') status = 1;
            else if (status === 'incorrect') status = 0;
            else if (status === 'partial') status = 2;

            // 문항 위치 좌표: num_roi 우선, 없으면 x, y
            let numX, numY, numW, numH;
            if (roi.num_roi && roi.num_roi.w) {
                numX = roi.num_roi.x * scaleX;
                numY = roi.num_roi.y * scaleY;
                numW = roi.num_roi.w * scaleX;
                numH = roi.num_roi.h * scaleY;
            } else {
                numX = (roi.x || 50) * scaleX;
                numY = (roi.y || 50) * scaleY;
                numW = (roi.w || 60) * scaleX;
                numH = (roi.h || 40) * scaleY;
            }

            // 답안 영역 좌표 (ans_roi)
            let ansX = numX + numW + 10;
            let ansY = numY;
            let ansW = 100 * scaleX;
            let ansH = numH;
            if (roi.ans_roi && roi.ans_roi.w) {
                ansX = roi.ans_roi.x * scaleX;
                ansY = roi.ans_roi.y * scaleY;
                ansW = roi.ans_roi.w * scaleX;
                ansH = roi.ans_roi.h * scaleY;
            }

            const centerX = numX + numW / 2;
            const centerY = numY + numH / 2;
            const radius = Math.max(numW, numH) * 0.72;

            // 1. 정답: 빨간 동그라미 O
            if (status === 1) {
                ctx.save();
                ctx.strokeStyle = '#dc2626'; // 빨간색
                ctx.lineWidth = 4;
                ctx.beginPath();
                ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
                ctx.stroke();
                ctx.restore();
            }
            // 2. 오답: 빨간 사선 빗금 /
            else if (status === 0) {
                ctx.save();
                ctx.strokeStyle = '#dc2626';
                ctx.lineWidth = 4.5;
                ctx.beginPath();
                // 문항 영역에서 답안 영역까지 시원하게 사선 빗금
                const startX = numX - 10;
                const startY = numY + numH + 12;
                const endX = Math.max(ansX + ansW * 0.6, numX + numW + 30);
                const endY = numY - 14;
                ctx.moveTo(startX, startY);
                ctx.lineTo(endX, endY);
                ctx.stroke();
                ctx.restore();
            }
            // 3. 부분점수: 빨간 세모 △ 및 부여점수 표기
            else if (status === 2) {
                ctx.save();
                ctx.strokeStyle = '#dc2626';
                ctx.lineWidth = 4;
                ctx.beginPath();
                ctx.moveTo(centerX, centerY - radius * 1.05);
                ctx.lineTo(centerX + radius * 0.95, centerY + radius * 0.8);
                ctx.lineTo(centerX - radius * 0.95, centerY + radius * 0.8);
                ctx.closePath();
                ctx.stroke();

                // 부여 점수 표기 (예: +3.5)
                const awarded = parseFloat(result.score_awarded || result.currentScore || 0);
                ctx.fillStyle = '#dc2626';
                ctx.font = 'bold 22px sans-serif';
                ctx.textAlign = 'left';
                ctx.fillText(`+${awarded}`, numX + numW + 8, centerY + 8);
                ctx.restore();
            }
        });
    }

    /**
     * 시험지 1페이지 총점 도장 렌더링
     */
    function drawTotalScoreStamp(ctx, totalScore, x, y) {
        ctx.save();
        const w = 180;
        const h = 70;

        // 빨간 도장 테두리
        ctx.strokeStyle = '#dc2626';
        ctx.lineWidth = 3.5;
        ctx.beginPath();
        ctx.roundRect(x, y, w, h, 10);
        ctx.stroke();

        // 안쪽 얇은 이중선
        ctx.lineWidth = 1.5;
        ctx.strokeRect(x + 4, y + 4, w - 8, h - 8);

        // 총점 텍스트
        ctx.fillStyle = '#dc2626';
        ctx.textAlign = 'center';
        ctx.font = 'bold 16px sans-serif';
        ctx.fillText('총 점', x + w / 2, y + 25);

        ctx.font = 'bold 30px sans-serif';
        ctx.fillText(`${totalScore} 점`, x + w / 2, y + 56);

        ctx.restore();
    }

    // =========================================================================
    // 설계 3.4 규격: 최후의 안전망: 비상 복구 시스템 (Zero-Loss Synthetic Reprint)
    // =========================================================================
    function openRecoveryModal() {
        const students = collectStudentData();
        const select = document.getElementById('rpRecoveryStudentSelect');
        if (select) {
            select.innerHTML = '<option value="all">전체 학생 일괄 복구 재인쇄</option>';
            students.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.student_id;
                opt.textContent = `${s.studentName} (${s.total_score}점)`;
                select.appendChild(opt);
            });
        }
        showCard('rpCardRecovery');
    }

    async function executeSyntheticReprint() {
        const students = collectStudentData();
        const select = document.getElementById('rpRecoveryStudentSelect');
        const targetVal = select ? select.value : 'all';

        const targetStudents = targetVal === 'all'
            ? students
            : students.filter(s => String(s.student_id) === String(targetVal));

        if (targetStudents.length === 0) {
            alert('복구 대상 학생이 없습니다.');
            return;
        }

        isProcessing = true;
        showLoading('비상 복구 시험지 합성 중...', `학생 ${targetStudents.length}명의 원본 스캔본과 채점을 완전 합성합니다.`, 10);

        try {
            const { PDFDocument } = window.PDFLib;
            const pdfDoc = await PDFDocument.create();

            const pagesMeta = (Array.isArray(window.templatePages) && window.templatePages.length > 0)
                ? window.templatePages
                : [{ page_num: 1, width: 1200, height: 1700 }];

            const total = targetStudents.length;

            for (let i = 0; i < total; i++) {
                const st = targetStudents[i];
                const pct = Math.round(((i + 1) / total) * 85);
                showLoading('비상 복구 시험지 합성 중...', `[${i + 1}/${total}] ${st.studentName} 원본 이미지 합성 중...`, pct);

                for (let pIdx = 0; pIdx < pagesMeta.length; pIdx++) {
                    const pMeta = pagesMeta[pIdx];
                    const pageNum = pMeta.page_num || (pIdx + 1);

                    const canvas = document.createElement('canvas');
                    canvas.width = CANVAS_W;
                    canvas.height = CANVAS_H;
                    const ctx = canvas.getContext('2d');

                    // 흰색 배경
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, CANVAS_W, CANVAS_H);

                    // 1. 학생 자필 원본 스캔본 이미지 로드
                    // 원본 경로: /public/uploads/originals/{assignmentId}/{studentId}.jpg
                    // 다쪽일 경우: /public/uploads/originals/{assignmentId}/{studentId}_p{pageNum}.jpg
                    let originalUrl = pagesMeta.length > 1
                        ? `/public/uploads/originals/${window.assignId}/${st.student_id}_p${pageNum}.jpg`
                        : `/public/uploads/originals/${window.assignId}/${st.student_id}.jpg`;

                    const bgImg = await loadImageSafe(originalUrl);
                    if (bgImg) {
                        ctx.drawImage(bgImg, 0, 0, CANVAS_W, CANVAS_H);
                    } else if (pMeta.image_path) {
                        // 학생 이미지 없을 시 템플릿 기본 배경 폴백
                        const tplImg = await loadImageSafe(pMeta.image_path);
                        if (tplImg) ctx.drawImage(tplImg, 0, 0, CANVAS_W, CANVAS_H);
                    }

                    // 2. 그 위에 동일한 채점 오버레이 얹기
                    if (pageNum === 1) {
                        drawTotalScoreStamp(ctx, st.total_score, CANVAS_W - 240, 80);
                    }
                    renderPageGradingMarks(ctx, st, pageNum, pMeta);

                    // 3. PDF 페이지 추가
                    const blobPng = await new Promise(res => canvas.toBlob(res, 'image/png'));
                    const bytesPng = await blobPng.arrayBuffer();
                    const imgPng = await pdfDoc.embedPng(bytesPng);

                    const pdfPage = pdfDoc.addPage([A4_PT_W, A4_PT_H]);
                    pdfPage.drawImage(imgPng, { x: 0, y: 0, width: A4_PT_W, height: A4_PT_H });
                }
            }

            showLoading('인쇄 스풀링 중...', '새 백지 출력용 스풀러로 전송하고 있습니다.', 95);

            const pdfBytes = await pdfDoc.save();
            const blob = new Blob([pdfBytes], { type: 'application/pdf' });
            const blobUrl = URL.createObjectURL(blob);

            executeSilentPdfPrint(blobUrl);

            setTimeout(() => {
                isProcessing = false;
                closeModal();
                if (typeof window.showToast === 'function') {
                    window.showToast('📄 백지 복구 재인쇄가 프린터로 전송되었습니다!');
                }
            }, 800);

        } catch(err) {
            console.error('비상 복구 인쇄 실패:', err);
            isProcessing = false;
            alert('비상 복구 인쇄 실패: ' + err.message);
            closeModal();
        }
    }

    function loadImageSafe(url) {
        return new Promise(resolve => {
            if (!url) return resolve(null);
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
            img.src = url;
        });
    }

    // 외부에 공개하는 인터페이스 (Public API)
    return {
        openModal: openModal,
        closeModal: closeModal,
        goToStage1: goToStage1,
        goToStage2: goToStage2,
        goToStage3: goToStage3,
        handleTrayCheckChange: handleTrayCheckChange,
        executeTestSheetPrint: executeTestSheetPrint,
        executeStampPrint: executeStampPdf,
        proceedSkipToPrint: proceedSkipToPrint,
        proceedToFinalPrint: proceedToFinalPrint,
        selectDuplexMode: selectDuplexMode,
        backFromDuplex: backFromDuplex,
        startOverprintExecution: startOverprintExecution,
        openRecoveryModal: openRecoveryModal,
        executeSyntheticReprint: executeSyntheticReprint
    };
})();
