/**
 * 이어봄 관리자 통합 Google Sheet 메뉴 · ver7
 * 이 파일은 관리자 통합 시트에 바인딩된 별도 Apps Script 프로젝트의
 * Code.gs 전체 파일입니다. 학생·교사 앱 프로젝트에 넣지 마세요.
 */

const YB_MENU_NAME = "🏫 [학교 관리자]";
const YB_DB_HEADERS = ["타임스탬프", "학번", "성명", "이메일"];
const YB_EXTRA_HEADERS = ["학생 제출 본문", "AI 변환 결과", "처리 상태"];
const YB_ADMIN_EMAILS_KEY = "SCHOOL_ADMIN_EMAILS";
const YB_PROMPT_DRAFT_KEY = "STUDENT_CONVERSION_PROMPT_DRAFT";
const YB_CLASS_ACCESS_TAB = "_TeacherAccess";
const YB_MASTER_ACCESS_TAB = "TeacherAccess";
const YB_BODY_BYTE_LIMIT = 45000;

function onOpen(event) {
  try {
    SpreadsheetApp.getUi()
      .createMenu(YB_MENU_NAME)
      .addItem("1. 학생부 변환 프롬프트 편집기 열기 (대시보드)", "openPromptEditorDashboard")
      .addItem("2. 시스템 초기화 및 자가 치유 (Self-Healing)", "initAndSelfHealSystem")
      .addItem("3. 학생 제출 현황 및 바이트 검증 점검", "checkSubmissionAndBytes")
      .addSeparator()
      .addItem("4. 교사 관리자 계정 등록 및 권한 확인", "manageTeacherPermissions")
      .addItem("5. 프롬프트 불변 스냅샷 공식 게시", "publishImmutableSnapshot")
      .addItem("6. 시스템 자가 진단 (System Doctor 🩺)", "runSystemDoctor")
      .addItem("7. 엔진 무결성 합성 단위 테스트 실행", "runSyntheticUnitTests")
      .addToUi();
  } catch (error) {
    console.error("학교 관리자 메뉴 생성 실패", error && error.name);
  }
}

function openPromptEditorDashboard() {
  return ybAdminAction_("프롬프트 편집기", function () {
    ybRequireAdmin_();
    const output = HtmlService.createHtmlOutput(ybPromptEditorHtml_()).setTitle("학생부 변환 프롬프트 편집기");
    SpreadsheetApp.getUi().showSidebar(output);
    ybToast_("프롬프트 편집기를 열었습니다.");
  });
}

function saveStudentPromptDraft(text) {
  ybRequireAdmin_();
  const prompt = String(text || "").trim();
  if (!prompt) return { ok: false, message: "프롬프트 내용을 입력해 주세요." };
  if (ybUtf8Bytes_(prompt) > YB_BODY_BYTE_LIMIT) return { ok: false, message: "프롬프트는 45,000바이트 이하로 입력해 주세요." };
  PropertiesService.getDocumentProperties().setProperty(YB_PROMPT_DRAFT_KEY, prompt);
  ybToast_("프롬프트 초안을 저장했습니다.");
  return { ok: true, message: "초안을 저장했습니다. 공식 게시 전까지는 처리에 적용되지 않습니다." };
}

function initAndSelfHealSystem() {
  return ybAdminAction_("시스템 초기화 및 자가 치유", function () {
    ybRequireAdmin_();
    const book = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = book.getActiveSheet();
    if (ybIsProtectedDataSheet_(sheet)) {
      throw new Error("현재 시트는 학생 명단 또는 권한 자료로 보입니다. 수정하지 않았습니다. 제출 기록 시트를 선택하세요.");
    }

    if (sheet.getMaxColumns() < 7) sheet.insertColumnsAfter(sheet.getMaxColumns(), 7 - sheet.getMaxColumns());
    const oldHeaders = sheet.getRange(1, 1, 1, Math.max(7, sheet.getLastColumn())).getDisplayValues()[0];
    const repairNeeded = YB_DB_HEADERS.some((header, index) => String(oldHeaders[index] || "").trim() !== header);
    if (repairNeeded && oldHeaders.slice(0, 4).some((value) => String(value || "").trim())) {
      ybBackupHeaderRow_(book, sheet.getName(), oldHeaders);
    }

    sheet.getRange(1, 1, 1, 4).setValues([YB_DB_HEADERS]);
    YB_EXTRA_HEADERS.forEach((header, index) => {
      if (!String(oldHeaders[index + 4] || "").trim()) sheet.getRange(1, index + 5).setValue(header);
    });
    const width = Math.max(7, sheet.getLastColumn());
    sheet.getRange(1, 1, 1, width)
      .setBackground("#5C1E1E")
      .setFontColor("#FFFFFF")
      .setFontWeight("bold")
      .setHorizontalAlignment("center")
      .setVerticalAlignment("middle");
    sheet.setFrozenRows(1);
    sheet.setRowHeight(1, 34);
    if (sheet.getMaxRows() > 1) sheet.getRange(2, 1, sheet.getMaxRows() - 1, 1).setNumberFormat("yyyy. MM. dd. HH:mm:ss");

    ybToast_(repairNeeded ? "필수 기록 헤더를 복구했습니다." : "기록 헤더와 스타일을 확인했습니다.");
    ybAlert_(repairNeeded
      ? "초기화 완료: A:D 헤더를 복구했습니다. 복구 전 헤더는 숨김 백업 탭에 보관했습니다."
      : "확인 완료: A:D 헤더, 스타일, 첫 행 고정을 적용했습니다.");
  });
}

function checkSubmissionAndBytes() {
  return ybAdminAction_("학생 제출 현황 및 바이트 검증", function () {
    ybRequireAdmin_();
    const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    if (ybIsProtectedDataSheet_(sheet)) throw new Error("명단·권한 시트는 점검하지 않습니다. 제출 기록 시트를 선택하세요.");
    const lastRow = sheet.getLastRow();
    const lastColumn = sheet.getLastColumn();
    if (lastRow < 2 || lastColumn < 1) return ybAlert_("제출 기록이 없습니다.");
    const headers = sheet.getRange(1, 1, 1, lastColumn).getDisplayValues()[0];
    const bodyIndex = headers.findIndex((value) => ["학생 제출 본문", "제출 본문", "답안 원문", "submission_text"].includes(String(value || "").trim().toLowerCase()));
    if (bodyIndex < 0) throw new Error("학생 제출 본문 열을 찾지 못했습니다. E열 이후 헤더를 확인하세요.");

    let count = 0;
    let largest = 0;
    let oversized = 0;
    sheet.getRange(2, bodyIndex + 1, lastRow - 1, 1).getDisplayValues().flat().forEach((text) => {
      if (!String(text || "").trim()) return;
      count += 1;
      const bytes = ybUtf8Bytes_(String(text));
      largest = Math.max(largest, bytes);
      if (bytes > YB_BODY_BYTE_LIMIT) oversized += 1;
    });
    ybAlert_(`점검 완료\n제출 답안: ${count}건\n최대 크기: ${largest.toLocaleString("ko-KR")}바이트\n45,000바이트 초과: ${oversized}건\n답안 내용은 표시하지 않았습니다.`);
  });
}

function manageTeacherPermissions() {
  return ybAdminAction_("교사·관리자 계정 등록 및 권한 확인", function () {
    const admin = ybRequireAdmin_();
    const ui = SpreadsheetApp.getUi();
    const rolePrompt = ui.prompt("계정 역할", "teacher(교사 승인), admin(관리자 추가), revoke(교사 접근 회수) 중 하나를 입력하세요.", ui.ButtonSet.OK_CANCEL);
    if (rolePrompt.getSelectedButton() !== ui.Button.OK) return;
    const role = String(rolePrompt.getResponseText() || "").trim().toLowerCase();
    if (!["teacher", "admin", "revoke"].includes(role)) throw new Error("teacher, admin, revoke 중 하나를 입력하세요.");
    const email = ybPromptEmail_(ui);
    if (!email) return;

    if (role === "admin") {
      const masterBook = SpreadsheetApp.getActiveSpreadsheet();
      const masterFile = DriveApp.getFileById(masterBook.getId());
      const masterOwner = masterFile.getOwner() ? ybNormalizeEmail_(masterFile.getOwner().getEmail()) : "";
      if (masterOwner !== email) masterFile.addEditor(email);
      const admins = ybAdminEmails_();
      if (!admins.includes(email)) admins.push(email);
      PropertiesService.getScriptProperties().setProperty("SCHOOL_ADMIN_EMAILS", admins.join(","));
      ybEnsureMasterAccess_({ email, displayName: email, className: "", classCode: "", spreadsheetId: "", status: "admin", adminEmail: admin.email });
      return ybAlert_("관리자 계정을 등록하고 통합 Sheet의 편집 권한을 공유했습니다. 이메일은 로그나 안내창에 표시하지 않았습니다.");
    }

    if (role === "revoke") {
      const spreadsheetId = ybPromptText_(ui, "담당 학급 파일 ID", "교사 권한을 회수할 담당 학급 Sheet ID를 입력하세요.");
      if (!spreadsheetId) return;
      ybDisableTeacherRegistration_({ email, spreadsheetId, adminEmail: admin.email });
      ybToast_("교사 접근을 비활성화했습니다.");
      return ybAlert_("교사 앱 권한을 비활성화하고 담당 파일 공유 권한을 회수했습니다.");
    }

    const displayName = ybPromptText_(ui, "교사 표시 이름", "시트에 표시할 교사 이름을 입력하세요.");
    if (!displayName) return;
    const className = ybPromptText_(ui, "담당 학급", "예: 2학년 3반");
    if (!className) return;
    const spreadsheetId = ybPromptText_(ui, "담당 학급 파일 ID", "Students 명단이 있는 학급 Sheet ID를 입력하세요. 로그에는 저장하지 않습니다.");
    if (!spreadsheetId) return;
    const record = { email, displayName, className, spreadsheetId, adminEmail: admin.email };
    ybWriteTeacherRegistration_(record);
    ybToast_("교사 계정과 담당 학급을 승인했습니다.");
    ybAlert_("완료: 담당 파일 공유와 보호된 _TeacherAccess 탭을 설정했습니다. 교사 Apps Script는 ver7 Code.gs로 업데이트해야 합니다.");
  });
}

function publishImmutableSnapshot() {
  return ybAdminAction_("프롬프트 공식 스냅샷", function () {
    const admin = ybRequireAdmin_();
    const prompt = PropertiesService.getDocumentProperties().getProperty(YB_PROMPT_DRAFT_KEY) || "";
    if (!prompt.trim()) throw new Error("먼저 프롬프트 편집기에서 초안을 저장해 주세요.");
    const book = SpreadsheetApp.getActiveSpreadsheet();
    const stamp = Utilities.formatDate(new Date(), book.getSpreadsheetTimeZone(), "yyyyMMdd_HHmmss");
    const snapshot = book.insertSheet(ybUniqueSheetName_(book, `PromptSnapshot_${stamp}`));
    const checksum = ybHexBytes_(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, prompt, Utilities.Charset.UTF_8));
    snapshot.getRange("A1:B5").setValues([["항목", "공식 게시 값"], ["버전", `prompt-${stamp}`], ["게시 시각", new Date()], ["게시자", admin.email], ["SHA-256", checksum]]);
    snapshot.getRange("A7").setValue("학생부 변환 프롬프트");
    snapshot.getRange("A8").setValue(prompt).setWrap(true).setVerticalAlignment("top");
    snapshot.getRange("A1:B1").setBackground("#5C1E1E").setFontColor("#FFFFFF").setFontWeight("bold");
    snapshot.setColumnWidth(1, 190);
    snapshot.setColumnWidth(2, 720);
    snapshot.setRowHeight(8, 280);
    snapshot.setFrozenRows(1);
    ybProtectSheet_(snapshot, admin.email, "공식 프롬프트 스냅샷 - 수정 제한");
    ybAlert_(`프롬프트 스냅샷을 보호 상태로 게시했습니다.\n버전: prompt-${stamp}\nSHA-256 지문을 기록했습니다. 소유자는 보호를 변경할 수 있습니다.`);
  });
}

function runSystemDoctor() {
  return ybAdminAction_("시스템 자가 진단", function () {
    ybRequireAdmin_();
    const book = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = book.getActiveSheet();
    const report = [
      `관리자 목록: ${ybAdminEmails_().length ? "설정됨" : "미설정"}`,
      `활성 시트: ${ybIsProtectedDataSheet_(sheet) ? "명단·권한 자료 (초기화 금지)" : "기록 시트"}`,
      `전체 탭 수: ${book.getSheets().length}`,
      `시간대: ${book.getSpreadsheetTimeZone()}`
    ];
    if (!ybIsProtectedDataSheet_(sheet)) {
      const headers = sheet.getRange(1, 1, 1, Math.max(4, sheet.getLastColumn())).getDisplayValues()[0];
      report.push(`A:D 헤더: ${YB_DB_HEADERS.every((header, index) => String(headers[index] || "").trim() === header) ? "정상" : "복구 필요"}`);
    }
    ybAlert_("시스템 진단 결과\n" + report.map((line) => `• ${line}`).join("\n"));
  });
}

function runSyntheticUnitTests() {
  return ybAdminAction_("엔진 무결성 합성 테스트", function () {
    ybRequireAdmin_();
    const tests = [
      ["헤더 정규화", ybNormalizeHeader_(" 학번 ") === "학번"],
      ["이메일 정규화", ybNormalizeEmail_(" Teacher@Example.edu ") === "teacher@example.edu"],
      ["UTF-8 바이트 계산", ybUtf8Bytes_("과학") === 6],
      ["CSV 수식 보호", ybCsvSafeCell_("=1+1").startsWith("'")],
      ["바이트 상한", ybUtf8Bytes_("a".repeat(YB_BODY_BYTE_LIMIT)) === YB_BODY_BYTE_LIMIT]
    ];
    const failed = tests.filter(([, passed]) => !passed).map(([name]) => name);
    ybAlert_(failed.length ? `테스트 실패: ${failed.join(", ")}` : `합성 테스트 ${tests.length}개 통과. 실제 학생 자료는 사용하지 않았습니다.`);
    if (failed.length) throw new Error(`합성 테스트 실패: ${failed.join(", ")}`);
  });
}

function ybEnsureClassAccess_(book, record) {
  let sheet = book.getSheetByName(YB_CLASS_ACCESS_TAB);
  if (!sheet) sheet = book.insertSheet(YB_CLASS_ACCESS_TAB);
  const headers = ["teacher_email", "display_name", "class_code", "class_name", "status", "approved_at", "approved_by"];
  if (sheet.getLastRow() === 0) sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  else {
    const actual = sheet.getRange(1, 1, 1, headers.length).getDisplayValues()[0].map((value) => String(value || "").trim().toLowerCase());
    if (!headers.every((header, index) => actual[index] === header)) throw new Error("_TeacherAccess 탭 헤더가 예상과 다릅니다. 기존 내용을 보존했습니다.");
  }
  const lastRow = sheet.getLastRow();
  const emails = lastRow > 1 ? sheet.getRange(2, 1, lastRow - 1, 1).getDisplayValues().flat().map(ybNormalizeEmail_) : [];
  const index = emails.indexOf(record.email);
  const row = index >= 0 ? index + 2 : Math.max(2, lastRow + 1);
  sheet.getRange(row, 1, 1, headers.length).setValues([[
    record.email, record.displayName, record.classCode, record.className, "approved", new Date(), record.adminEmail
  ]]);
  sheet.getRange(1, 1, 1, headers.length).setBackground("#5C1E1E").setFontColor("#FFFFFF").setFontWeight("bold");
  sheet.setFrozenRows(1);
  ybProtectSheet_(sheet, record.adminEmail, "담당 교사 접근 정보 - 관리자 수정 전용");
  if (!sheet.isSheetHidden()) sheet.hideSheet();
}

function ybEnsureMasterAccess_(record) {
  const book = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = book.getSheetByName(YB_MASTER_ACCESS_TAB);
  if (!sheet) sheet = book.insertSheet(YB_MASTER_ACCESS_TAB);
  const headers = ["email", "display_name", "class_name", "class_code", "spreadsheet_id", "status", "updated_at", "approved_by"];
  if (sheet.getLastRow() === 0) sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  else {
    const actual = sheet.getRange(1, 1, 1, headers.length).getDisplayValues()[0].map((value) => String(value || "").trim().toLowerCase());
    if (!headers.every((header, index) => actual[index] === header)) throw new Error("관리자 TeacherAccess 헤더가 예상과 다릅니다. 기존 자료를 보존했습니다.");
  }
  const lastRow = sheet.getLastRow();
  const emails = lastRow > 1 ? sheet.getRange(2, 1, lastRow - 1, 1).getDisplayValues().flat().map(ybNormalizeEmail_) : [];
  const index = emails.indexOf(record.email);
  const row = index >= 0 ? index + 2 : Math.max(2, lastRow + 1);
  sheet.getRange(row, 1, 1, headers.length).setValues([[
    record.email, record.displayName, record.className, record.classCode, record.spreadsheetId,
    record.status, new Date(), record.adminEmail
  ]]);
  sheet.getRange(1, 1, 1, headers.length).setBackground("#5C1E1E").setFontColor("#FFFFFF").setFontWeight("bold");
  sheet.setFrozenRows(1);
  if (!sheet.isSheetHidden()) sheet.hideSheet();
}

function ybWriteTeacherRegistration_(record) {
  // School administrators own class workbooks so sharing and offboarding stay
  // under school control. Teachers receive access only to assigned files.
  const file = DriveApp.getFileById(record.spreadsheetId);
  const ownerEmail = file.getOwner() ? ybNormalizeEmail_(file.getOwner().getEmail()) : "";
  if (!ownerEmail || !ybAdminEmails_().includes(ownerEmail)) {
    throw new Error("학급 Sheet 소유자가 승인된 학교 관리자 계정이 아닙니다. 관리자 소유의 파일을 사용하거나 소유권을 먼저 이전하세요.");
  }
  const classBook = SpreadsheetApp.openById(record.spreadsheetId);
  const roster = classBook.getSheetByName("Students");
  if (!roster || roster.getLastRow() < 2) throw new Error("담당 파일의 Students 탭을 확인하지 못했습니다.");
  const values = roster.getDataRange().getDisplayValues();
  const columns = ybHeaderMap_(values[0]);
  ["student_id", "class_code", "class_name", "attendance_number", "display_name", "status"].forEach((key) => {
    if (columns[key] === undefined) throw new Error("Students 탭의 필수 헤더를 확인하세요.");
  });
  const rows = values.slice(1).filter((row) =>
    String(row[columns.status] || "").trim().toLowerCase() === "active" &&
    ybNormalizeText_(row[columns.class_name]) === ybNormalizeText_(record.className)
  );
  const classCodes = [...new Set(rows.map((row) => ybNormalizeCode_(row[columns.class_code])).filter(Boolean))];
  if (!rows.length || classCodes.length !== 1) throw new Error("활성 학생 명단에서 해당 반의 학급 코드 하나를 확인할 수 없습니다.");
  record.classCode = classCodes[0];

  const grantedHere = ownerEmail !== record.email;
  if (grantedHere) file.addEditor(record.email);
  try {
    ybEnsureClassAccess_(classBook, record);
    ybEnsureMasterAccess_(record);
  } catch (error) {
    try {
      const accessSheet = classBook.getSheetByName(YB_CLASS_ACCESS_TAB);
      if (accessSheet && accessSheet.getLastRow() > 1) {
        const emails = accessSheet.getRange(2, 1, accessSheet.getLastRow() - 1, 1).getDisplayValues().flat().map(ybNormalizeEmail_);
        const index = emails.indexOf(record.email);
        if (index >= 0) accessSheet.getRange(index + 2, 5).setValue("disabled");
      }
      if (grantedHere) file.removeEditor(record.email);
    } catch (rollbackError) {
      console.error("교사 등록 정리 실패", rollbackError && rollbackError.name);
    }
    throw new Error("교사 등록을 마치지 못했습니다. 접근 기록의 상태와 Google Drive 공유 권한을 확인하세요.");
  }
}

function ybDisableTeacherRegistration_(record) {
  const file = DriveApp.getFileById(record.spreadsheetId);
  const ownerEmail = file.getOwner() ? ybNormalizeEmail_(file.getOwner().getEmail()) : "";
  if (ownerEmail === record.email) throw new Error("담당 교사가 학급 파일 소유자입니다. 소유권을 학교 관리 계정으로 이전해야 권한을 회수할 수 있습니다.");
  const classBook = SpreadsheetApp.openById(record.spreadsheetId);
  const sheet = classBook.getSheetByName(YB_CLASS_ACCESS_TAB);
  if (!sheet || sheet.getLastRow() < 2) throw new Error("학급 파일에 교사 접근 기록이 없습니다.");
  const emails = sheet.getRange(2, 1, sheet.getLastRow() - 1, 1).getDisplayValues().flat().map(ybNormalizeEmail_);
  const index = emails.indexOf(record.email);
  if (index < 0) throw new Error("해당 교사의 학급 접근 기록을 찾지 못했습니다.");
  sheet.getRange(index + 2, 5).setValue("disabled");
  sheet.getRange(index + 2, 7).setValue(record.adminEmail);
  ybEnsureMasterAccess_(Object.assign({}, record, { status: "disabled" }));
  file.removeEditor(record.email);
}

function ybAdminEmails_() {
  return String(PropertiesService.getScriptProperties().getProperty(YB_ADMIN_EMAILS_KEY) || "")
    .split(",").map(ybNormalizeEmail_).filter(Boolean);
}

function ybRequireAdmin_() {
  const email = ybNormalizeEmail_(Session.getActiveUser().getEmail());
  if (!email) throw new Error("현재 Google 계정을 확인할 수 없습니다. 관리자 계정으로 통합 Sheet에서 실행하세요.");
  if (!ybAdminEmails_().includes(email)) throw new Error("등록된 학교 관리자만 사용할 수 있습니다.");
  return { email };
}

function ybIsProtectedDataSheet_(sheet) {
  const name = sheet.getName().trim().toLowerCase();
  if (["students", "teacheraccess", "_teacheraccess"].includes(name) || name.includes("명단") || name.includes("roster")) return true;
  if (!sheet.getLastColumn()) return false;
  const headers = sheet.getRange(1, 1, 1, Math.min(sheet.getLastColumn(), 30)).getDisplayValues()[0].map(ybNormalizeHeader_).map((value) => value.toLowerCase());
  return headers.includes("initial_pin") || headers.includes("pin_hash") || headers.includes("pin_salt") ||
    (headers.includes("student_id") && headers.includes("attendance_number")) ||
    (headers.includes("class_code") && headers.includes("display_name"));
}

function ybBackupHeaderRow_(book, sourceName, oldHeaders) {
  const stamp = Utilities.formatDate(new Date(), book.getSpreadsheetTimeZone(), "yyyyMMdd_HHmmss");
  const backup = book.insertSheet(ybUniqueSheetName_(book, `HeaderBackup_${stamp}`));
  backup.getRange(1, 1).setValue(`복구 전 헤더 백업 · ${sourceName}`);
  backup.getRange(2, 1, 1, oldHeaders.length).setValues([oldHeaders]);
  backup.hideSheet();
}

function ybUniqueSheetName_(book, base) {
  let name = base;
  let suffix = 2;
  while (book.getSheetByName(name)) { name = `${base}_${suffix++}`; }
  return name;
}

function ybPromptEditorHtml_() {
  const prompt = PropertiesService.getDocumentProperties().getProperty(YB_PROMPT_DRAFT_KEY) || "";
  const safe = ybEscapeHtml_(prompt);
  return `<html><head><base target="_top"><style>body{font:13px Arial,sans-serif;color:#23342a;padding:14px}textarea{width:100%;min-height:55vh;box-sizing:border-box;padding:10px;border:1px solid #ccd7c9;border-radius:8px;font:12px/1.5 monospace}button{margin-top:10px;padding:9px 12px;border:0;border-radius:7px;background:#173d32;color:white;font-weight:bold;cursor:pointer}.note{color:#68766c;font-size:11px;line-height:1.5}.status{min-height:24px;color:#476b47;font-size:11px}</style></head><body><h3>학생부 변환 프롬프트</h3><p class="note">초안은 관리자 통합 Sheet에 저장하며 공식 게시 전에는 변환에 적용되지 않습니다. 학생 개인정보를 넣지 마세요.</p><textarea id="prompt" maxlength="45000" placeholder="변환 규칙과 출력 형식을 입력하세요">${safe}</textarea><button onclick="saveDraft()">초안 저장</button><div id="status" class="status" role="status"></div><script>function saveDraft(){const status=document.getElementById('status');status.textContent='저장 중...';google.script.run.withSuccessHandler(r=>status.textContent=r.message).withFailureHandler(()=>status.textContent='저장하지 못했습니다. 관리자 권한을 확인해 주세요.').saveStudentPromptDraft(document.getElementById('prompt').value);}</script></body></html>`;
}

function ybProtectSheet_(sheet, adminEmail, description) {
  const protections = sheet.getProtections(SpreadsheetApp.ProtectionType.SHEET);
  const protection = protections.length ? protections[0] : sheet.protect();
  protection.setDescription(description).setWarningOnly(false).setDomainEdit(false);
  protection.getEditors().forEach((user) => {
    const editorEmail = ybNormalizeEmail_(user.getEmail());
    if (editorEmail && editorEmail !== adminEmail) protection.removeEditor(editorEmail);
  });
  if (adminEmail) protection.addEditor(adminEmail);
}

function ybHeaderMap_(row) { return row.reduce((map, value, index) => { map[String(value || "").replace(/^\uFEFF/, "").trim().toLowerCase()] = index; return map; }, {}); }
function ybNormalizeHeader_(value) { return String(value || "").replace(/^\uFEFF/, "").trim(); }
function ybNormalizeEmail_(value) { return String(value || "").trim().toLowerCase(); }
function ybNormalizeText_(value) { return String(value || "").normalize("NFKC").trim().replace(/\s+/g, ""); }
function ybNormalizeCode_(value) { return String(value || "").trim().toUpperCase(); }
function ybUtf8Bytes_(value) { return Utilities.newBlob(String(value || ""), "text/plain").getBytes().length; }
function ybCsvSafeCell_(value) { let text = String(value ?? ""); if (/^[\s]*[=+@-]/.test(text)) text = `'${text}`; return `"${text.replace(/"/g, '""')}"`; }
function ybEscapeHtml_(value) { return String(value || "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]); }
function ybHexBytes_(bytes) { return bytes.map((byte) => ((byte + 256) % 256).toString(16).padStart(2, "0")).join(""); }
function ybToast_(message) { SpreadsheetApp.getActiveSpreadsheet().toast(String(message), "학교 관리자", 5); }
function ybAlert_(message) { SpreadsheetApp.getUi().alert(String(message)); }

function ybAdminAction_(label, callback) {
  try { callback(); } catch (error) {
    console.error(`${label} 실패`, error && error.name);
    const detail = String(error && error.message || "");
    const message = /[가-힣]/.test(detail) ? detail : "Google 권한이나 시트 설정에 문제가 있습니다. 설정을 확인한 뒤 다시 시도해 주세요.";
    ybAlert_(`${label}을(를) 완료하지 못했습니다.\n${message}`);
  }
}
