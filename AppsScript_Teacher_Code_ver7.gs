// Teacher web app bound to a school-owned class spreadsheet.
// Deploy the web app to execute as the Google user accessing it.

function doGet() {
  return HtmlService.createHtmlOutputFromFile("TeacherIndex")
    .setTitle("이어봄 교사 접속")
    .addMetaTag("viewport", "width=device-width, initial-scale=1");
}

// Run once from this bound script editor after the admin has approved the
// teacher in the protected _TeacherAccess tab. The function derives the
// spreadsheet ID from its container; it never accepts an arbitrary file ID.
function setupTeacherClassContext() {
  const workbook = SpreadsheetApp.getActiveSpreadsheet();
  if (!workbook) throw new Error("담당 학급 Google Sheet의 확장 프로그램 → Apps Script에서 이 프로젝트를 열어 주세요.");
  const email = String(Session.getActiveUser().getEmail() || "").trim().toLowerCase();
  if (!email) throw new Error("현재 Google 계정 이메일을 확인할 수 없습니다. 승인받은 교사 계정으로 실행하세요.");
  const accessSheet = workbook.getSheetByName("_TeacherAccess");
  if (!accessSheet || accessSheet.getLastRow() < 2) throw new Error("_TeacherAccess 탭이 없습니다. 먼저 관리자 메뉴에서 학급과 교사를 등록하세요.");
  const values = accessSheet.getDataRange().getDisplayValues();
  const headerMap = teacherHeaderMap_(values[0]);
  const emailColumn = headerMap.teacher_email;
  const statusColumn = headerMap.status;
  if (emailColumn === undefined || statusColumn === undefined) throw new Error("_TeacherAccess 헤더를 확인할 수 없습니다.");
  const approved = values.slice(1).some((row) =>
    String(row[emailColumn] || "").trim().toLowerCase() === email &&
    String(row[statusColumn] || "").trim().toLowerCase() === "approved"
  );
  if (!approved) throw new Error("이 Google 계정은 이 학급의 승인 교사가 아닙니다.");
  PropertiesService.getScriptProperties().setProperty("TEACHER_CLASS_SPREADSHEET_ID", workbook.getId());
  return "교사 웹앱에 현재 학급 파일을 연결했습니다. 파일 ID는 로그에 출력하지 않았습니다.";
}

function teacherGetContext() {
  const email = String(Session.getActiveUser().getEmail() || "").trim().toLowerCase();
  if (!email) {
    return {
      ok: false,
      code: "EMAIL_UNAVAILABLE",
      message: "Google 계정을 확인할 수 없습니다. 교사 웹앱을 ‘접속한 사용자로 실행’으로 배포했는지 확인해 주세요."
    };
  }

  const spreadsheetId = PropertiesService.getScriptProperties().getProperty("TEACHER_CLASS_SPREADSHEET_ID");
  if (!spreadsheetId) {
    return {
      ok: false,
      code: "CLASS_CONTEXT_MISSING",
      message: "담당 학급 설정이 없습니다. 교사용 Apps Script 편집기에서 setupTeacherClassContext를 실행해 주세요."
    };
  }

  try {
    // Deployed as USER_ACCESSING: this open is authorized as the signed-in teacher.
    const workbook = SpreadsheetApp.openById(spreadsheetId);
    const sheet = workbook.getSheetByName("_TeacherAccess");
    if (!sheet || sheet.getLastRow() < 2) {
      return { ok: false, code: "ACCESS_REGISTRY_MISSING", message: "관리자가 이 학급 파일에 교사 접근을 등록하지 않았습니다." };
    }

    const values = sheet.getDataRange().getDisplayValues();
    const headers = teacherHeaderMap_(values[0]);
    ["teacher_email", "display_name", "class_code", "class_name", "status"].forEach((header) => {
      if (headers[header] === undefined) throw new Error("_TeacherAccess 탭의 헤더를 확인할 수 없습니다.");
    });
    const row = values.slice(1).find((candidate) =>
      String(candidate[headers.teacher_email] || "").trim().toLowerCase() === email &&
      String(candidate[headers.status] || "").trim().toLowerCase() === "approved"
    );
    if (!row) {
      return { ok: false, code: "NOT_APPROVED", message: "이 Google 계정은 이 학급에 승인되지 않았습니다. 학교 담당자에게 확인해 주세요." };
    }

    return {
      ok: true,
      teacher: {
        email: email,
        displayName: String(row[headers.display_name] || email),
        classCode: String(row[headers.class_code] || ""),
        className: String(row[headers.class_name] || ""),
        workbookName: workbook.getName()
      }
    };
  } catch (error) {
    console.error("담당 학급 권한 확인 실패", error && error.name);
    return {
      ok: false,
      code: "CLASS_ACCESS_FAILED",
      message: "담당 학급 파일에 접근할 수 없습니다. Google Sheet 공유 권한을 확인해 주세요."
    };
  }
}

function teacherHeaderMap_(row) {
  return row.reduce((map, value, index) => {
    map[String(value || "").replace(/^\uFEFF/, "").trim().toLowerCase()] = index;
    return map;
  }, {});
}
