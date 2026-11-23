# PIN 초기화 진단 · ver7

`initializeRosterPins_` 실행이 완료로 보이는데 시트 값이 바뀌지 않거나, `studentSettings_ is not defined` 오류가 날 때 쓰는 임시 진단 스크립트입니다.

## 실행 방법

1. [독립 진단 Apps Script 파일 다운로드](https://l75cibjp1nxa0w6u.jitda.io/download/pin-setup-diagnostic-ver7.gs)
2. 기존 `Code.gs`를 덮어쓰지 말고, 학생용 Apps Script 편집기에서 새 스크립트 파일을 추가해 `PinSetupDiagnostic`이라고 이름을 붙입니다.
3. 다운로드한 파일 내용을 붙여넣고 저장합니다.
4. 상단 함수 선택 메뉴에서 `diagnoseAndInitializeRosterPinsVer7`를 선택해 실행합니다.
5. 왼쪽 `실행 기록`을 열어 상태와 로그를 확인합니다. 성공 로그에는 처리 행 개수만 표시되며 PIN, salt, hash, Sheet ID, 학생 이름은 출력되지 않습니다.
6. 성공이면 가상 학생 행에서 `initial_pin`이 비고 `pin_salt`, `pin_hash`가 채워졌는지 직접 확인합니다. 그 값들은 복사하거나 공유하지 않습니다.
7. 확인이 끝나면 `PinSetupDiagnostic` 임시 파일과 이전에 추가한 `runRosterPinSetupOnce` 임시 함수가 있다면 삭제하고 저장합니다. 이 진단 함수가 포함된 프로젝트를 웹앱으로 배포하지 마세요.

## 진단이 확인하는 항목

- 현재 열린 프로젝트에 `STUDENT_CLASS_REGISTRY_JSON`과 충분한 길이의 `PIN_PEPPER`가 있는지
- 등록된 학급 설정과 Spreadsheet ID로 시트를 열 수 있는지
- 설정한 명단 탭이 존재하고 학생 행이 있는지
- 1행의 필수 열 이름이 맞는지
- 학급 코드·반과 학생 명단 행이 설정과 일치하는지
- 초기 PIN이 숫자 4자리이며 처리 대상이 있는지
- PIN 해시 후 원문 칸이 비고 해시·salt가 실제로 채워졌는지

오류 메시지만 공유해 주세요. PIN, 학급 Sheet URL/ID, PIN salt/hash, Script Properties 값은 공유하지 마세요.
