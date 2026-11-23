// TEMPORARY authorization helper for a teacher-bound spreadsheet script.
// Add this file to the teacher's Apps Script project and run
// authorizeTeacherSpreadsheetAccessOnce from the editor as the teacher.
// Google should show the Spreadsheet access consent flow.
// Delete this temporary file after authorization, before deploying the web app.

function authorizeTeacherSpreadsheetAccessOnce() {
  const activeSpreadsheet = SpreadsheetApp.getActiveSpreadsheet();
  if (!activeSpreadsheet) {
    throw new Error("현재 프로젝트가 교사 Google Sheet에 연결되어 있지 않습니다. 담당 교사 시트의 확장 프로그램 → Apps Script에서 여세요.");
  }

  // Intentionally not wrapped in try/catch: Apps Script must surface its OAuth consent prompt.
  const checkedSpreadsheet = SpreadsheetApp.openById(activeSpreadsheet.getId());
  if (!checkedSpreadsheet) throw new Error("Google Sheets 권한을 확인하지 못했습니다.");

  return "Google Sheets 접근 권한이 확인되었습니다. 이 임시 함수를 삭제해 주세요.";
}
