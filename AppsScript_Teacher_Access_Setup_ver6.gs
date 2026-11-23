// TEMPORARY one-time setup helper for a teacher-bound class spreadsheet.
// Add this file to the teacher's bound Apps Script project, run
// registerCurrentTeacherForSetup from the Apps Script editor, then DELETE
// this file before deploying the web app.
// Do not call this function from google.script.run or a deployed web page.

function registerCurrentTeacherForSetup() {
  const email = String(Session.getEffectiveUser().getEmail() || "").trim().toLowerCase();
  if (!email) {
    throw new Error("현재 Apps Script를 실행한 Google 계정을 확인할 수 없습니다. 교사 계정으로 로그인해 편집기에서 실행해 주세요.");
  }

  const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  if (!spreadsheet) {
    throw new Error("현재 프로젝트가 Google Sheet에 연결되어 있지 않습니다. 교사 학급 Sheet의 확장 프로그램 → Apps Script에서 이 프로젝트를 여세요.");
  }

  const roster = spreadsheet.getSheetByName("Students");
  if (!roster || roster.getLastRow() < 2) {
    throw new Error("현재 Sheet에서 학생 명단 탭을 확인하지 못했습니다. 탭 이름이 Students인지, 2행부터 명단이 있는지 확인하세요.");
  }

  const values = roster.getDataRange().getDisplayValues();
  const headers = values[0].reduce((map, value, index) => {
    map[String(value || "").replace(/^\uFEFF/, "").trim().toLowerCase()] = index;
    return map;
  }, {});
  if (headers.class_name === undefined || headers.class_code === undefined || headers.status === undefined) {
    throw new Error("Students 탭 1행에 class_code, class_name, status 열이 필요합니다.");
  }

  const activeClasses = new Map();
  values.slice(1).forEach((row) => {
    if (String(row[headers.status] || "").trim().toLowerCase() !== "active") return;
    const classCode = String(row[headers.class_code] || "").trim().toUpperCase();
    const className = String(row[headers.class_name] || "").normalize("NFKC").trim();
    if (classCode && className) activeClasses.set(`${classCode}|${className}`, { classCode, className });
  });

  if (activeClasses.size !== 1) {
    throw new Error("현재 Students 탭에서 활성 학급을 하나로 확인할 수 없습니다. 이 교사 파일은 한 학급 명단만 담아야 합니다.");
  }

  const activeClass = [...activeClasses.values()][0];
  const classFiles = [{ className: activeClass.className, spreadsheetId: spreadsheet.getId() }];
  setTeacherAccess_(email, "담당 교사", JSON.stringify(classFiles));

  // Log only a generic completion message; never log the email or Sheet ID.
  console.log("교사 계정과 연결된 학급 파일을 승인 목록에 등록했습니다. 이메일과 파일 ID는 출력하지 않았습니다.");
  return { ok: true, registeredClassCount: 1 };
}
