# Google Sheets · Apps Script 로그인 설계

문서 버전: ver5

이 버전은 로그인과 역할별 접근 경계만 Apps Script 방식으로 바꿉니다. 학생 평가 제출, 교사 채점 저장, 보충학습 기록, 관리자 통합 집계는 로그인 샘플에 아직 연결하지 않았습니다.

## 확정 구조

### 학생 웹앱

- Google 계정 없이 학급 코드, 반, 번호, 이름, 4자리 PIN으로 접속합니다.
- 학생용 Apps Script 프로젝트는 `소유자 권한으로 실행`합니다. 학생 브라우저는 Google Sheet를 직접 열지 않고 Apps Script 함수에 로그인 정보만 보냅니다.
- 서버가 학급별 비공개 Sheet의 `Students` 탭을 확인하고, 성공하면 짧은 서버 세션을 발급합니다.
- PIN 원문은 저장하지 않습니다. 학생별 salt와 Apps Script Script Properties의 PIN pepper로 HMAC-SHA256 검증값을 보관합니다.
- 실패 응답은 계정 유무를 구분하지 않도록 통일하고, 학급 코드·번호 조합별로 15분 동안 최대 5회 시도를 허용합니다.
- 세션은 15분 미사용 시 만료하고, 발급 후 8시간이 지나면 다시 로그인해야 합니다.
- Apps Script CacheService는 만료 시각보다 먼저 캐시를 비울 수 있습니다. 이런 경우 세션이나 실패 횟수가 초기화될 수 있으므로 다시 로그인하게 하고, 고위험 서비스의 영구 저장소 대용으로 간주하지 않습니다.

### 교사용 웹앱

- 교사용 Apps Script 프로젝트를 학생용과 따로 배포합니다.
- 교사는 Google 계정으로 접속하며, 서버가 로그인 이메일을 승인 목록과 비교합니다.
- 교사용 배포는 `접속한 사용자 권한으로 실행`합니다. 로그인 이메일이 확인되지 않거나 승인되지 않았으면 접근을 거부합니다.
- 외부 Google 계정 사용자는 Google의 Apps Script 권한 승인 화면을 볼 수 있습니다. 검증되지 않은 앱 경고나 조직 정책 차단이 나타나면 학교 관리자가 앱을 신뢰하도록 설정하거나 Google 게시·검증 절차를 진행해야 할 수 있습니다.
- 교사별로 담당 학급 파일만 공유합니다. 교사용 코드는 승인 목록에 연결된 학급 파일만 엽니다.
- 외부 학교·개인 계정은 Google 계정 로그인과 Sheet 접근 승인 절차를 거칩니다. 학교 Workspace 정책에 따라 외부 Apps Script 또는 비검증 경고가 제한될 수 있습니다.
- 로그인 후 평가·피드백 데이터를 읽거나 수정하는 모든 교사용 서버 함수도 매 요청마다 `Session.getActiveUser().getEmail()`과 allowlist를 다시 확인해야 합니다. 브라우저가 보낸 이메일이나 학급 번호만 믿으면 안 됩니다.

### 관리자와 Google Sheets

- 관리자는 별도 웹앱 로그인 화면을 쓰지 않습니다. Google 계정 권한으로 공유받은 관리자 통합 Sheet를 Google Drive에서 직접 엽니다.
- 통합 Sheet는 관리자 이메일에만 Viewer 권한으로 공유합니다. `링크가 있는 모든 사용자` 권한은 사용하지 않습니다.
- 교사에게는 통합 Sheet를 공유하지 않고 담당 학급 파일만 공유합니다. 학생에게는 Sheet 파일을 공유하지 않습니다.
- 학급별 원본 기록을 관리자 통합 Sheet로 모으는 방법(Apps Script 집계 또는 승인된 수식)은 데이터 연결 단계에서 추가합니다. 현재 로그인 샘플은 기록 집계를 구현하지 않습니다.

## 왜 학생·교사 앱을 분리하나요?

Apps Script 웹앱은 배포별로 실행 권한을 정합니다. 학생 앱은 Google 계정이 없는 학생도 써야 하므로 서비스 소유자의 권한으로 동작해야 하고, 교사 앱은 실제 Google 계정 이메일을 확인해야 하므로 접속한 사용자의 권한으로 동작해야 합니다. 한 배포 설정으로 두 요구를 안전하게 함께 충족하기 어려워 서로 다른 Apps Script 프로젝트로 분리합니다.

| 프로젝트 | 실행 권한 | 접근 설정 | 데이터 권한 |
|---|---|---|---|
| 학생 웹앱 | 소유자 권한으로 실행 | 익명 접속 허용(학교 정책이 허용할 때) | 소유자가 관리하는 학급 파일. 서버는 로그인한 학생 본인만 반환 |
| 교사 웹앱 | 접속한 사용자 권한으로 실행 | Google 계정 사용자 | Google이 로그인 이메일을 확인하고, 담당 교사에게 공유된 학급 파일만 접근 |
| 관리자 시트 | 웹앱 아님 | Google Drive 공유 권한 | 관리자 통합 Sheet를 지정된 관리자만 Viewer로 확인 |

## Google Sheets 준비

학급마다 별도의 Spreadsheet를 만들고 `Students` 탭을 추가합니다. 1행에 다음 열 이름을 사용합니다.

```text
student_id | class_code | class_name | attendance_number | display_name | initial_pin | pin_salt | pin_hash | status
```

- `student_id`: 서비스 내부에서 쓰는 학생 ID
- `class_code`: 학생이 받는 학급 코드
- `class_name`: 예: `2학년 3반`
- `attendance_number`: 반 안에서 쓰는 번호
- `display_name`: 화면에 표시할 이름
- `initial_pin`: 최초 준비 때만 넣는 숫자 4자리 PIN
- `pin_salt`, `pin_hash`: 초기화 함수가 생성합니다.
- `status`: 사용 가능한 학생은 `active`

학생 앱에서 학급 코드를 바탕으로 해당 학급 파일을 찾기 때문에, 학생은 파일 ID를 알거나 Google Sheet에 직접 접근할 필요가 없습니다.

## 학생 Apps Script 설정

`AppsScript_Student_Code_ver5.gs`, `AppsScript_Student_Index_ver5.html`, `AppsScript_Student_appsscript_ver5.json`을 새 학생용 Apps Script 프로젝트에 넣습니다. 파일명은 프로젝트 안에서 `Code.gs`, `StudentIndex.html`, `appsscript.json`으로 만들면 됩니다.

`Project Settings → Script Properties`에 두 값을 설정합니다.

- `PIN_PEPPER`: 충분히 긴 무작위 비밀 문자열. Sheet, HTML, 채팅에 넣지 않습니다.
- `STUDENT_CLASS_REGISTRY_JSON`: 학급 코드와 그 학급 Sheet ID의 연결 정보

형식 예시:

```json
{"SCI-26-03":{"className":"2학년 3반","spreadsheetId":"학급_SPREADSHEET_ID","rosterSheet":"Students"}}
```

`PIN_PEPPER`와 Sheet ID는 Apps Script 설정에서만 입력하세요. 학생에게 보내는 웹앱 코드나 URL에는 넣지 않습니다.

### PIN 최초 등록

1. 담당자가 비공개 `Students` 시트의 `initial_pin` 열에 학생마다 다른 4자리 PIN을 임시 입력합니다.
2. Apps Script 편집기에서 `initializeRosterPins_`를 소유자 계정으로 한 번 직접 실행합니다.
3. 함수가 `pin_salt`, `pin_hash`를 채우고 `initial_pin`을 비웁니다.
4. PIN 배부표는 학생별로 전달하고 다운로드·인쇄 잔여물을 파기합니다.

Apps Script의 `google.script.run`은 이름 끝에 `_`가 붙은 함수를 브라우저에서 호출할 수 없습니다. PIN 초기화 함수는 이 규칙을 이용해 학생 공개 화면에서 호출되지 않도록 했습니다.

## 교사 Apps Script 설정

`AppsScript_Teacher_Code_ver5.gs`, `AppsScript_Teacher_Index_ver5.html`, `AppsScript_Teacher_appsscript_ver5.json`을 별도의 교사용 Apps Script 프로젝트에 넣고 파일명을 `Code.gs`, `TeacherIndex.html`, `appsscript.json`으로 설정합니다.

교사용 Apps Script 프로젝트의 `Code.gs`에는 소유자(신뢰하는 프로젝트 편집자)만 편집기에서 실행하는 `setTeacherAccess_`와 `disableTeacherAccess_`가 있습니다. 이 함수들은 이메일 전체 목록을 한 개의 Script Property에 저장하지 않고, 이메일의 SHA-256 식별자를 이용해 교사별 설정으로 저장합니다.

Apps Script 편집기에서 아래처럼 담당 교사와 학급 파일을 한 명씩 등록합니다.

```javascript
setTeacherAccess_(
  "teacher@example.edu",
  "담당 선생님",
  JSON.stringify([
    { className: "2학년 3반", spreadsheetId: "담당_학급_SPREADSHEET_ID" }
  ])
);
```

교사의 추가 학급이 있으면 `classFiles` 배열에 해당 학급 파일을 추가합니다. 승인 철회는 아래처럼 처리합니다.

```javascript
disableTeacherAccess_("teacher@example.edu");
```

등록 전에 각 학급 Spreadsheet를 해당 교사에게만 공유해야 합니다. 교사용 코드는 요청에서 임의로 보낸 이메일이나 파일 ID를 믿지 않고 `Session.getActiveUser().getEmail()`과 소유자가 등록한 담당 파일 목록을 사용합니다.

## 배포

### 학생 웹앱

1. 학생 Apps Script 프로젝트에 위 파일을 추가하고 Script Properties를 설정합니다.
2. 학급 명단과 PIN을 준비한 뒤 `initializeRosterPins_`를 실행합니다.
3. `Deploy → New deployment → Web app`을 선택합니다.
4. `Execute as`는 소유자 계정으로 설정합니다.
5. 학생이 Google 계정 없이 접속해야 하므로 접근 설정은 익명 포함 공개가 필요합니다. Workspace 관리자가 이를 막아 두었다면 설정 변경을 요청하거나 다른 승인된 호스팅 방식을 선택해야 합니다.
6. 학생에게 `/exec` 주소와 각자의 PIN을 전달합니다. Sheet URL이나 다른 학생 PIN은 공유하지 않습니다.

### 교사 웹앱

1. 교사 Apps Script 프로젝트에서 교사 이메일 allowlist를 설정합니다.
2. 담당 교사의 Google 이메일을 승인 목록에 넣고, 그 교사에게 담당 학급 파일만 공유합니다.
3. `Deploy → New deployment → Web app`을 선택합니다.
4. `Execute as`는 `접속한 사용자`, 접근 대상은 Google 계정이 있는 사용자로 설정합니다.
5. 실제 학교 계정과 개인 Google 계정으로 이메일 확인을 시험합니다. 이메일이 비어 있으면 웹앱은 기본적으로 접근을 거부합니다.

### 관리자 통합 Sheet

- 통합 기록 Sheet를 별도로 만들고, Google Drive의 Share 기능으로 관리자 계정만 Viewer로 추가합니다.
- 학급별 Sheet에서 관리 시트로 데이터를 모으는 집계 작업은 로그인 이후의 다음 구현 단계입니다. 아직 Google Sheet로 평가 결과가 자동 누적되는 상태는 아닙니다.
- 실제 제출 기능을 붙일 때도 학생 브라우저는 Sheet에 직접 쓰지 않습니다. Apps Script가 세션 토큰을 확인하고 서버에서만 학급 원본 Sheet에 기록합니다.
- 학급별 기록과 관리자 통합 시트의 값이 맞는지, 재시도 시 중복 행이 생기지 않는지, 담당 학급이 아닌 교사 접근을 차단하는지 별도 테스트해야 합니다.

## 로컬 미리보기와 실제 배포의 차이

프로젝트 폴더에는 웹 화면 데모와 Apps Script에 붙여 넣을 원본이 함께 있습니다. 로컬 미리보기의 학생 로그인은 샘플 명단으로만 확인하며 Google Sheets에 저장하지 않습니다. 실제 로그인·세션 검증은 Apps Script에 코드를 넣고 위 배포 권한으로 게시해야 동작합니다.

학생 4자리 PIN 로그인은 교실에서 쓰기 쉬운 대신 강한 비밀번호와 같지 않습니다. PIN 속도 제한·재시도 제한을 유지하고, 학생 결과는 서버가 명단에서 인증한 내부 학생 ID로만 조회해야 합니다.

## 공식 참고자료

- Apps Script 웹앱 배포 권한: https://developers.google.com/apps-script/guides/web
- Apps Script 현재 사용자 이메일 제공 조건: https://developers.google.com/apps-script/reference/base/session#getActiveUser()
- Apps Script 배포 버전 관리: https://developers.google.com/apps-script/concepts/deployments
- Apps Script OAuth 권한: https://developers.google.com/apps-script/guides/services/authorization
