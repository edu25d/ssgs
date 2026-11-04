/**
 * public/assets/js/dashboard.js
 * 교사 메인 대시보드 클라이언트 스크립트 (반응형 & 스마트 학생 명단 등록기 포함)
 */

// --- [토스트 및 클립보드 복사] ---
function showToast(message) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = "show";
    setTimeout(() => {
        toast.className = toast.className.replace("show", "");
    }, 2500);
}

function copyToClipboard(text, label) {
    navigator.clipboard.writeText(text).then(() => {
        showToast(`${label}이(가) 복사되었습니다.`);
    }).catch(err => {
        console.error('복사 실패:', err);
        showToast('복사에 실패했습니다.');
    });
}
/* Kiosk Management Modal & Password UI */
var currentKioskClassId = null;
var currentKioskClassName = '';
var currentTeacherUsername = '';
var currentHasKioskPassword = false;
var kioskModified = false;

function updateKioskPasswordUI(hasPassword) {
    currentHasKioskPassword = Boolean(hasPassword);
    var badge = document.getElementById('kioskPasswordStatusBadge');
    var displayArea = document.getElementById('kioskPasswordDisplayArea');
    var inputArea = document.getElementById('kioskPasswordInputArea');
    var pwInput = document.getElementById('kioskClassPasswordInput');
    var cancelBtn = document.getElementById('kioskPasswordCancelBtn');
    var notice = document.getElementById('kioskPasswordNotice');

    if (pwInput) pwInput.value = '';
    if (notice) notice.style.display = 'none';

    if (currentHasKioskPassword) {
        if (badge) {
            badge.innerText = '\uB4F1\uB85D\uB428 \uD83D\uDD12';
            badge.style.background = '#dcfce7';
            badge.style.color = '#15803d';
            badge.style.border = '1px solid #86efac';
        }
        if (displayArea) displayArea.style.display = 'flex';
        if (inputArea) inputArea.style.display = 'none';
        if (cancelBtn) cancelBtn.style.display = 'none';
    } else {
        if (badge) {
            badge.innerText = '\uBBF8\uC124\uC815 \u26A0\uFE0F';
            badge.style.background = '#fef3c7';
            badge.style.color = '#b45309';
            badge.style.border = '1px solid #fcd34d';
        }
        if (displayArea) displayArea.style.display = 'none';
        if (inputArea) inputArea.style.display = 'flex';
        if (cancelBtn) cancelBtn.style.display = 'none';
        if (pwInput) pwInput.placeholder = '\uD0A4\uC624\uC2A4\uD06C \uBE44\uBC00\uBC88\uD638\uB97C \uC124\uC815\uD574\uC8FC\uC138\uC694 (4\uC790\uB9AC \uC774\uC0C1)';
    }
}

function showKioskPasswordChangeForm() {
    var displayArea = document.getElementById('kioskPasswordDisplayArea');
    var inputArea = document.getElementById('kioskPasswordInputArea');
    var cancelBtn = document.getElementById('kioskPasswordCancelBtn');
    var pwInput = document.getElementById('kioskClassPasswordInput');

    if (displayArea) displayArea.style.display = 'none';
    if (inputArea) inputArea.style.display = 'flex';
    if (cancelBtn) cancelBtn.style.display = 'inline-block';
    if (pwInput) {
        pwInput.value = '';
        pwInput.placeholder = '\uBCC0\uACBD\uD560 \uC0C8 \uBE44\uBC00\uBC88\uD638 \uC785\uB825 (4\uC790\uB9AC \uC774\uC0C1)';
        pwInput.focus();
    }
}

function cancelKioskPasswordChange() {
    updateKioskPasswordUI(currentHasKioskPassword);
}

function highlightKioskPasswordInput(message) {
    var box = document.getElementById('kioskPasswordBox');
    var pwInput = document.getElementById('kioskClassPasswordInput');
    var notice = document.getElementById('kioskPasswordNotice');

    showKioskPasswordChangeForm();

    if (notice) {
        notice.innerText = message || '\u26A0\uFE0F \uD0A4\uC624\uC2A4\uD06C ID\uB97C \uBC1C\uAE09\uD558\uB824\uBA74 \uBA3C\uC800 \uC811\uC18D \uBE44\uBC00\uBC88\uD638\uB97C \uC124\uC815\uD574 \uC8FC\uC138\uC694.';
        notice.style.display = 'block';
    }

    if (box) {
        box.classList.remove('kiosk-shake-highlight');
        void box.offsetWidth;
        box.classList.add('kiosk-shake-highlight');
        setTimeout(function() { box.classList.remove('kiosk-shake-highlight'); }, 1200);
    }

    if (pwInput) {
        pwInput.focus();
    }
}

function updateClassCardKioskDOM(classId, accounts) {
    var gridEl = document.getElementById('kioskListGrid_' + classId);
    var btnEl = document.getElementById('kioskCardBtn_' + classId);
    if (!gridEl) return;

    if (!accounts || accounts.length === 0) {
        gridEl.innerHTML = '<div class="kiosk-empty-msg">\uB4F1\uB85D\uB41C \uC785\uB825\uAE30 \uC5C6\uC74C</div>';
        if (btnEl) btnEl.innerText = '+ \uC785\uB825\uAE30 ID \uB4F1\uB85D';
    } else {
        var html = '';
        for (var i = 0; i < accounts.length; i++) {
            html += '<div class="kiosk-chip">' + escapeHtml(accounts[i].kiosk_id) + '</div>';
        }
        gridEl.innerHTML = html;
        if (btnEl) btnEl.innerText = '\u2699\uFE0F \uC785\uB825\uAE30 ID \uAD00\uB9AC';
    }
}

function loadKioskAccounts(classId) {
    var container = document.getElementById('kioskListContainer');
    if (!container) return;
    container.innerHTML = '<p style="text-align:center; padding:15px; color:#64748b;">\uBD88\uB7EC\uC624\uB294 \uC911...</p>';

    fetch('/api/classes/get_kiosk_accounts.php?class_id=' + classId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                updateKioskPasswordUI(data.has_password);
                updateClassCardKioskDOM(classId, data.accounts || []);

                if (!data.accounts || data.accounts.length === 0) {
                    container.innerHTML = '<p style="text-align:center; color:#94a3b8; font-size:0.88rem; padding:15px 0;">\uBC1C\uAE09\uB41C \uD0A4\uC624\uC2A4\uD06C ID\uAC00 \uC5C6\uC2B5\uB2C8\uB2E4.<br>\uC544\uB798 <b>[+ \uC0C8 ID \uBC1C\uAE09]</b>\uC744 \uB20C\uB7EC \uC0DD\uC131\uD558\uC138\uC694.</p>';
                    return;
                }

                var html = '<ul style="list-style:none; margin:0; padding:0;">';
                for (var i = 0; i < data.accounts.length; i++) {
                    var acc = data.accounts[i];
                    html += '<li style="display:flex; align-items:center; justify-content:space-between; padding:7px 4px; border-bottom:1px solid #f1f5f9;">' +
                                '<span style="font-family:monospace; font-weight:700; color:#0f172a; font-size:0.97rem; letter-spacing:0.02em;">' + escapeHtml(acc.kiosk_id) + '</span>' +
                                '<button type="button" class="btn btn-sm btn-danger" onclick="deleteKioskAccount(' + acc.id + ', ' + classId + ', \'' + currentTeacherUsername + '\')" style="padding:3px 10px; font-size:0.78rem;">\uC0AD\uC81C</button>' +
                            '</li>';
                }
                html += '</ul>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p style="color:red; text-align:center; padding:10px;">\uC624\uB958: ' + (data.error || '\uC815\uBCF4\uB97C \uBD88\uB7EC\uC62C \uC218 \uC5C6\uC2B5\uB2C8\uB2E4.') + '</p>';
            }
        })
        .catch(function(err) {
            console.error(err);
            container.innerHTML = '<p style="color:red; text-align:center; padding:10px;">\uD1B5\uC2E0 \uC624\uB958\uAC00 \uBC1C\uC0DD\uD588\uC2B5\uB2C8\uB2E4.</p>';
        });
}

function openKioskEditModal(classId, className, teacherUsername) {
    currentKioskClassId = classId;
    if (className) currentKioskClassName = className;
    if (teacherUsername) currentTeacherUsername = teacherUsername;
    kioskModified = false;

    var titleEl = document.getElementById('kioskModalTitle');
    if (titleEl) titleEl.innerText = (currentKioskClassName || '\uD559\uAE09') + ' - \uD0A4\uC624\uC2A4\uD06C \uAD00\uB9AC';

    var badge = document.getElementById('kioskPasswordStatusBadge');
    if (badge) {
        badge.innerText = '\uD655\uC778 \uC911...';
        badge.style.background = '#e2e8f0';
        badge.style.color = '#475569';
        badge.style.border = 'none';
    }

    var addBtn = document.getElementById('kioskAddBtn');
    if (addBtn) {
        addBtn.onclick = function() { createKioskAccount(classId); };
    }

    document.getElementById('kioskEditModal').style.display = 'flex';
    loadKioskAccounts(classId);
}

function closeKioskModal() {
    closeModal('kioskEditModal');
    if (kioskModified) {
        location.reload();
    }
}

function saveKioskClassPassword() {
    if (!currentKioskClassId) return;

    var pwInput = document.getElementById('kioskClassPasswordInput');
    var pw = pwInput ? pwInput.value.trim() : '';

    if (!pw || pw.length < 4) {
        highlightKioskPasswordInput('\uBE44\uBC00\uBC88\uD638\uB97C 4\uC790\uB9AC \uC774\uC0C1 \uC785\uB825\uD574 \uC8FC\uC138\uC694.');
        return;
    }

    var formData = new URLSearchParams();
    formData.append('class_id', currentKioskClassId);
    formData.append('password', pw);

    fetch('/api/classes/set_kiosk_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async function(res) {
        var text = await res.text();
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error('Non-JSON response:', text);
            throw new Error('\uC11C\uBC84 \uC751\uB2F5 \uC624\uB958: ' + text.substring(0, 100));
        }
    })
    .then(function(data) {
        if (data.success) {
            showToast('\uD559\uAE09 \uD0A4\uC624\uC2A4\uD06C \uBE44\uBC00\uBC88\uD638\uAC00 \uC800\uC7A5\uB418\uC5C8\uC2B5\uB2C8\uB2E4.');
            updateKioskPasswordUI(true);
            kioskModified = true;
        } else {
            alert('\uC624\uB958: ' + (data.error || data.message || '\uC800\uC7A5 \uC2E4\uD328'));
        }
    })
    .catch(function(err) {
        console.error(err);
        alert(err.message || '\uD1B5\uC2E0 \uC624\uB958\uAC00 \uBC1C\uC0DD\uD588\uC2B5\uB2C8\uB2E4.');
    });
}

function deleteKioskAccount(accountId, classId, teacherUsername) {
    if (!confirm('\uC774 \uD0A4\uC624\uC2A4\uD06C \uACC4\uC815\uC744 \uC0AD\uC81C\uD558\uC2DC\uACA0\uC2B5\uB2C8\uAE4C?')) return;

    var formData = new URLSearchParams();
    formData.append('account_id', accountId);

    fetch('/api/classes/delete_kiosk_account.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async function(res) {
        var text = await res.text();
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error('Non-JSON response:', text);
            throw new Error('\uC11C\uBC84 \uC751\uB2F5 \uC624\uB958: ' + text.substring(0, 100));
        }
    })
    .then(function(data) {
        if (data.success) {
            showToast('\uC0AD\uC81C\uB418\uC5C8\uC2B5\uB2C8\uB2E4.');
            kioskModified = true;
            loadKioskAccounts(classId);
        } else {
            alert('\uC624\uB958: ' + (data.error || data.message || '\uC0AD\uC81C \uC2E4\uD328'));
        }
    })
    .catch(function(err) {
        console.error(err);
        alert(err.message || '\uD1B5\uC2E0 \uC624\uB958\uAC00 \uBC1C\uC0DD\uD588\uC2B5\uB2C8\uB2E4.');
    });
}

function createKioskAccount(classId) {
    if (!currentHasKioskPassword) {
        highlightKioskPasswordInput('\u26A0\uFE0F \uD0A4\uC624\uC2A4\uD06C ID\uB97C \uBC1C\uAE09\uD558\uB824\uBA74 \uBA3C\uC800 \uC811\uC18D \uBE44\uBC00\uBC88\uD638\uB97C \uC124\uC815\uD574 \uC8FC\uC138\uC694.');
        return;
    }

    var formData = new URLSearchParams();
    formData.append('class_id', classId);

    fetch('/api/classes/create_kiosk_account.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async function(res) {
        var text = await res.text();
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error('Non-JSON response from server:', text);
            throw new Error('\uC11C\uBC84 \uC751\uB2F5 \uC624\uB958 (HTML): ' + text.substring(0, 120));
        }
    })
    .then(function(data) {
        if (data.success) {
            var issuedId = data.kiosk_id || (data.data && data.data.kiosk_id) || '';
            showToast('\uD0A4\uC624\uC2A4\uD06C ID\uAC00 \uBC1C\uAE09\uB418\uC5C8\uC2B5\uB2C8\uB2E4: ' + issuedId);
            kioskModified = true;
            loadKioskAccounts(classId);
        } else {
            alert('\uC624\uB958: ' + (data.error || data.message || '\uACC4\uC815 \uBC1C\uAE09 \uC2E4\uD328'));
        }
    })
    .catch(function(err) {
        console.error(err);
        alert(err.message || '\uD1B5\uC2E0 \uC624\uB958\uAC00 \uBC1C\uC0DD\uD588\uC2B5\uB2C8\uB2E4.');
    });
}
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'flex';
}

let isCreatingClass = false;

function openAddModal() {
    const input = document.getElementById('classNamesInput');
    if (input) input.value = '';
    const modal = document.getElementById('addClassModal');
    if (modal) modal.style.display = 'flex';
    if (input) input.focus();
}

function renderCreatingClassPlaceholders(names) {
    // 1. 모바일 탭에서 학급 탭이 비활성 상태면 활성화 전환
    const classBtn = document.querySelector('.segment-tab-btn[onclick*="classes"]');
    if (typeof switchMobileTab === 'function') {
        switchMobileTab('classes', classBtn);
    }

    // 2. 등록된 학급 없음 안내 박스가 있으면 숨김
    const emptyNotice = document.getElementById('emptyClassNotice');
    if (emptyNotice) {
        emptyNotice.style.display = 'none';
    }

    // 3. 학급 카드 그리드 컨테이너 확인 및 노출
    let grid = document.getElementById('classGridContainer');
    if (!grid) {
        grid = document.createElement('div');
        grid.className = 'class-grid';
        grid.id = 'classGridContainer';
        const section = document.getElementById('tabSectionClasses');
        if (section) section.appendChild(grid);
    }
    grid.style.display = 'flex';

    // 4. 입력된 각 학급명마다 진행 스켈레톤 카드 동적 추가
    names.forEach(name => {
        const card = document.createElement('div');
        card.className = 'class-card-creating';
        card.innerHTML = `
            <div class="creating-spinner"></div>
            <div class="creating-name">${escapeHtml(name)}</div>
            <div class="creating-desc">구글 드라이브 연동 중...</div>
        `;
        grid.appendChild(card);
    });

    // 5. 학급 관리 영역으로 부드럽게 스크롤 이동
    const section = document.getElementById('tabSectionClasses');
    if (section) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

async function handleCreateClassSubmit(e) {
    if (e) e.preventDefault();

    if (isCreatingClass) {
        alert('이미 학급 생성이 진행 중입니다. 잠시만 기다려 주세요.');
        return;
    }

    const input = document.getElementById('classNamesInput');
    const rawVal = (input ? input.value : '').trim();
    if (!rawVal) {
        alert('학급명을 입력해 주세요.');
        return;
    }

    const names = rawVal.split(',').map(s => s.trim()).filter(Boolean);
    if (names.length === 0) {
        alert('유효한 학급명을 입력해 주세요.');
        return;
    }

    isCreatingClass = true;
    const submitBtn = document.getElementById('btnSubmitCreateClass');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = '요청 중...';
    }

    // 1. 모달 즉시 닫기
    closeModal('addClassModal');

    // 2. 인풋 초기화
    if (input) input.value = '';

    // 3. 대시보드 화면에 스켈레톤 진행 카드 즉시 렌더링
    renderCreatingClassPlaceholders(names);

    // 4. 백그라운드 비동기 요청 전송
    try {
        const response = await fetch('/api/classes/create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ class_names: rawVal })
        });

        let data = null;
        try {
            data = await response.json();
        } catch (parseErr) {
            console.error('응답 파싱 오류:', parseErr);
        }

        if (data && data.success) {
            showToast('학급이 성공적으로 생성되었습니다.');
            setTimeout(() => {
                location.reload();
            }, 600);
        } else {
            const errMsg = (data && (data.error || data.message))
                ? (data.error || data.message)
                : '학급 생성 중 오류가 발생했습니다.';
            alert(errMsg);
            location.reload();
        }
    } catch (err) {
        console.error('학급 생성 에러:', err);
        alert('학급 생성 중 통신 오류가 발생했습니다. 페이지를 새로고침하여 상태를 확인해 주세요.');
        location.reload();
    } finally {
        isCreatingClass = false;
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = '생성하기';
        }
    }
}

function openSingleEditModal(classId, className) {
    document.getElementById('editClassId').value = classId;
    document.getElementById('editClassName').value = className;
    document.getElementById('editClassModal').style.display = 'flex';
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'none';
}

async function deleteClass() {
    const classId = document.getElementById('editClassId').value;
    const className = document.getElementById('editClassName').value;
    if (!classId) return;

    const confirmMsg = `[${className}] 학급을 정말로 삭제하시겠습니까?\n\n` +
        `※ 학급에 속한 모든 학생 및 시험 데이터가 비활성화됩니다.\n` +
        `※ 구글 드라이브의 기존 학급 폴더는 데이터 보존을 위해 '[삭제됨]' 상태로 안전하게 보관됩니다.`;

    if (!confirm(confirmMsg)) {
        return;
    }

    try {
        const res = await fetch('/api/classes/delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ class_id: classId })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('editClassModal');
            showToast(data.message || '학급이 삭제되었습니다.');
            setTimeout(() => {
                location.reload();
            }, 400);
        } else {
            alert('삭제 오류: ' + (data.error || data.message || '알 수 없는 오류가 발생했습니다.'));
        }
    } catch (err) {
        console.error('학급 삭제 에러:', err);
        alert('서버 통신 오류가 발생했습니다.');
    }
}

// --- [구글 드라이브 폴더 선택 모달] ---
function openFolderModal() {
    const select = document.getElementById('folderSelect');
    select.innerHTML = '<option value="">폴더 목록을 불러오는 중...</option>';
    document.getElementById('folderModal').style.display = 'flex';

    fetch('/api/google/get_folders.php')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                select.innerHTML = '<option value="">-- 이동할 폴더를 선택하세요 --</option>';
                data.folders.forEach(f => {
                    const opt = document.createElement('option');
                    opt.value = f.id;
                    opt.textContent = `📁 ${f.name}`;
                    opt.dataset.name = f.name;
                    select.appendChild(opt);
                });
            } else {
                select.innerHTML = `<option value="">오류: ${data.error || '폴더 목록 로드 실패'}</option>`;
            }
        })
        .catch(() => {
            select.innerHTML = '<option value="">통신 오류 발생</option>';
        });
}

const folderSelectEl = document.getElementById('folderSelect');
if (folderSelectEl) {
    folderSelectEl.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const nameInput = document.getElementById('newFolderName');
        if (nameInput) nameInput.value = selected.dataset.name || '';
    });
}

// --- [복수 학급 선택 드롭다운] ---
function toggleClassDropdown() {
    const el = document.getElementById('classDropdown');
    if (el) el.classList.toggle('show');
}

window.addEventListener('click', function(e) {
    const container = document.querySelector('.multi-select-container');
    const dropdown = document.getElementById('classDropdown');
    if (container && dropdown && !container.contains(e.target)) {
        dropdown.classList.remove('show');
    }
});

function toggleAllClasses(master) {
    const checkboxes = document.querySelectorAll('.class-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
    updateSelectLabel();
}

function updateSelectLabel() {
    const checked = document.querySelectorAll('.class-checkbox:checked');
    const total = document.querySelectorAll('.class-checkbox').length;
    const label = document.getElementById('selectLabel');
    const master = document.getElementById('selectAllClasses');

    if (checked.length === 0) {
        label.innerText = '-- 학급 선택 --';
        if (master) { master.checked = false; master.indeterminate = false; }
    } else if (checked.length === total) {
        label.innerText = `전체 학급 (${checked.length}개)`;
        if (master) { master.checked = true; master.indeterminate = false; }
    } else {
        label.innerText = `${checked.length}개 학급 선택됨`;
        if (master) { master.checked = false; master.indeterminate = true; }
    }
}

// --- [결과 마감 및 복원 모던 진행 표시 UI] ---
function showTaskLoadingOverlay(title, descList) {
    const existing = document.getElementById('taskLoadingOverlay');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'taskLoadingOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(15,23,42,0.75);backdrop-filter:blur(4px);z-index:99999;display:flex;align-items:center;justify-content:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;';

    const descHtml = descList.map((item, idx) => `
        <div id="loadingStep_${idx}" style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:14px;color:#94a3b8;">
            <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#38bdf8;"></span>
            <span>${item}</span>
        </div>
    `).join('');

    overlay.innerHTML = `
        <div style="background:#ffffff;border-radius:16px;padding:32px 36px;max-width:440px;width:90%;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);text-align:center;">
            <div style="width:48px;height:48px;border:4px solid #e2e8f0;border-top-color:#3b82f6;border-radius:50%;margin:0 auto 16px;animation:spinOverlay 0.9s linear infinite;"></div>
            <h3 style="margin:0 0 10px;font-size:18px;font-weight:700;color:#1e293b;">${title}</h3>
            <div style="text-align:left;background:#f8fafc;border-radius:10px;padding:12px 16px;border:1px solid #e2e8f0;">
                 ${descHtml}
            </div>
            <p style="margin:16px 0 0;font-size:13px;color:#64748b;">창을 닫거나 새로고침하지 마시고 잠시만 기다려주세요.</p>
        </div>
        <style>
            @keyframes spinOverlay { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>
    `;
}

function hideTaskLoadingOverlay() {
    const existing = document.getElementById('taskLoadingOverlay');
    if (existing) existing.remove();
}

function closeAssignment(assignId) {
    const msg = "정말로 이 평가를 마감하시겠습니까?\n\n마감 시 모든 채점 결과가 구글 시트로 백업되며, 서버에 저장된 채점 데이터와 이미지 원본은 보안을 위해 완전 파기됩니다.";
    if (!confirm(msg)) return;

    showTaskLoadingOverlay("평가 결과 마감 및 보안 백업", [
        "구글 드라이브 비식별 가명 파일 초고속 병렬 백업",
        "구글 시트 문항 점수 및 원본 하이퍼링크 기록",
        "서버 로컬 원본 및 크롭 이미지 안전 파기 (Zero-PII)"
    ]);

    fetch('/api/google/sync_sheet.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ assign_id: assignId })
    })
    .then(res => res.json())
    .then(data => {
        hideTaskLoadingOverlay();
        if (data.success) {
            alert("✅ " + (data.message || "마감 처리가 완료되었습니다."));
            location.reload();
        } else {
            alert("❌ 마감 오류: " + (data.message || data.error || "알 수 없는 오류가 발생했습니다."));
        }
    })
    .catch(err => {
        hideTaskLoadingOverlay();
        console.error(err);
        alert("서버와 통신하는 중 오류가 발생했습니다.");
    });
}

function restoreData(assignId) {
    if (!confirm("구글 드라이브와 시트에서 데이터를 복원하시겠습니까?")) return;

    showTaskLoadingOverlay("평가 데이터 복원", [
        "구글 시트 채점 데이터 분석",
        "구글 드라이브 원본 시험지 다운로드",
        "문항별 크롭 이미지 정밀 재구성"
    ]);

    fetch('/api/google/restore_sheet.php?assign_id=' + assignId)
        .then(res => res.json())
        .then(data => {
            hideTaskLoadingOverlay();
            if (data.success) {
                alert("✅ " + data.message);
                location.reload();
            } else {
                alert("❌ 복원 오류: " + data.error);
            }
        })
        .catch(err => {
            hideTaskLoadingOverlay();
            console.error(err);
            alert("서버 통신 중 오류가 발생했습니다.");
        });
}

// --- [QR 팝업 띄우기 (반응형 중앙)] ---
function openQrPopup(assignId) {
    const url = `/views/input/popup_qr.php?assign_id=${assignId}`;
    const width = 450;
    const height = 500;
    const left = Math.round((screen.width - width) / 2);
    const top = Math.round((screen.height - height) / 2);
    window.open(url, `QR_Popup_${assignId}`, `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no`);
}

function showQR(url, title) {
    const container = document.getElementById('qrcode-container');
    const titleEl = document.getElementById('qrModalTitle');
    if (titleEl) titleEl.innerText = title || '접속 QR 코드';
    if (!container) return;
    container.innerHTML = '';

    new QRCode(container, {
        text: url,
        width: 220,
        height: 220,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
    });

    document.getElementById('qrModal').style.display = 'flex';
}

// --- [스마트 실시간 현황 경량 폴링 (JSON 3초 폴링)] ---
setInterval(() => {
    fetch('/api/classes/get_status.php')
        .then(res => res.json())
        .then(data => {
            if (data.success && Array.isArray(data.assignments)) {
                data.assignments.forEach(item => {
                    const assignId = item.assignment_id;
                    const subEls = document.querySelectorAll(`#submitted-count-${assignId}, .submitted-count-${assignId}`);
                    const pendEls = document.querySelectorAll(`#pending-count-${assignId}, .pending-count-${assignId}`);
                    
                    subEls.forEach(el => el.textContent = `${item.submitted_count}명`);
                    pendEls.forEach(el => {
                        el.textContent = `${item.pending_review_count}명`;
                        if (item.pending_review_count > 0) {
                            el.style.color = '#dc3545';
                            el.style.fontWeight = 'bold';
                        } else {
                            el.style.color = '#16a34a';
                        }
                    });
                });
            }
        })
        .catch(() => {});
}, 3000);


// =============================================================================
// =============================================================================
// [★ 신규 기능] 스마트 학생 명단 간편 관리 (2단 탭: 명단/PIN 관리 + 텍스트 일괄 등록)
// =============================================================================

let currentRosterClassId = 0;
let parsedStudentList = [];

function switchRosterTab(tabName) {
    const tabBtnList = document.getElementById('tabBtnList');
    const tabBtnEdit = document.getElementById('tabBtnEdit');
    const rosterTabList = document.getElementById('rosterTabList');
    const rosterTabEdit = document.getElementById('rosterTabEdit');

    if (tabName === 'list') {
        if (tabBtnList) tabBtnList.classList.add('active');
        if (tabBtnEdit) tabBtnEdit.classList.remove('active');
        if (rosterTabList) rosterTabList.classList.add('active');
        if (rosterTabEdit) rosterTabEdit.classList.remove('active');
    } else {
        if (tabBtnList) tabBtnList.classList.remove('active');
        if (tabBtnEdit) tabBtnEdit.classList.add('active');
        if (rosterTabList) rosterTabList.classList.remove('active');
        if (rosterTabEdit) rosterTabEdit.classList.add('active');
        parseRosterText();
    }
}

function openStudentRosterModal(classId, className) {
    currentRosterClassId = classId;
    parsedStudentList = [];

    const titleEl = document.getElementById('rosterModalTitle');
    if (titleEl) titleEl.innerText = `👥 [${className}] 학생 명단 간편 관리`;

    switchRosterTab('list');
    document.getElementById('studentRosterModal').style.display = 'flex';

    loadStudentRoster(classId);
}

function loadStudentRoster(classId) {
    const tbody = document.getElementById('studentManageTbody');
    const tabCount = document.getElementById('tabStudentCount');
    const textarea = document.getElementById('rosterTextarea');

    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:25px; color:#94a3b8;">학생 목록을 불러오는 중...</td></tr>';
    }

    fetch(`/api/classes/get_students.php?class_id=${classId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success && Array.isArray(data.students)) {
                const list = data.students;
                if (tabCount) tabCount.textContent = list.length;

                if (list.length === 0) {
                    if (tbody) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="4" style="text-align:center; padding:35px; color:#64748b;">
                                    <p style="margin-bottom:10px;">등록된 학생이 없습니다.</p>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="switchRosterTab('edit')">
                                        ✍️ 명단 일괄 등록하기
                                    </button>
                                </td>
                            </tr>
                        `;
                    }
                    if (textarea) {
                        textarea.value = '';
                        textarea.placeholder = "여기에 엑셀이나 나이스에서 복사한 명단을 그대로 붙여넣으세요 (Ctrl+V)\n\n예시 1 (이름만):\n김철수\n이영희\n\n예시 2 (이름과 PIN):\n김철수\t1234\n이영희\t5678";
                    }
                    // 등록된 학생이 없으면 바로 텍스트 등록 탭으로 전환
                    switchRosterTab('edit');
                } else {
                    if (tbody) {
                        let html = '';
                        list.forEach((s, idx) => {
                            const isInit = parseInt(s.is_initial_pin) === 1;
                            const pinBadge = isInit
                                ? `<span class="badge" style="background:#fd7e14; color:#fff; font-size:0.75rem; padding:3px 6px; border-radius:4px;">초기 PIN(미변경)</span>`
                                : `<span class="badge" style="background:#198754; color:#fff; font-size:0.75rem; padding:3px 6px; border-radius:4px;">학생 변경 완료</span>`;

                            html += `
                                <tr>
                                    <td style="color:#64748b; font-weight:bold;">${idx + 1}</td>
                                    <td style="font-weight:700; color:#1e293b;">${escapeHtml(s.name)}</td>
                                    <td>${pinBadge}</td>
                                    <td style="text-align:center;">
                                        <button type="button" class="btn btn-sm btn-outline-danger" style="padding:2px 8px; font-size:0.8rem;" onclick="resetStudentPin(${s.id}, '${escapeHtml(s.name)}')">
                                            🔑 PIN 초기화
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    }

                    // 텍스트 탭에도 기존 명단 동기화
                    if (textarea) {
                        const lines = list.map(s => (s.pin ? `${s.name}\t${s.pin}` : s.name));
                        textarea.value = lines.join('\n');
                    }
                }
                parseRosterText();
            } else {
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:25px; color:#dc3545;">학생 목록 조회 실패: ${data.error || '오류 발생'}</td></tr>`;
                }
            }
        })
        .catch(() => {
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:25px; color:#dc3545;">통신 오류가 발생했습니다.</td></tr>';
            }
        });
}

function resetStudentPin(studentId, studentName) {
    const newPin = prompt(`[${studentName}] 학생의 새 4자리 비밀번호(PIN)를 입력하세요.\n(초기화 후 학생이 로그인할 때 이 번호를 입력하고 다시 본인 비밀번호를 설정하게 됩니다)`, '0000');
    if (newPin === null) return;

    const trimmed = newPin.trim();
    if (!/^\d{4}$/.test(trimmed)) {
        alert('비밀번호는 4자리 숫자여야 합니다.');
        return;
    }

    fetch('/api/classes/reset_student_pin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            student_id: studentId,
            new_pin: trimmed
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + (data.message || '비밀번호가 초기화되었습니다.'));
            loadStudentRoster(currentRosterClassId);
        } else {
            alert('❌ 초기화 실패: ' + (data.error || data.message || '오류가 발생했습니다.'));
        }
    })
    .catch(() => alert('서버 통신 오류가 발생했습니다.'));
}

function parseRosterText() {
    const raw = document.getElementById('rosterTextarea')?.value || '';
    const defaultPin = (document.getElementById('defaultPinInput')?.value || '0000').trim();
    const warningEl = document.getElementById('rosterWarning');
    const tbody = document.getElementById('rosterPreviewTbody');
    const countBadge = document.getElementById('rosterCountBadge');
    const saveBtn = document.getElementById('rosterSaveBtn');

    if (!tbody || !countBadge) return;

    const lines = raw.split(/\r?\n/);
    parsedStudentList = [];
    const seenNames = new Map();
    let hasDuplicate = false;
    let dupName = '';

    lines.forEach((line) => {
        let trimmed = line.trim();
        if (!trimmed) return;

        // 앞쪽 번호 패턴 (예: "1.", "1 ", "1번 ", "01-") 제거
        trimmed = trimmed.replace(/^\d+[\.\s번\-]+/, '').trim();
        if (!trimmed) return;

        // 탭, 쉼표 등으로 분리
        const parts = trimmed.split(/[\t,]+/).map(p => p.trim()).filter(Boolean);
        let name = '';
        let pin = defaultPin;

        if (parts.length >= 2) {
            name = parts[0];
            pin = parts[1];
        } else {
            // 공백 분리 시도 (예: "김철수 1234")
            const spaceParts = trimmed.split(/\s+/);
            if (spaceParts.length >= 2 && /^\d{4}$/.test(spaceParts[spaceParts.length - 1])) {
                pin = spaceParts.pop();
                name = spaceParts.join(' ');
            } else {
                name = trimmed;
            }
        }

        name = name.trim();
        if (!name) return;

        // 중복 이름 체크
        if (seenNames.has(name)) {
            hasDuplicate = true;
            dupName = name;
        } else {
            seenNames.set(name, true);
        }

        parsedStudentList.push({ name, pin });
    });

    // 미리보기 렌더링
    tbody.innerHTML = '';
    parsedStudentList.forEach((s, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="color:#64748b; font-weight:bold;">${idx + 1}</td>
            <td style="font-weight:700; color:#1e293b;">${escapeHtml(s.name)}</td>
            <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:bold;">${escapeHtml(s.pin)}</code></td>
        `;
        tbody.appendChild(tr);
    });

    countBadge.textContent = `${parsedStudentList.length}명 인식됨`;

    if (warningEl && saveBtn) {
        if (hasDuplicate) {
            warningEl.textContent = `⚠️ 중복된 이름('${dupName}')이 있습니다. 학생 식별을 위해 '${dupName}1', '${dupName}2' 또는 '${dupName}A'로 고유하게 수정해주세요.`;
            warningEl.style.display = 'block';
            saveBtn.disabled = true;
        } else {
            warningEl.style.display = 'none';
            saveBtn.disabled = (parsedStudentList.length === 0);
        }
    }
}

function saveStudentRoster() {
    if (!currentRosterClassId || parsedStudentList.length === 0) {
        alert('저장할 학생 명단이 없습니다.');
        return;
    }

    const saveBtn = document.getElementById('rosterSaveBtn');
    saveBtn.disabled = true;
    saveBtn.innerText = '저장 및 동기화 중...';

    fetch('/api/classes/sync_students.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            class_id: currentRosterClassId,
            students: parsedStudentList
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('✅ ' + (data.message || '학생 명단이 성공적으로 저장되었습니다.'));
            closeModal('studentRosterModal');
            setTimeout(() => location.reload(), 800);
        } else {
            alert('❌ 저장 실패: ' + (data.error || data.message || '오류가 발생했습니다.'));
            saveBtn.disabled = false;
            saveBtn.innerText = '학생 명단 일괄 저장';
        }
    })
    .catch(err => {
        console.error(err);
        alert('서버 통신 오류가 발생했습니다.');
        saveBtn.disabled = false;
        saveBtn.innerText = '학생 명단 일괄 저장';
    });
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function onTemplateSelectChange(templateId) {
    const editBtn = document.getElementById('btnEditSelectedTemplate');
    const wrapper = document.getElementById('selectedTemplateEditWrapper');
    if (templateId) {
        if (editBtn) {
            editBtn.href = `/views/templates/editor.php?id=${templateId}`;
            editBtn.style.display = 'inline-flex';
        }
        if (wrapper) wrapper.style.display = 'block';
    } else {
        if (editBtn) {
            editBtn.style.display = 'none';
            editBtn.href = '#';
        }
        if (wrapper) wrapper.style.display = 'none';
    }
}

// [신규] 교사 메인 대시보드에서 학생 모범답안 공개 여부 원클릭 비동기 토글
function toggleDashboardAnswer(assignId, checkboxEl) {
    const isChecked = checkboxEl.checked;
    const parentLabel = checkboxEl.closest('label');
    
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
            checkboxEl.checked = !isChecked;
        } else {
            showToast(data.message || (isChecked ? '💡 학생에게 모범 답안이 공개되었습니다.' : '🔒 학생 화면에서 모범 답안이 숨김 처리되었습니다.'));
            if (parentLabel) {
                parentLabel.style.borderColor = isChecked ? '#8b5cf6' : '#cbd5e1';
                parentLabel.style.color = isChecked ? '#6d28d9' : '#64748b';
            }
            // 데스크톱 / 모바일 체크박스 양방향 동기화
            const otherId = checkboxEl.id.startsWith('chkShowAnswerMobile') 
                ? `chkShowAnswer_${assignId}` 
                : `chkShowAnswerMobile_${assignId}`;
            const otherChk = document.getElementById(otherId);
            if (otherChk) {
                otherChk.checked = isChecked;
                const otherLabel = otherChk.closest('label');
                if (otherLabel) {
                    otherLabel.style.borderColor = isChecked ? '#8b5cf6' : '#cbd5e1';
                    otherLabel.style.color = isChecked ? '#6d28d9' : '#64748b';
                }
            }
        }
    })
    .catch(err => {
        console.error(err);
        alert('서버 통신 오류가 발생했습니다.');
        checkboxEl.checked = !isChecked;
    });
}

// [신규] 모바일 대시보드 세그먼트 탭 전환 함수
function switchMobileTab(tabName, btnEl) {
    document.querySelectorAll('.segment-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));

    if (btnEl) {
        btnEl.classList.add('active');
    }

    if (tabName === 'assignments') {
        const sec = document.getElementById('tabSectionAssignments');
        if (sec) sec.classList.add('active');
    } else if (tabName === 'classes') {
        const sec = document.getElementById('tabSectionClasses');
        if (sec) sec.classList.add('active');
    } else if (tabName === 'settings') {
        const sec = document.getElementById('tabSectionSettings');
        if (sec) sec.classList.add('active');
    }
}

// =============================================================================
// [★ 신규 기능] 학생 접속 및 개별 제출 전용 통합 공유 모달 (QR & 링크 복사)
// =============================================================================
let currentShareAssignId = 0;
let currentShareClassId = 0;
let currentShareUrl = '';
let currentShareLinkName = '접속 링크';

function openShareSheet(assignId, examTitle, className, shareUrl) {
    currentShareAssignId = assignId;
    currentShareClassId = 0;
    currentShareUrl = shareUrl;
    currentShareLinkName = '학생 개별 제출 링크';

    const titleEl = document.getElementById('shareModalExamTitle');
    const classEl = document.getElementById('shareModalClassTitle');
    if (titleEl) titleEl.innerText = `📱 ${examTitle} (${className})`;
    if (classEl) classEl.innerText = `학생이 각자의 기기로 직접 접속하여 답안지를 제출합니다.`;

    renderShareQr(shareUrl);
}

function openClassShareSheet(classId, className, shareUrl) {
    currentShareClassId = classId;
    currentShareAssignId = 0;
    currentShareUrl = shareUrl;
    currentShareLinkName = '내 공부방 접속 링크';

    const titleEl = document.getElementById('shareModalExamTitle');
    const classEl = document.getElementById('shareModalClassTitle');
    if (titleEl) titleEl.innerText = `🏫 ${className} 내 공부방 안내`;
    if (classEl) classEl.innerText = `학생들이 각자의 기기로 접속할 수 있는 학급 전용 포털입니다.`;

    renderShareQr(shareUrl);
}

function renderShareQr(url) {
    const qrContainer = document.getElementById('shareSheetQrcodeContainer');
    if (qrContainer) {
        qrContainer.innerHTML = '';
        new QRCode(qrContainer, {
            text: url,
            width: 170,
            height: 170,
            colorDark: "#0f172a",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    const modal = document.getElementById('studentShareModal');
    if (modal) {
        modal.style.display = 'flex';
    }
}

function copyCurrentShareLink() {
    if (currentShareUrl) {
        copyToClipboard(currentShareUrl, currentShareLinkName || '접속 링크');
        closeModal('studentShareModal');
    }
}

function openTvQrFromShare() {
    closeModal('studentShareModal');
    if (currentShareClassId) {
        openClassQrPopup(currentShareClassId);
    } else if (currentShareAssignId) {
        openQrPopup(currentShareAssignId);
    }
}

function openClassQrPopup(classId) {
    const url = `/views/input/popup_qr.php?class_id=${classId}`;
    const width = 520;
    const height = 620;
    const left = Math.round((screen.width - width) / 2);
    const top = Math.round((screen.height - height) / 2);
    window.open(url, `ClassQR_Popup_${classId}`, `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no`);
}


