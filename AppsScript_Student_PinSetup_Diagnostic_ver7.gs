// Standalone, temporary PIN setup/diagnostic tool.
// Add this as a NEW .gs file to the same Student Apps Script project.
// Run diagnoseAndInitializeRosterPinsVer7 from the Apps Script editor.
// After it succeeds, delete this file and any temporary setup wrapper
// before deploying the student web app.
// Never add this setup function to the student-facing HTML or call it via
// google.script.run.

function diagnoseAndInitializeRosterPinsVer7() {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    const properties = PropertiesService.getScriptProperties();
    const registryText = properties.getProperty("STUDENT_CLASS_REGISTRY_JSON");
    if (!registryText) {
      throw new Error("Script Properties에서 STUDENT_CLASS_REGISTRY_JSON을 찾지 못했습니다. 학생 Apps Script 프로젝트인지 확인하세요.");
    }

    let classes;
    try {
      classes = JSON.parse(registryText);
    } catch (error) {
      throw new Error("STUDENT_CLASS_REGISTRY_JSON의 JSON 형식이 올바르지 않습니다.");
    }

    const pepper = properties.getProperty("PIN_PEPPER");
    if (!pepper || pepper.length < 32) {
      throw new Error("Script Properties에 32자 이상의 PIN_PEPPER가 있는지 확인하세요. 값 자체는 공유하지 마세요.");
    }

    const entries = Object.entries(classes || {});
    if (!entries.length) {
      throw new Error("STUDENT_CLASS_REGISTRY_JSON에 등록된 학급이 없습니다.");
    }

    const summary = {
      configuredClasses: entries.length,
      checkedSheets: 0,
      rowsScanned: 0,
      pinsHashedNow: 0,
      rowsAlreadyHashed: 0,
      rowsWithoutInitialPin: 0
    };
    const pendingWrites = [];

    entries.forEach(([classCode, config], classIndex) => {
      if (!config || !config.className || !config.spreadsheetId) {
        throw new Error(`Script Properties의 ${classIndex + 1}번째 학급 설정에 className 또는 spreadsheetId가 없습니다.`);
      }

      let spreadsheet;
      try {
        spreadsheet = SpreadsheetApp.openById(String(config.spreadsheetId).trim());
      } catch (error) {
        throw new Error(`Script Properties에 등록한 ${classIndex + 1}번째 Sheet를 열지 못했습니다. ID 오타, 파일 삭제 여부, 소유자 접근 권한을 확인하세요.`);
      }

      const sheetName = String(config.rosterSheet || "Students").trim();
      const sheet = spreadsheet.getSheetByName(sheetName);
      if (!sheet) {
        throw new Error(`등록한 학급 파일에서 '${sheetName}' 탭을 찾지 못했습니다. Script Properties의 rosterSheet와 실제 탭 이름을 확인하세요.`);
      }

      const lastRow = sheet.getLastRow();
      if (lastRow < 2) {
        throw new Error(`'${sheetName}' 탭에 학생 행이 없습니다. 1행은 헤더, 2행부터 학생 정보가 있어야 합니다.`);
      }

      const values = sheet.getRange(1, 1, lastRow, sheet.getLastColumn()).getDisplayValues();
      const headers = pinSetupHeaderMap_(values[0]);
      const required = [
        "student_id", "class_code", "class_name", "attendance_number",
        "display_name", "initial_pin", "pin_salt", "pin_hash", "status"
      ];
      const missing = required.filter((name) => headers[name] === undefined);
      if (missing.length) {
        throw new Error(`Students 탭 1행에 필요한 열이 없습니다: ${missing.join(", ")}. 열 이름의 철자와 밑줄을 확인하세요.`);
      }

      summary.checkedSheets += 1;
      summary.rowsScanned += lastRow - 1;
      const seenPins = new Set();

      values.slice(1).forEach((row, index) => {
        const rowNumber = index + 2;
        const initialPin = String(row[headers.initial_pin] || "").trim();
        const salt = String(row[headers.pin_salt] || "").trim();
        const hash = String(row[headers.pin_hash] || "").trim();

        if (!initialPin) {
          if (salt && hash) summary.rowsAlreadyHashed += 1;
          else if (String(row[headers.status]).toLowerCase() === "active") summary.rowsWithoutInitialPin += 1;
          return;
        }

        if (!/^\d{4}$/.test(initialPin)) {
          throw new Error(`Students 탭 ${rowNumber}행의 initial_pin이 숫자 4자리가 아닙니다. 해당 셀을 일반 텍스트로 설정하고 4자리를 다시 입력하세요.`);
        }
        if (seenPins.has(initialPin)) {
          throw new Error(`학급 안에 중복 PIN이 있습니다. PIN을 공개하지 않았으니 서로 다른 번호로 바꾸고 다시 실행하세요.`);
        }
        seenPins.add(initialPin);

        const rowCode = pinSetupNormalizeCode_(row[headers.class_code]);
        const rowClass = pinSetupNormalizeText_(row[headers.class_name]);
        if (rowCode !== pinSetupNormalizeCode_(classCode) || rowClass !== pinSetupNormalizeText_(config.className)) {
          throw new Error(`Students 탭 ${rowNumber}행의 학급 코드 또는 반 이름이 Script Properties와 다릅니다.`);
        }
        if (!String(row[headers.student_id] || "").trim() ||
            !String(row[headers.display_name] || "").trim() ||
            !pinSetupNormalizeNumber_(row[headers.attendance_number])) {
          throw new Error(`Students 탭 ${rowNumber}행에서 student_id, display_name 또는 attendance_number가 비어 있습니다.`);
        }

        pendingWrites.push({
          sheet: sheet,
          row: rowNumber,
          saltColumn: headers.pin_salt + 1,
          hashColumn: headers.pin_hash + 1,
          initialPinColumn: headers.initial_pin + 1,
          initialPin: initialPin
        });
      });
    });

    if (!pendingWrites.length) {
      if (summary.rowsAlreadyHashed) {
        Logger.log("진단 완료: PIN 해시가 이미 있는 행 %s개, PIN 초기화 대상 없음. 시트 변경 없음.", summary.rowsAlreadyHashed);
        return summary;
      }
      throw new Error("initial_pin에 처리할 PIN이 없습니다. 등록된 학급, Students 탭, 2행 이후 열 값을 확인하세요.");
    }

    pendingWrites.forEach((item) => {
      const salt = Utilities.getUuid().replace(/-/g, "");
      const hash = pinSetupHmacHex_(`${salt}:${item.initialPin}`, pepper);
      item.sheet.getRange(item.row, item.saltColumn).setValue(salt);
      item.sheet.getRange(item.row, item.hashColumn).setValue(hash);
      item.sheet.getRange(item.row, item.initialPinColumn).clearContent();
      summary.pinsHashedNow += 1;
    });

    SpreadsheetApp.flush();

    pendingWrites.forEach((item) => {
      const salt = String(item.sheet.getRange(item.row, item.saltColumn).getDisplayValue() || "").trim();
      const hash = String(item.sheet.getRange(item.row, item.hashColumn).getDisplayValue() || "").trim();
      const originalPin = String(item.sheet.getRange(item.row, item.initialPinColumn).getDisplayValue() || "").trim();
      if (!salt || !hash || originalPin) {
        throw new Error("일부 PIN 변경을 다시 읽어 확인하지 못했습니다. 값이나 PIN을 로그로 보내지 말고, 시트의 해당 열과 Apps Script 실행 권한을 확인하세요.");
      }
    });

    Logger.log(
      "진단·초기화 완료: 설정 학급 %s개, 확인한 명단 탭 %s개, 검사 행 %s개, 이번 실행 해시 %s개, 기존 해시 %s개, PIN 미입력 활성 행 %s개. 비밀값은 기록하지 않았습니다.",
      summary.configuredClasses,
      summary.checkedSheets,
      summary.rowsScanned,
      summary.pinsHashedNow,
      summary.rowsAlreadyHashed,
      summary.rowsWithoutInitialPin
    );
    return summary;
  } finally {
    lock.releaseLock();
  }
}

function pinSetupHeaderMap_(headerRow) {
  return headerRow.reduce((map, value, index) => {
    const key = String(value || "").replace(/^\uFEFF/, "").trim().toLowerCase();
    if (key) map[key] = index;
    return map;
  }, {});
}

function pinSetupNormalizeCode_(value) {
  return String(value || "").trim().toUpperCase();
}

function pinSetupNormalizeText_(value) {
  return String(value || "").normalize("NFKC").trim().replace(/\s+/g, "");
}

function pinSetupNormalizeNumber_(value) {
  return String(value || "").trim().replace(/^0+(?=\d)/, "");
}

function pinSetupHmacHex_(value, key) {
  return Utilities.computeHmacSha256Signature(String(value), String(key), Utilities.Charset.UTF_8)
    .map((byte) => ((byte + 256) % 256).toString(16).padStart(2, "0"))
    .join("");
}
