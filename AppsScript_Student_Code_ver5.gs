const STUDENT_IDLE_TTL_SECONDS = 15 * 60;
const STUDENT_MAX_SESSION_MS = 8 * 60 * 60 * 1000;
const STUDENT_LOGIN_WINDOW_SECONDS = 15 * 60;
const STUDENT_LOGIN_MAX_FAILURES = 5;

function doGet() {
  return HtmlService.createHtmlOutputFromFile("StudentIndex")
    .setTitle("이어봄 학생 접속")
    .addMetaTag("viewport", "width=device-width, initial-scale=1");
}

function studentLogin(payload) {
  const input = normalizeStudentInput_(payload);
  if (!input.classCode || !input.className || !input.attendanceNumber || !input.name || !/^\d{4}$/.test(input.pin)) {
    return { ok: false, message: "모든 항목을 확인해 주세요." };
  }

  const throttleKey = studentThrottleKey_(input);
  if (isLoginBlocked_(throttleKey)) {
    return { ok: false, message: "입력 내용을 확인하거나 잠시 기다린 뒤 다시 시도해 주세요." };
  }

  try {
    const settings = studentSettings_();
    const classInfo = settings.classes[input.classCode];
    if (!classInfo || normalizeText_(classInfo.className) !== input.className) return failStudentLogin_(throttleKey);

    const sheet = SpreadsheetApp.openById(classInfo.spreadsheetId)
      .getSheetByName(classInfo.rosterSheet || "Students");
    if (!sheet || sheet.getLastRow() < 2) {
      return { ok: false, message: "학생 명단이 아직 준비되지 않았습니다. 담당 선생님께 알려 주세요." };
    }

    const values = sheet.getDataRange().getDisplayValues();
    const headers = headerMap_(values[0]);
    const requiredHeaders = ["student_id", "class_code", "class_name", "attendance_number", "display_name", "pin_salt", "pin_hash", "status"];
    if (requiredHeaders.some((header) => headers[header] === undefined)) {
      throw new Error("Students sheet headers do not match the login schema.");
    }

    const row = values.slice(1).find((cells) =>
      normalizeCode_(cells[headers.class_code]) === input.classCode &&
      normalizeText_(cells[headers.class_name]) === input.className &&
      normalizeNumber_(cells[headers.attendance_number]) === input.attendanceNumber &&
      normalizeText_(cells[headers.display_name]) === input.name &&
      String(cells[headers.status]).toLowerCase() === "active"
    );

    if (!row || !row[headers.pin_salt] || !row[headers.pin_hash]) return failStudentLogin_(throttleKey);
    const actualHash = pinHash_(input.pin, row[headers.pin_salt], settings.pinPepper);
    if (!constantTimeEqual_(actualHash, String(row[headers.pin_hash]))) return failStudentLogin_(throttleKey);

    CacheService.getScriptCache().remove(throttleKey);
    const student = {
      studentId: String(row[headers.student_id]),
      classCode: input.classCode,
      className: String(row[headers.class_name]),
      attendanceNumber: normalizeNumber_(row[headers.attendance_number]),
      displayName: String(row[headers.display_name])
    };
    return {
      ok: true,
      token: createStudentSession_(student),
      expiresInSeconds: STUDENT_IDLE_TTL_SECONDS,
      student: {
        className: student.className,
        attendanceNumber: student.attendanceNumber,
        displayName: student.displayName
      }
    };
  } catch (error) {
    console.error("Student login failed", error);
    return { ok: false, message: "지금은 접속할 수 없습니다. 설정과 명단을 확인해 주세요." };
  }
}

function studentGetContext(sessionToken) {
  const session = readStudentSession_(sessionToken);
  if (!session) return { ok: false, message: "접속 시간이 끝났습니다. 다시 로그인해 주세요." };
  return {
    ok: true,
    student: {
      className: session.className,
      attendanceNumber: session.attendanceNumber,
      displayName: session.displayName
    },
    expiresInSeconds: STUDENT_IDLE_TTL_SECONDS
  };
}

function studentLogout(sessionToken) {
  const token = String(sessionToken || "");
  if (token) CacheService.getScriptCache().remove(studentSessionKey_(token));
  return { ok: true };
}

// Run only from the Apps Script editor after temporarily entering initial_pin
// in the private roster sheet. The function hashes and clears every PIN.
function initializeRosterPins_() {
  const settings = studentSettings_();
  Object.values(settings.classes).forEach((classInfo) => {
    const sheet = SpreadsheetApp.openById(classInfo.spreadsheetId)
      .getSheetByName(classInfo.rosterSheet || "Students");
    if (!sheet || sheet.getLastRow() < 2) return;
    const values = sheet.getDataRange().getDisplayValues();
    const headers = headerMap_(values[0]);
    ["initial_pin", "pin_salt", "pin_hash", "attendance_number"].forEach((header) => {
      if (headers[header] === undefined) throw new Error(`Students 시트에 ${header} 열을 추가해 주세요.`);
    });
    values.slice(1).forEach((row, index) => {
      const pin = String(row[headers.initial_pin] || "").trim();
      if (!pin) return;
      if (!/^\d{4}$/.test(pin)) throw new Error(`출석번호 ${row[headers.attendance_number]}의 PIN은 숫자 4자리여야 합니다.`);
      const salt = Utilities.getUuid().replace(/-/g, "");
      const targetRow = index + 2;
      sheet.getRange(targetRow, headers.pin_salt + 1).setValue(salt);
      sheet.getRange(targetRow, headers.pin_hash + 1).setValue(pinHash_(pin, salt, settings.pinPepper));
      sheet.getRange(targetRow, headers.initial_pin + 1).clearContent();
    });
  });
}

function normalizeStudentInput_(payload) {
  const input = payload || {};
  return {
    classCode: normalizeCode_(input.classCode),
    className: normalizeText_(input.className),
    attendanceNumber: normalizeNumber_(input.attendanceNumber),
    name: normalizeText_(input.name),
    pin: String(input.pin || "").trim()
  };
}

function studentSettings_() {
  const properties = PropertiesService.getScriptProperties();
  const rawClasses = properties.getProperty("STUDENT_CLASS_REGISTRY_JSON") || "{}";
  const classes = JSON.parse(rawClasses);
  const pinPepper = properties.getProperty("PIN_PEPPER");
  if (!pinPepper || pinPepper.length < 32) throw new Error("PIN_PEPPER script property is missing or too short.");
  return { classes, pinPepper };
}

function failStudentLogin_(throttleKey) {
  const cache = CacheService.getScriptCache();
  const lock = LockService.getScriptLock();
  lock.waitLock(3000);
  try {
    const failures = Number(cache.get(throttleKey) || 0) + 1;
    cache.put(throttleKey, String(failures), STUDENT_LOGIN_WINDOW_SECONDS);
  } finally {
    lock.releaseLock();
  }
  return { ok: false, message: "학급 정보와 로그인 정보를 확인해 주세요." };
}

function isLoginBlocked_(key) {
  return Number(CacheService.getScriptCache().get(key) || 0) >= STUDENT_LOGIN_MAX_FAILURES;
}

function studentThrottleKey_(input) {
  const pepper = PropertiesService.getScriptProperties().getProperty("PIN_PEPPER") || "not-configured";
  return `login-fail:${hmacHex_(`${input.classCode}|${input.attendanceNumber}`, pepper)}`;
}

function createStudentSession_(student) {
  const token = `${Utilities.getUuid()}${Utilities.getUuid()}`.replace(/-/g, "");
  const record = Object.assign({}, student, { startedAt: Date.now() });
  CacheService.getScriptCache().put(studentSessionKey_(token), JSON.stringify(record), STUDENT_IDLE_TTL_SECONDS);
  return token;
}

function readStudentSession_(tokenValue) {
  const token = String(tokenValue || "");
  if (token.length < 48 || token.length > 128) return null;
  const cache = CacheService.getScriptCache();
  const key = studentSessionKey_(token);
  const raw = cache.get(key);
  if (!raw) return null;
  const record = JSON.parse(raw);
  if (Date.now() - Number(record.startedAt) > STUDENT_MAX_SESSION_MS) {
    cache.remove(key);
    return null;
  }
  cache.put(key, JSON.stringify(record), STUDENT_IDLE_TTL_SECONDS);
  return record;
}

function studentSessionKey_(token) {
  return `student-session:${hmacHex_(String(token), "session-key")}`;
}

function pinHash_(pin, salt, pepper) {
  return hmacHex_(`${salt}:${pin}`, pepper);
}

function hmacHex_(value, key) {
  return Utilities.computeHmacSha256Signature(String(value), String(key), Utilities.Charset.UTF_8)
    .map((byte) => ((byte + 256) % 256).toString(16).padStart(2, "0"))
    .join("");
}

function constantTimeEqual_(left, right) {
  if (left.length !== right.length) return false;
  let mismatch = 0;
  for (let index = 0; index < left.length; index += 1) mismatch |= left.charCodeAt(index) ^ right.charCodeAt(index);
  return mismatch === 0;
}

function headerMap_(headerRow) {
  return headerRow.reduce((map, value, index) => {
    map[String(value).trim().toLowerCase()] = index;
    return map;
  }, {});
}

function normalizeCode_(value) {
  return String(value || "").trim().toUpperCase();
}

function normalizeText_(value) {
  return String(value || "").normalize("NFKC").trim().replace(/\s+/g, "");
}

function normalizeNumber_(value) {
  return String(value || "").trim().replace(/^0+(?=\d)/, "");
}
