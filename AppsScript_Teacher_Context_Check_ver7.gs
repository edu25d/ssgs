// TEMPORARY, read-only diagnostic for the teacher Apps Script project.
// Add this file to the same project and run checkTeacherContextOnce
// from the Apps Script editor. It logs no email, student data, or Sheet ID.
// Delete this file before deploying the teacher web app.

function checkTeacherContextOnce() {
  const result = teacherGetContext();
  const safeSummary = {
    ok: Boolean(result && result.ok === true),
    code: result && result.code ? String(result.code) : result && result.ok === true ? "OK" : "UNKNOWN_RESULT",
    assignedClassCount: result && result.teacher && Array.isArray(result.teacher.classes)
      ? result.teacher.classes.length
      : 0
  };
  console.log("교사 접근 점검(계정·파일 식별정보 제외): " + JSON.stringify(safeSummary));
  return safeSummary;
}
