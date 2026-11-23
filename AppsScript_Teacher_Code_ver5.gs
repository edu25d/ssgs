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
    // Each teacher is granted access only to their assigned class workbooks.
    const classes = (access.classFiles || []).map((classFile) => {
      const workbook = SpreadsheetApp.openById(classFile.spreadsheetId);
      return { className: classFile.className, workbookName: workbook.getName() };
    });
    if (!classes.length) throw new Error("No class workbooks are assigned.");
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

// Run only by a trusted Apps Script project editor. The client cannot call
// functions ending in an underscore through google.script.run.
// Example:
// setTeacherAccess_("teacher@example.edu", "담당 선생님", JSON.stringify([
//   { className: "2학년 3반", spreadsheetId: "CLASS_SPREADSHEET_ID" }
// ]));
function setTeacherAccess_(emailValue, displayName, classFilesJson) {
  const email = String(emailValue || "").trim().toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error("교사 Google 이메일을 확인해 주세요.");
  const classFiles = JSON.parse(String(classFilesJson || "[]"));
  if (!Array.isArray(classFiles) || !classFiles.length || classFiles.some((item) => !item.className || !item.spreadsheetId)) {
    throw new Error("classFiles에는 className과 spreadsheetId가 있는 항목이 필요합니다.");
  }
  const record = { status: "approved", displayName: String(displayName || email), classFiles: classFiles };
  PropertiesService.getScriptProperties().setProperty(teacherAccessPropertyKey_(email), JSON.stringify(record));
}

function disableTeacherAccess_(emailValue) {
  const email = String(emailValue || "").trim().toLowerCase();
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
  const digest = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, String(email).toLowerCase(), Utilities.Charset.UTF_8)
    .map((byte) => ((byte + 256) % 256).toString(16).padStart(2, "0"))
    .join("");
  return `TEACHER_ACCESS_${digest}`;
}
