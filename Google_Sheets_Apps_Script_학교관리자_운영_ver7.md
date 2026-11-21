# 학교 관리자 운영 구조 · ver7

## 역할 구조

관리자 웹앱은 만들지 않습니다. 학교 관리자는 관리자 Google 계정에 공유된 통합 Google Sheet를 직접 열고, 그 Sheet에 연결된 학교 관리자 메뉴를 사용합니다.

- 학생: 익명 공개로 배포한 학생 Apps Script 웹앱 사용. Sheet 파일은 공유하지 않습니다.
- 교사: 별도 교사용 Apps Script 웹앱 사용. Google 계정을 확인하고 본인 담당 학급 Sheet만 공유받습니다.
- 관리자: 관리자 소유 통합 Google Sheet를 직접 열고 바인딩된 `🏫 [학교 관리자]` 메뉴 사용.

## 중요한 파일 소유권 결정

관리자 메뉴가 교사 승인 때 담당 학급 Sheet에 `_TeacherAccess` 탭을 만들고 Drive 공유 권한도 부여·회수합니다. 따라서 담당 학급 Sheet의 소유자는 학교의 Super Admin/운영 계정이어야 합니다. 교사가 소유자인 파일은 관리자 메뉴가 공유를 회수하거나 보호된 접근 정보를 통제할 수 없습니다.

현재 학생 명단 Sheet를 교사가 소유하고 있다면, 관리자 소유의 학급 Sheet로 복사·이전하는 것을 권장합니다. PIN은 해시값만 복사하고 `initial_pin` 원문은 옮기지 않습니다. 학생용 Apps Script의 학급 registry도 새 파일 ID로 갱신하고 학생 재로그인 테스트를 마칩니다.

관리자 Apps Script를 사용할 Super Admin 계정은 다음 파일을 소유하거나 공유 관리 권한이 있어야 합니다.

- 관리자 통합 Google Sheet와 그 바인딩 Apps Script
- 승인·회수하려는 학급별 Google Sheet

## 프로젝트 배치

### 1. 학생 웹앱

기존 학생용 Apps Script 프로젝트를 유지합니다.

- 실행 계정: 학생 웹앱 소유자
- 학생 인증: 학급 코드·반·번호·이름·4자리 PIN
- 데이터 원본: 관리자 소유의 학급별 Sheet. 학생에게 파일 공유 안 함.
- PIN 초기화 진단 파일은 이미 사용 후 삭제했으면 다시 넣지 않습니다.

### 2. 교사 웹앱

교사 학급 Sheet의 `확장 프로그램 → Apps Script`로 연 바인딩 프로젝트를 유지합니다.

- `Code.gs`는 [AppsScript_Teacher_Code_ver7.gs](./AppsScript_Teacher_Code_ver7.gs) 내용으로 교체합니다. 현재 파일은 먼저 백업해 둡니다.
- `TeacherIndex.html`은 기존 파일을 유지합니다.
- `appsscript.json`은 [AppsScript_Teacher_appsscript_ver7.json](./AppsScript_Teacher_appsscript_ver7.json)으로 갱신합니다.
- 관리자 메뉴에서 교사가 승인되면 학급 Sheet에 숨김·보호된 `_TeacherAccess` 탭이 만들어집니다.
- Code.gs를 바꾼 뒤 교사 편집기에서 `setupTeacherClassContext`를 수동 실행해 현재 학급 파일 연결을 저장합니다.
- 새 Code.gs와 manifest를 저장한 뒤 Apps Script 편집기에서 `setupTeacherClassContext`를 교사 계정으로 한 번 실행하고 Spreadsheet 권한을 승인합니다. 관리자 메뉴가 해당 파일에 `_TeacherAccess`를 만든 뒤 실행해야 합니다.
- 교사 웹앱은 `접속한 사용자 권한으로 실행`하도록 배포해야 합니다. 이 설정에서 로그인 이메일을 확인하고, 교사에게 공유된 학급 Sheet만 엽니다.
- `setupTeacherClassContext`는 이 bound 프로젝트의 현재 Sheet ID만 저장하고, `_TeacherAccess`에서 실행 계정이 승인 교사인지 먼저 확인합니다. 임의 Sheet ID를 인자로 받지 않습니다. 일반 로그인 확인 함수는 `teacherGetContext`입니다.

### 3. 관리자 통합 Sheet

별도의 관리자 통합 Google Sheet를 사용합니다. 관리자 UI는 그 시트의 상단 메뉴입니다.

- 새 관리자 통합 Sheet를 Super Admin 계정 소유로 만듭니다.
- Apps Script 파일에는 [AppsScript_Admin_Master_Menu_Code_ver7.gs](./AppsScript_Admin_Master_Menu_Code_ver7.gs)를 `Code.gs` 전체 내용으로 넣습니다. 기존 Code.gs가 있으면 먼저 별도로 백업합니다.
- 프로젝트 매니페스트는 [AppsScript_Admin_Master_appsscript_ver7.json](./AppsScript_Admin_Master_appsscript_ver7.json)을 사용합니다.
- 통합 Sheet를 관리자 Google 계정에만 공유합니다. 교사와 학생에게 통합 Sheet를 공유하지 않습니다.
- `Code.gs`를 교사용 Apps Script 프로젝트에 넣지 마세요. 관리자 메뉴는 관리자 통합 Sheet의 바인딩 Apps Script 전용입니다.
- 이전 ver6 통합 코드는 교사 웹앱과 관리자 메뉴를 한 프로젝트에 섞은 중간안입니다. 이번 구조에서는 사용하지 말고, 관리자 통합 Sheet에는 ver7 관리자 코드만 넣습니다.
- 관리자 바인딩 스크립트에는 웹앱 배포를 하지 않습니다. Super Admin이 통합 Sheet에서 메뉴를 사용합니다.

## 초기 관리자 등록

1. Super Admin 소유 계정으로 관리자 통합 Sheet를 만들고 바인딩 Apps Script에 Admin Code.gs와 매니페스트를 적용합니다.
2. 관리자 바인딩 Apps Script의 `Project Settings → Script Properties`에서 `SCHOOL_ADMIN_EMAILS`를 추가하고, 값에는 Super Admin Google 계정 이메일 하나를 입력합니다.
3. Apps Script 편집기에서 코드를 저장하고, Spreadsheet·Drive 접근 권한 요청이 나오면 Super Admin이 승인합니다.
4. 통합 Sheet를 닫았다 다시 열면 `🏫 [학교 관리자]` 메뉴가 나타납니다.
5. 이후 다른 관리자 계정은 4번 메뉴에서 추가하고, 관리자 통합 Sheet에도 Google Drive 공유 권한을 부여합니다.

`SCHOOL_ADMIN_EMAILS`에는 실제 승인된 Super Admin만 입력합니다. 이 Script Property와 Apps Script 편집 권한은 Super Admin만 관리해야 합니다.

## 교사 승인 흐름

1. 모든 학급 Sheet의 소유자를 Super Admin/학교 운영 계정으로 정리합니다.
2. 관리자는 통합 Sheet에서 `🏫 [학교 관리자] → 4. 교사 관리자 계정 등록 및 권한 확인`을 선택합니다.
3. `teacher`를 선택하고 교사 Google 계정 이메일, 표시 이름, 담당 학급명, 해당 학급 Sheet ID를 입력합니다.
4. 스크립트가 활성 `Students` 명단에서 학급 코드·학급명을 확인합니다.
5. 관리자 소유의 학급 Sheet를 해당 교사에게 공유하고 `_TeacherAccess` 탭에 해당 교사의 승인 기록을 씁니다.
6. 통합 Sheet의 숨김 `TeacherAccess`에는 관리자 조회용 권한 메타데이터를 기록합니다.
7. 교사는 자기 학급 Sheet의 바인딩 Apps Script에서 `setupTeacherClassContext`를 실행한 뒤 교사 웹앱을 `접속한 사용자 권한으로 실행`으로 배포합니다.
8. Teacher Code ver7의 `teacherGetContext`는 Google 로그인 이메일과 현재 학급 파일의 `_TeacherAccess` 승인 행을 함께 확인합니다.

교사 파일 등록 도중 “관리자 소유 파일이어야 한다”는 메시지가 나오면 교사 소유 파일을 그대로 승인하지 않습니다. 관리자 소유 사본으로 옮기고 PIN 원문을 복사하지 않은 뒤, 학급 registry와 권한을 새 ID로 설정합니다.

교사를 회수할 때 메뉴 4에서 `revoke`를 선택합니다. 현재 코드는 `_TeacherAccess` 상태를 `disabled`로 바꾸고 직접 공유된 교사 편집 권한을 제거합니다. 교사가 파일 소유자이면 Drive 권한만으로 소유권을 회수할 수 없으므로 승인 전에 소유권을 확인합니다.

## 7개 관리자 메뉴 기능

1. 프롬프트 대시보드: 문서 속성에 초안을 저장합니다. 게시 전까지 채점에 사용하지 않습니다.
2. 시스템 초기화: 활성 기록 시트의 A:D 헤더, E 이후 빈 확장 헤더, 스타일, 행 고정을 적용합니다. 학생 명단·권한 Sheet는 변경을 거부합니다. 기존 잘못된 헤더는 숨김 백업 탭에 보관합니다.
3. 제출 점검: 선택한 제출 기록 시트에서 답안 수와 UTF-8 바이트 크기만 계산하며 답안 본문은 출력하지 않습니다.
4. 계정·권한: 관리자 등록 또는 관리자 소유 학급 파일에 교사를 연결·회수합니다.
5. 공식 프롬프트 스냅샷: 초안과 SHA-256 지문을 별도 보호 탭으로 남깁니다. Spreadsheet 소유자는 보호를 바꿀 수 있습니다.
6. System Doctor: 관리자 등록, 선택한 탭의 명단/기록 구분, 시간대, 필수 헤더를 확인합니다.
7. 합성 테스트: 가상 문자열로 헤더 정규화, 바이트 계산, CSV 수식 보호를 검사합니다.

## 아직 하지 않은 작업

- 이 메뉴는 `Submissions`, AI 채점 및 학습 결과를 관리자 통합 Sheet로 모으는 파이프라인이 아닙니다. 데이터 저장·집계는 다음 단계에서 구현해야 합니다.
- 평가/점수/대화 각 탭의 열별 권한, 삭제 주기, 백업 복구와 감사 로그를 별도 설계·테스트해야 합니다.
- 교사 Google 계정의 이메일은 배포 설정과 Workspace 정책에 따라 비어 있을 수 있습니다. 실제 교사 계정으로 교사 앱을 배포 시험하기 전에는 학생 데이터를 연결하지 않습니다.
- 바인딩된 관리자 시트가 여러 편집자에게 공유되면 이들은 시트 내용을 직접 볼 수 있습니다. ScriptProperties의 관리자 allowlist는 시트의 Google Drive 공유 권한을 대체하지 않습니다.

## 공식 참고

- Apps Script 웹앱 배포·실행 권한: https://developers.google.com/apps-script/guides/web
- Apps Script 현재 사용자 이메일: https://developers.google.com/apps-script/reference/base/session#getActiveUser()
- Apps Script 배포 관리: https://developers.google.com/apps-script/concepts/deployments
- Apps Script OAuth 권한: https://developers.google.com/apps-script/guides/services/authorization
