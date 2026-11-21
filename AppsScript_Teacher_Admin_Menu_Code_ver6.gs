/**
 * 이어봄 교사 웹앱 + Google Sheets 학교 관리자 메뉴
 * 문서 버전: ver6
 *
 * 이 파일은 현재 교사용 Code.gs의 웹앱·권한 함수를 보존하고
 * 요청된 학교 관리자 커스텀 메뉴와 각 메뉴 동작을 포함하는 완성본입니다.
 * 적용 전 기존 Code.gs를 백업하고, Google Sheet 사본에서 먼저 시험하세요.
 */

const SCHOOL_ADMIN_MENU_NAME = "🏫 [학교 관리자]";
const SCHOOL_DB_HEADERS = ["타임스탬프", "학번", "성명", "이메일"];
const SCHOOL_DB_EXTRA_HEADERS = ["학생 제출 본문", "AI 변환 결과", "처리 상태"];
const SCHOOL_PROMPT_DRAFT_KEY = "STUDENT_CONVERSION_PROMPT_DRAFT";
const SCHOOL_MAX_CELL_UTF8_BYTES = 45000;

// 기존 교사용 웹앱 진입점을 유지합니다.
function doGet() {
  return HtmlService.createHtmlOutputFromFile("TeacherIndex")
    .setTitle("이어봄 교사 접속")
    .addMetaTag("viewport", "width=device-width, initial-scale=1");
}

function teacherGetContext() {
  const email = String(Session.getActiveUser().getEmail() || "").trim().toLowerCase();
  if (!email) {
    return {
      ok: false,
      code: "EMAIL_UNAVAILABLE",
      message: "Google 이메일을 확인할 수 없습니다. 교사용 웹앱이 ‘접속한 사용자로 실행’으로 배포되었는지 확인해 주세요."
    };
  }

  const access = teacherAccessFor_(email);
  if (!access || access.status !== "approved") {
    return {
      ok: false,
      code: "NOT_APPROVED",
      message: "이 Google 계정에는 승인된 교사 권한이 없습니다. 학교 담당자에게 승인을 요청해 주세요."
    };
  }

  try {
    const classes = (access.classFiles || []).map((classFile) => {
      const workbook = SpreadsheetApp.openById(classFile.spreadsheetId);
      return { className: classFile.className, workbookName: workbook.getName() };
    });
    if (!classes.length) throw new Error("담당 학급 파일이 등록되지 않았습니다.");
    return {
      ok: true,
      teacher: {
        email: email,
        displayName: access.displayName || email,
        classes: classes
      }
    };
  } catch (error) {
    console.error("Teacher workbook access denied", error);
    return {
      ok: false,
      code: "CLASS_FILE_UNAVAILABLE",
      message: "담당 학급 파일 권한이 없거나 파일 설정이 끝나지 않았습니다. 관리자에게 확인해 주세요."
    };
  }
}

// 교사 접근 allowlist는 이 바인딩된 프로젝트의 Script Properties에 저장합니다.
function setTeacherAccess_(emailValue, displayName, classFilesJson) {
  const email = schoolNormalizeEmail_(emailValue);
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error("교사 Google 이메일을 확인해 주세요.");
  const classFiles = JSON.parse(String(classFilesJson || "[]"));
  if (!Array.isArray(classFiles) || !classFiles.length || classFiles.some((item) => !item.className || !item.spreadsheetId)) {
    throw new Error("classFiles에는 학급 이름과 파일 ID가 있는 항목이 필요합니다.");
  }
  const record = { status: "approved", displayName: String(displayName || email), classFiles: classFiles };
  PropertiesService.getScriptProperties().setProperty(teacherAccessPropertyKey_(email), JSON.stringify(record));
}

function disableTeacherAccess_(emailValue) {
  const email = schoolNormalizeEmail_(emailValue);
  const record = teacherAccessFor_(email);
  if (!record) return;
  record.status = "disabled";
  PropertiesService.getScriptProperties().setProperty(teacherAccessPropertyKey_(email), JSON.stringify(record));
}

function teacherAccessFor_(email) {
  const raw = PropertiesService.getScriptProperties().getProperty(teacherAccessPropertyKey_(email));
  return raw ? JSON.parse(raw) : null;
}

function teacherAccessPropertyKey_(email) {
  const digest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, schoolNormalizeEmail_(email), Utilities.Charset.UTF_8);
  return `TEACHER_ACCESS_${schoolHexBytes_(digest)}`;
}

// 요청하신 메뉴 구조와 항목 순서를 유지합니다.
function onOpen(e) {
  try {
    SpreadsheetApp.getUi()
      .createMenu(SCHOOL_ADMIN_MENU_NAME)
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
    console.error("학교 관리자 메뉴 생성 실패", error);
  }
}

function openPromptEditorDashboard() {
  return schoolAdminAction_("프롬프트 편집기", function () {
    schoolRequireAdmin_();
    SpreadsheetApp.getUi().showSidebar(
      HtmlService.createHtmlOutput(schoolPromptEditorHtml_()).setTitle("학생부 변환 프롬프트 편집기")
    );
    schoolToast_("프롬프트 편집기를 열었습니다.");
  });
}

function saveStudentPromptDraft(promptText) {
  schoolRequireAdmin_();
  const prompt = String(promptText || "").trim();
  if (!prompt) return { ok: false, message: "프롬프트 내용을 입력해 주세요." };
  if (utf8ByteLength_(prompt) > SCHOOL_MAX_CELL_UTF8_BYTES) return { ok: false, message: "프롬프트가 45,000바이트를 넘습니다." };
  PropertiesService.getDocumentProperties().setProperty(SCHOOL_PROMPT_DRAFT_KEY, prompt);
  schoolToast_("프롬프트 초안을 저장했습니다.");
  return { ok: true, message: "초안을 저장했습니다. 공식 게시 전까지는 처리에 적용되지 않습니다." };
}

function initAndSelfHealSystem() {
  return schoolAdminAction_("시스템 초기화 및 자가 치유", function () {
    schoolRequireAdmin_();
    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = spreadsheet.getActiveSheet();

    // 학생 인증 명단을 실수로 덮어쓰지 않도록 보호합니다.
    if (schoolIsRosterSheet_(sheet)) {
      throw new Error("현재 활성 시트가 학생 명단으로 보입니다. 명단은 수정하지 않았습니다. 비어 있는 제출 기록 시트를 선택한 뒤 실행하세요.");
    }

    if (sheet.getMaxColumns() < 7) sheet.insertColumnsAfter(sheet.getMaxColumns(), 7 - sheet.getMaxColumns());
    const width = Math.max(7, sheet.getLastColumn());
    const previousHeaders = sheet.getRange(1, 1, 1, width).getDisplayValues()[0];
    const needsRepair = SCHOOL_DB_HEADERS.some((header, index) => String(previousHeaders[index] || "").trim() !== header);

    if (needsRepair && previousHeaders.slice(0, 4).some((value) => String(value || "").trim())) {
      schoolBackupHeaderRow_(spreadsheet, previousHeaders);
    }

    sheet.getRange(1, 1, 1, SCHOOL_DB_HEADERS.length).setValues([SCHOOL_DB_HEADERS]);
    SCHOOL_DB_EXTRA_HEADERS.forEach((header, index) => {
      const column = index + 5;
      if (!String(previousHeaders[column - 1] || "").trim()) sheet.getRange(1, column).setValue(header);
    });

    const headerWidth = Math.max(7, sheet.getLastColumn());
    sheet.getRange(1, 1, 1, headerWidth)
      .setBackground("#5C1E1E")
      .setFontColor("#FFFFFF")
      .setFontWeight("bold")
      .setHorizontalAlignment("center")
      .setVerticalAlignment("middle");
    sheet.setFrozenRows(1);
    sheet.setRowHeight(1, 34);
    if (sheet.getMaxRows() > 1) sheet.getRange(2, 1, sheet.getMaxRows() - 1, 1).setNumberFormat("yyyy. MM. dd. HH:mm:ss");

    schoolToast_(needsRepair ? "필수 기록 헤더를 복구했습니다." : "기록 헤더와 스타일을 확인했습니다.");
    schoolAlert_(needsRepair
      ? "초기화 완료: 필수 헤더를 복구했습니다. 기존 헤더는 숨김 백업 탭에 보관했습니다."
      : "확인 완료: 필수 헤더, 스타일, 첫 행 고정을 적용했습니다.");
  });
}

function checkSubmissionAndBytes() {
  return schoolStaffAction_("학생 제출 현황 및 바이트 점검", function () {
    const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    if (schoolIsRosterSheet_(sheet)) throw new Error("학생 명단 탭에서는 점검하지 않습니다. 제출 기록 시트를 선택해 주세요.");
    const lastRow = sheet.getLastRow();
    const lastColumn = sheet.getLastColumn();
    if (lastRow < 2 || lastColumn < 1) return schoolAlert_("제출 기록이 없습니다.");

    const headers = sheet.getRange(1, 1, 1, lastColumn).getDisplayValues()[0];
    const bodyIndex = headers.findIndex((value) => ["학생 제출 본문", "제출 본문", "답안 원문", "submission_text"].includes(String(value || "").trim().toLowerCase()));
    if (bodyIndex < 0) throw new Error("학생 제출 본문 열을 찾지 못했습니다. E열 이후 헤더를 확인하세요.");

    let count = 0;
    let maxBytes = 0;
    let oversized = 0;
    sheet.getRange(2, bodyIndex + 1, lastRow - 1, 1).getDisplayValues().flat().forEach((body) => {
      if (!String(body || "").trim()) return;
      count += 1;
      const bytes = utf8ByteLength_(String(body));
      maxBytes = Math.max(maxBytes, bytes);
      if (bytes > SCHOOL_MAX_CELL_UTF8_BYTES) oversized += 1;
    });
    schoolAlert_(`점검 완료\n제출 답안: ${count}건\n최대 크기: ${maxBytes.toLocaleString("ko-KR")}바이트\n45,000바이트 초과: ${oversized}건\n답안 내용은 표시하지 않았습니다.`);
  });
}

function manageTeacherPermissions() {
  return schoolAdminAction_("교사·관리자 계정 관리", function () {
    schoolRequireAdmin_();
    const ui = SpreadsheetApp.getUi();
    const rolePrompt = ui.prompt("계정 역할", "등록할 역할을 teacher 또는 admin으로 입력하세요.", ui.ButtonSet.OK_CANCEL);
    if (rolePrompt.getSelectedButton() !== ui.Button.OK) return;
    const role = String(rolePrompt.getResponseText() || "").trim().toLowerCase();
    if (!["teacher", "admin"].includes(role)) throw new Error("역할에는 teacher 또는 admin을 입력하세요.");

    const email = schoolPromptEmail_(ui, "Google 계정 이메일");
    if (!email) return;
    if (role === "admin") {
      const admins = schoolAdminEmails_();
      if (!admins.includes(email)) admins.push(email);
      PropertiesService.getScriptProperties().setProperty("SCHOOL_ADMIN_EMAILS", admins.join(","));
      return schoolAlert_("관리자 계정을 등록했습니다. 이메일 주소는 알림이나 로그에 표시하지 않았습니다.");
    }

    const displayName = schoolPromptText_(ui, "교사 표시 이름", "스프레드시트에 표시할 이름을 입력하세요.");
    if (!displayName) return;
    const className = schoolPromptText_(ui, "담당 학급", "예: 2학년 3반");
    if (!className) return;
    const spreadsheetId = schoolPromptText_(ui, "학급 파일 ID", "학생 명단이 있는 담당 학급 Google Sheet ID를 입력하세요. 이 값은 출력하지 않습니다.");
    if (!spreadsheetId) return;

    const workbook = SpreadsheetApp.openById(spreadsheetId);
    const roster = workbook.getSheetByName("Students");
    if (!roster || roster.getLastRow() < 2) throw new Error("선택한 파일에서 학생 명단이 있는 Students 탭을 확인하지 못했습니다.");
    const rows = roster.getDataRange().getDisplayValues();
    const headers = schoolHeaderMap_(rows[0]);
    if (["class_code", "class_name", "status"].some((key) => headers[key] === undefined)) {
      throw new Error("Students 탭의 1행에 class_code, class_name, status 열이 필요합니다.");
    }
    const codes = new Set(rows.slice(1)
      .filter((row) => String(row[headers.status] || "").trim().toLowerCase() === "active" && schoolNormalizeText_(row[headers.class_name]) === schoolNormalizeText_(className))
      .map((row) => schoolNormalizeCode_(row[headers.class_code]))
      .filter(Boolean));
    if (codes.size !== 1) throw new Error("해당 반에 활성 학급 코드가 하나인지 확인할 수 없습니다.");

    setTeacherAccess_(email, displayName, JSON.stringify([{ className, classCode: [...codes][0], spreadsheetId }]));
    schoolToast_("교사 계정과 담당 학급을 승인 목록에 등록했습니다.");
    schoolAlert_("교사 권한을 등록했습니다. 이메일과 Sheet ID는 로그에 표시하지 않았습니다.");
  });
}

function publishImmutableSnapshot() {
  return schoolAdminAction_("프롬프트 공식 스냅샷", function () {
    const admin = schoolRequireAdmin_();
    const prompt = PropertiesService.getDocumentProperties().getProperty(SCHOOL_PROMPT_DRAFT_KEY) || "";
    if (!prompt.trim()) throw new Error("프롬프트 편집기에서 먼저 초안을 저장해 주세요.");
    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
    const stamp = Utilities.formatDate(new Date(), spreadsheet.getSpreadsheetTimeZone(), "yyyyMMdd_HHmmss");
    const snapshot = spreadsheet.insertSheet(schoolUniqueSheetName_(spreadsheet, `PromptSnapshot_${stamp}`));
    const checksum = schoolHexBytes_(Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, prompt, Utilities.Charset.UTF_8));
    snapshot.getRange("A1:B5").setValues([["항목", "공식 게시 값"], ["버전", `prompt-${stamp}`], ["게시 시각", new Date()], ["게시자", admin.email], ["SHA-256", checksum]]);
    snapshot.getRange("A7").setValue("학생부 변환 프롬프트");
    snapshot.getRange("A8").setValue(prompt).setWrap(true).setVerticalAlignment("top");
    snapshot.getRange("A1:B1").setBackground("#5C1E1E").setFontColor("#FFFFFF").setFontWeight("bold");
    snapshot.setColumnWidth(1, 190);
    snapshot.setColumnWidth(2, 720);
    snapshot.setRowHeight(8, 280);
    snapshot.setFrozenRows(1);
    schoolProtectSnapshot_(snapshot, admin.email);
    schoolAlert_(`프롬프트 스냅샷을 보호 상태로 게시했습니다.\n버전: prompt-${stamp}\nSHA-256 지문을 기록했습니다. 파일 소유자는 보호를 변경할 수 있습니다.`);
  });
}

function runSystemDoctor() {
  return schoolAdminAction_("시스템 자가 진단", function () {
    schoolRequireAdmin_();
    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = spreadsheet.getActiveSheet();
    const report = [
      `관리자 등록: ${schoolAdminEmails_().length ? "설정됨" : "미설정"}`,
      `활성 시트 종류: ${schoolIsRosterSheet_(sheet) ? "학생 명단 (초기화 금지)" : "기록 시트"}`,
      `시트 탭 수: ${spreadsheet.getSheets().length}`,
      `시간대: ${spreadsheet.getSpreadsheetTimeZone()}`
    ];
    if (!schoolIsRosterSheet_(sheet)) {
      const row = sheet.getRange(1, 1, 1, Math.max(4, sheet.getLastColumn())).getDisplayValues()[0];
      report.push(`필수 헤더: ${SCHOOL_DB_HEADERS.every((value, index) => String(row[index] || "").trim() === value) ? "정상" : "복구 필요"}`);
    }
    schoolAlert_("시스템 진단 결과\n" + report.map((line) => `• ${line}`).join("\n"));
  });
}

function runSyntheticUnitTests() {
  return schoolAdminAction_("엔진 무결성 합성 테스트", function () {
    schoolRequireAdmin_();
    const tests = [
      ["헤더 공백 정리", schoolNormalizeHeader_(" 학번 ") === "학번"],
      ["이메일 정규화", schoolNormalizeEmail_(" Teacher@Example.edu ") === "teacher@example.edu"],
      ["UTF-8 크기", utf8ByteLength_("과학") === 6],
      ["CSV 수식 방어", csvSafeCell_("=1+1").startsWith("'")],
      ["바이트 상한", utf8ByteLength_("a".repeat(SCHOOL_MAX_CELL_UTF8_BYTES)) === SCHOOL_MAX_CELL_UTF8_BYTES]
    ];
    const failed = tests.filter(([, passed]) => !passed).map(([name]) => name);
    schoolAlert_(failed.length ? `합성 테스트 실패: ${failed.join(", ")}` : `합성 테스트 ${tests.length}개 통과. 실제 학생 정보는 사용하지 않았습니다.`);
    if (failed.length) throw new Error(`테스트 실패: ${failed.join(", ")}`);
  });
}

function bootstrapInitialSchoolAdmin_() {
  const properties = PropertiesService.getScriptProperties();
  if (schoolAdminEmails_().length) throw new Error("학교 관리자 계정이 이미 등록되어 있습니다.");
  const email = schoolNormalizeEmail_(Session.getEffectiveUser().getEmail());
  if (!email) throw new Error("현재 Apps Script 소유자 계정 이메일을 확인할 수 없습니다.");
  const ui = SpreadsheetApp.getUi();
  if (ui.alert("첫 학교 관리자 등록", "현재 Apps Script 실행 소유자를 첫 학교 관리자로 등록할까요?", ui.ButtonSet.YES_NO) !== ui.Button.YES) return;
  properties.setProperty("SCHOOL_ADMIN_EMAILS", email);
  schoolAlert_("첫 학교 관리자를 등록했습니다. 시트를 닫았다가 다시 열어 메뉴를 표시하세요.");
}

function schoolPromptEditorHtml_() {
  const saved = PropertiesService.getDocumentProperties().getProperty(SCHOOL_PROMPT_DRAFT_KEY) || "";
  const escaped = schoolEscapeHtml_(saved);
  return `<html><head><base target="_top"><style>body{font:13px Arial,sans-serif;color:#23342a;padding:14px}textarea{width:100%;min-height:55vh;box-sizing:border-box;padding:10px;border:1px solid #ccd7c9;border-radius:8px;font:12px/1.5 monospace}button{margin-top:10px;padding:9px 12px;border:0;border-radius:7px;background:#173d32;color:white;font-weight:bold;cursor:pointer}.note{color:#68766c;font-size:11px;line-height:1.5}.status{min-height:24px;color:#476b47;font-size:11px}</style></head><body><h3>학생부 변환 프롬프트</h3><p class="note">초안은 이 스프레드시트에 저장됩니다. 공식 스냅샷 게시 전에는 실제 처리에 사용되지 않습니다. 학생 개인정보를 넣지 마세요.</p><textarea id="prompt" maxlength="45000" placeholder="변환 규칙과 출력 형식을 입력하세요">${escaped}</textarea><button onclick="saveDraft()">초안 저장</button><div id="status" class="status" role="status"></div><script>function saveDraft(){const status=document.getElementById('status');status.textContent='저장 중...';google.script.run.withSuccessHandler(result=>{status.textContent=result.message;}).withFailureHandler(()=>{status.textContent='저장하지 못했습니다. 학교 관리자 권한을 확인해 주세요.';}).saveStudentPromptDraft(document.getElementById('prompt').value);}</script></body></html>`;
}

function saveStudentPromptDraft(promptText) {
  schoolRequireAdmin_();
  const prompt = String(promptText || "").trim();
  if (!prompt) return { ok: false, message: "프롬프트 내용을 입력해 주세요." };
  if (utf8ByteLength_(prompt) > SCHOOL_MAX_CELL_UTF8_BYTES) return { ok: false, message: "프롬프트는 45,000바이트 이하로 줄여 주세요." };
  PropertiesService.getDocumentProperties().setProperty(SCHOOL_PROMPT_DRAFT_KEY, prompt);
  schoolToast_("프롬프트 초안을 저장했습니다.");
  return { ok: true, message: "초안을 저장했습니다. 공식 게시 전에는 처리되지 않습니다." };
}

function schoolBackupHeaderRow_(spreadsheet, previousHeaders) {
  const stamp = Utilities.formatDate(new Date(), spreadsheet.getSpreadsheetTimeZone(), "yyyyMMdd_HHmmss");
  const backup = spreadsheet.insertSheet(schoolUniqueSheetName_(spreadsheet, `HeaderBackup_${stamp}`));
  backup.getRange(1, 1).setValue("복구 전 헤더 백업");
  backup.getRange(2, 1, 1, previousHeaders.length).setValues([previousHeaders]);
  backup.hideSheet();
}

function schoolUniqueSheetName_(spreadsheet, baseName) {
  let name = baseName;
  let suffix = 2;
  while (spreadsheet.getSheetByName(name)) {
    name = `${baseName}_${suffix}`;
    suffix += 1;
  }
  return name;
}

function schoolProtectSnapshot_(sheet, adminEmail) {
  const protection = sheet.protect().setDescription("공식 프롬프트 스냅샷 - 보호됨");
  protection.setWarningOnly(false);
  const editors = protection.getEditors();
  if (editors.length) protection.removeEditors(editors);
  if (adminEmail) protection.addEditor(adminEmail);
  if (protection.canDomainEdit()) protection.setDomainEdit(false);
}

function schoolPromptEmail_(ui, title) {
  const result = ui.prompt(title, "Google 계정 이메일을 입력하세요.", ui.ButtonSet.OK_CANCEL);
  if (result.getSelectedButton() !== ui.Button.OK) return "";
  const email = schoolNormalizeEmail_(result.getResponseText());
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error("Google 이메일 형식을 확인해 주세요.");
  return email;
}

function schoolPromptText_(ui, title, message) {
  const result = ui.prompt(title, message, ui.ButtonSet.OK_CANCEL);
  return result.getSelectedButton() === ui.Button.OK ? String(result.getResponseText() || "").trim() : "";
}

function schoolAdminEmails_() {
  return String(PropertiesService.getScriptProperties().getProperty("SCHOOL_ADMIN_EMAILS") || "")
    .split(",").map(schoolNormalizeEmail_).filter(Boolean);
}

function schoolCurrentEmail_() {
  return schoolNormalizeEmail_(Session.getActiveUser().getEmail());
}

function schoolRequireAdmin_() {
  const email = schoolCurrentEmail_();
  if (!email) throw new Error("현재 Google 계정을 확인할 수 없습니다. 시트에 로그인한 관리자 계정으로 실행해 주세요.");
  if (!schoolAdminEmails_().includes(email)) throw new Error("등록된 학교 관리자만 사용할 수 있는 메뉴입니다.");
  return { email };
}

function schoolRequireStaff_() {
  const email = schoolCurrentEmail_();
  if (!email) throw new Error("현재 Google 계정을 확인할 수 없습니다.");
  if (schoolAdminEmails_().includes(email)) return { email, role: "admin" };
  const access = teacherAccessFor_(email);
  if (access && access.status === "approved") return { email, role: "teacher" };
  throw new Error("승인된 교사 또는 관리자 계정만 사용할 수 있습니다.");
}

function schoolIsRosterSheet_(sheet) {
  const sheetName = sheet.getName().trim().toLowerCase();
  if (sheetName === "students" || sheetName.includes("명단") || sheetName.includes("roster")) return true;
  if (sheet.getLastColumn() < 1) return false;
  const headers = sheet.getRange(1, 1, 1, Math.min(sheet.getLastColumn(), 30)).getDisplayValues()[0].map(schoolNormalizeHeader_).map((value) => value.toLowerCase());
  return headers.includes("initial_pin") || headers.includes("pin_hash") || headers.includes("pin_salt") ||
    (headers.includes("student_id") && headers.includes("attendance_number")) ||
    (headers.includes("class_code") && headers.includes("display_name"));
}

function schoolHeaderMap_(row) {
  return row.reduce((map, value, index) => { map[schoolNormalizeHeader_(value).toLowerCase()] = index; return map; }, {});
}

function schoolNormalizeHeader_(value) { return String(value || "").replace(/^\uFEFF/, "").trim(); }
function schoolNormalizeEmail_(value) { return String(value || "").trim().toLowerCase(); }
function schoolNormalizeText_(value) { return String(value || "").normalize("NFKC").trim().replace(/\s+/g, ""); }
function schoolNormalizeCode_(value) { return String(value || "").trim().toUpperCase(); }
function utf8ByteLength_(value) { return Utilities.newBlob(String(value || ""), "text/plain").getBytes().length; }

function csvSafeCell_(value) {
  let text = String(value ?? "");
  if (/^[\s]*[=+@-]/.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
}

function schoolEscapeHtml_(value) {
  return String(value || "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);
}

function schoolHexBytes_(bytes) {
  return bytes.map((byte) => ((byte + 256) % 256).toString(16).padStart(2, "0")).join("");
}

function schoolToast_(message) { SpreadsheetApp.getActiveSpreadsheet().toast(String(message), "학교 관리자", 5); }
function schoolAlert_(message) { SpreadsheetApp.getUi().alert(String(message)); }

function schoolAdminAction_(label, callback) {
  try { callback(); } catch (error) {
    console.error(`${label} 실패`, error);
    schoolAlert_(`${label}을(를) 완료하지 못했습니다.\n${error.message || "설정과 권한을 확인해 주세요."}`);
  }
}

function schoolStaffAction_(label, callback) {
  try { schoolRequireStaff_(); callback(); } catch (error) {
    console.error(`${label} 실패`, error);
    schoolAlert_(`${label}을(를) 완료하지 못했습니다.\n${error.message || "권한과 활성 시트를 확인해 주세요."}`);
  }
}
