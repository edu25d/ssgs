// TEMPORARY SETUP TOOL.
// Add this file to the Student Apps Script project, run
// diagnoseAndInitializeRosterPins() from the editor, then DELETE this file
// and any temporary public setup wrapper before deploying the web app.
// Never run it from a browser or expose it through the deployed student app.

function diagnoseAndInitializeRosterPins() {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    const settings = studentSettings_();
    const entries = Object.entries(settings.classes || {});
    if (!entries.length) {
      throw new Error("STUDENT_CLASS_REGISTRY_JSON에 등록된 학급이 없습니다. Script Properties 이름과 JSON을 확인하세요.");
    }

    const jobs = [];
    const summary = {
      registeredClasses: entries.length,
      openedRosterSheets: 0,
      scannedRows: 0,
      pinsHashedNow: 0,
      pinsAlreadyHashed: 0,
      rowsWithoutPin: 0
    };

    entries.forEach(([classCode, classInfo], classIndex) => {
      if (!classInfo || !classInfo.spreadsheetId || !classInfo.className) {
        throw new Error(`등록된 ${classIndex + 1}번째 학급에 className 또는 spreadsheetId가 없습니다.`);
      }

      let spreadsheet;
      try {
        spreadsheet = SpreadsheetApp.openById(classInfo.spreadsheetId);
      } catch (error) {
        throw new Error(`등록된 ${classIndex + 1}번째 학급의 Sheet를 열 수 없습니다. spreadsheetId와 소유자 권한을 확인하세요.`);
      }

      const rosterName = classInfo.rosterSheet || "Students";
      const sheet = spreadsheet.getSheetByName(rosterName);
      if (!sheet) {
        throw new Error(`등록된 ${classIndex + 1}번째 학급에서 '${rosterName}' 탭을 찾지 못했습니다. 탭 이름과 대소문자를 확인하세요.`);
      }
      if (sheet.getLastRow() < 2) {
        throw new Error(`등록된 ${classIndex + 1}번째 학급의 '${rosterName}' 탭에 명단 행이 없습니다. 1행은 제목, 2행부터 학생 정보여야 합니다.`);
      }

      summary.openedRosterSheets += 1;
      const values = sheet.getDataRange().getDisplayValues();
      const headers = headerMap_(values[0]);
      const required = [
        "student_id", "class_code", "class_name", "attendance_number",
        "display_name", "initial_pin", "pin_salt", "pin_hash", "status"
      ];
      const missing = required.filter((name) => headers[name] === undefined);
      if (missing.length) {
        throw new Error(`등록된 ${classIndex + 1}번째 학급의 1행에 필요한 열이 없습니다: ${missing.join(", ")}.`);
      }

      if (normalizeCode_(classCode) !== normalizeCode_(classInfo.classCode || classCode)) {
        throw new Error(`등록된 ${classIndex + 1}번째 학급의 classCode 설정을 확인하세요.`);
      }

      const classJobs = [];
      const seenPins = new Set();
      values.slice(1).forEach((row, index) => {
        const rowNumber = index + 2;
        const initialPin = String(row[headers.initial_pin] || "").trim();
        const salt = String(row[headers.pin_salt] || "").trim();
        const hash = String(row[headers.pin_hash] || "").trim();
        const status = String(row[headers.status] || "").trim().toLowerCase();
        if (!initialPin) {
          if (salt && hash) summary.pinsAlreadyHashed += 1;
          else if (status === "active") summary.rowsWithoutPin += 1;
          return;
        }

        if (!/^\d{4}$/.test(initialPin)) {
          throw new Error(`Students 탭 ${rowNumber}행의 initial_pin이 숫자 4자리가 아닙니다. 셀 서식을 일반 텍스트로 하거나 0000 숫자 서식을 적용하세요.`);
        }
        if (seenPins.has(initialPin)) {
          throw new Error(`등록된 ${classIndex + 1}번째 학급에서 중복 PIN이 발견되었습니다. PIN 값은 표시하지 않았습니다. 학생별로 다른 PIN을 준비하세요.`);
        }
        seenPins.add(initialPin);

        const rowClassCode = normalizeCode_(row[headers.class_code]);
        const rowClassName = normalizeText_(row[headers.class_name]);
        if (rowClassCode !== normalizeCode_(classCode) || rowClassName !== normalizeText_(classInfo.className)) {
          throw new Error(`Students 탭 ${rowNumber}행의 학급 코드 또는 반 값이 Script Properties와 일치하지 않습니다.`);
        }
        if (!String(row[headers.student_id] || "").trim() || !normalizeText_(row[headers.display_name]) || !normalizeNumber_(row[headers.attendance_number])) {
          throw new Error(`Students 탭 ${rowNumber}행에서 student_id, display_name 또는 attendance_number가 비어 있습니다.`);
        }
        classJobs.push({ sheet, rowNumber, headers, initialPin });
      });

      summary.scannedRows += Math.max(0, values.length - 1);
      jobs.push(...classJobs);
    });

    if (!jobs.length) {
      if (summary.pinsAlreadyHashed > 0) {
        Logger.log("PIN 진단: 활성화된 PIN 원문은 없고 이미 해시된 행 %s개를 확인했습니다. 변경할 내용이 없습니다.", summary.pinsAlreadyHashed);
        return summary;
      }
      throw new Error("처리할 initial_pin이 없습니다. 2행 이후의 initial_pin 열과 등록 학급을 확인하세요.");
    }

    jobs.forEach((job) => {
      const salt = Utilities.getUuid().replace(/-/g, "");
      job.sheet.getRange(job.rowNumber, job.headers.pin_salt + 1).setValue(salt);
      job.sheet.getRange(job.rowNumber, job.headers.pin_hash + 1).setValue(pinHash_(job.initialPin, salt, settings.pinPepper));
      job.sheet.getRange(job.rowNumber, job.headers.initial_pin + 1).clearContent();
      summary.pinsHashedNow += 1;
    });

    SpreadsheetApp.flush();
    Logger.log(
      "PIN 초기화 완료(비밀값 미기록): 설정 학급 %s개, 확인한 명단 탭 %s개, 처리 행 %s개, 기존 해시 %s개, PIN 미입력 활성 행 %s개.",
      summary.registeredClasses,
      summary.openedRosterSheets,
      summary.pinsHashedNow,
      summary.pinsAlreadyHashed,
      summary.rowsWithoutPin
    );
    return summary;
  } finally {
    lock.releaseLock();
  }
}
