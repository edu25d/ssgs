# 교사 계정 등록 절차 · ver6

이 일회성 절차는 현재 교사 Google 계정과, 그 교사용 Apps Script가 연결된 학급 Google Sheet를 교사용 웹앱 승인 목록에 등록합니다.

## 실행 전 확인

- 현재 Apps Script 편집기를 승인받을 교사 Google 계정으로 열었는지 확인합니다.
- 현재 바인딩된 Sheet가 한 학급의 명단 파일인지 확인합니다. 관리자 통합 시트에서는 실행하지 않습니다.
- `Students` 탭의 활성 학생들이 같은 학급 코드·반 이름을 사용하는지 확인합니다.
- 교사 코드 `setTeacherAccess_`가 같은 Apps Script 프로젝트의 기본 Code 파일에 이미 있는지 확인합니다.

## 한 번만 실행

1. [교사 계정 등록 도우미 다운로드](https://l75cibjp1nxa0w6u.jitda.io/download/teacher-access-setup-ver6.gs)
2. Apps Script 왼쪽 `+` → `스크립트`로 새 파일을 만들고 이름을 `TeacherAccessSetup`으로 지정합니다.
3. 파일 내용을 붙여넣고 저장합니다.
4. 함수 선택 메뉴에서 `registerCurrentTeacherForSetup`을 선택하고 편집기에서 한 번 실행합니다. Google 권한 승인 창이 나오면 Spreadsheet 읽기 권한을 확인하고 허용합니다.
5. 실행 기록에 오류가 없고 일반 완료 메시지가 나오면 등록된 것입니다. 메시지와 로그에는 이메일과 Sheet ID를 출력하지 않습니다.
6. `TeacherAccessSetup.gs` 임시 파일을 바로 삭제하고 프로젝트를 저장합니다. 이후 웹앱을 배포할 때 이 도우미 함수를 남겨 두지 마세요.

이 함수는 현재 Apps Script 실행 계정을 교사로 승인 등록합니다. 승인받을 교사 본인의 계정으로 실행해야 합니다. 교사용 프로젝트 편집 권한을 가진 사람은 이 설정을 바꿀 수 있으므로 프로젝트 편집 권한도 신뢰하는 사람에게만 주세요.

## 완료 후 확인

- 교사 Code에는 `teacherGetContext`와 `setTeacherAccess_`가 있어야 합니다.
- 현재 스프레드시트의 `Students` 탭에는 필요한 `class_code`, `class_name`, `status` 열이 있어야 합니다.
- 실행 뒤에는 Sheet에 계정 이메일이나 새 비밀 값을 쓰지 않습니다. 접근 연결은 해당 Apps Script 프로젝트 내부의 Script Properties에 저장됩니다.
- 이 단계는 로그인 allowlist 등록만 합니다. 교사 웹앱 배포, 담당 Sheet 공유 권한, 실제 채점 화면과 평가 기록 저장은 다음 단계에서 따로 진행합니다.
