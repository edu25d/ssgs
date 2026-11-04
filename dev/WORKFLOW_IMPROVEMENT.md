# 작업 방식 개선 계획 — 서버 업로드 대상 구분

**상태: 적용 완료 (2026-09-07)**

목표: **서버에 올릴 파일과 올리지 않을 파일을 항상 헷갈리지 않게 한다.**
적용 범위: 쌤글라스 프로젝트 전체. dothome 서버 업로드가 필요한 모든 작업.

---

## 적용 완료: `app/` / `dev/` 폴더 분리

### 현재 구조

```
쌤글라스/
├── app/              ← 로컬에서만 구분하는 폴더. contents가 서버 루트로 올라감 (app/ 자체는 올리지 않음)
│   ├── api/
│   ├── config/
│   ├── public/
│   ├── views/
│   ├── grading/
│   ├── includes/
│   ├── template/
│   ├── index.php
│   ├── .htaccess
│   ├── google_callback.php
│   └── db 스키마.sql
├── dev/              ← 로컬 전용 작업물 (서버 업로드 대상 아님)
│   ├── docs/
│   ├── GEMINI.md
│   ├── db_clean_reset.sql
│   ├── SERVER_UPLOAD_GUIDE.md
│   └── WORKFLOW_IMPROVEMENT.md
├── .env              ← 로컬만. 서버 업로드 제외 (app/.env에 위치)
├── .env.example      ← 참고용 (업로드 선택, 올려도 안전)
├── .upload_ignore    ← 업로드 제외 패턴 목록 (참고용, 업로드 대상 아님)
└── AGENTS.md         ← 프로젝트 문서 (로컬)
```

**이점:**
- 서버에 올릴 때 `app/` 아래 contents만 골라서 올리면 되고, `app/` 자체나 `dev/` 등은 제외된다.
- `app/` 안에는 `.env`, 로그, 백업 폴더, 로컬 문서가 없으므로 업로드 대상 판단이 단순하다.
- 새 작업을 시작할 때 `app/`(서버 대상) 또는 `dev/`(로컬 전용) 중 어디에 둘지 바로 결정된다.

### transition: 적용된 변경사항

1. 서버 대상 파일/폴더 (`api/`, `config/`, `public/`, `template/`, `views/`, `grading/`, `includes/`, `index.php`, `.htaccess`, `google_callback.php`, `db 스키마.sql`) → `app/`으로 이동
2. 로컬 전용 파일/폴더 (`docs/`, `GEMINI.md`, `db_clean_reset.sql`, `gemini_debug.log`(이후 정리됨), `whoami.txt`(이후 삭제됨), `SERVER_UPLOAD_GUIDE.md`, `WORKFLOW_IMPROVEMENT.md`) → `dev/`로 이동
3. `.env`, `.env.example` → `app/`으로 이동 (서버 업로드 시 `.env`는 제외)
4. `app/config/env.php` → `APP_ROOT` 정의에 `app/` 구조 주석 추가
5. `.upload_ignore` 파일 생성 (루트에 위치, 업로드 제외 패턴 명시)

---

## `.v1104/` 폴더 (로컬 전용 — 업로드 제외)

`.v1104/` 폴더는 `.upload_ignore`에 업로드 제외로 등록되어 있으며, 서버 업로드 대상이 아니다. 이 폴더에는 구 버전 작업본/백업 성격의 뷰 파일, 템플릿 이미지 및 테스트 PDF 파일, 크롭 이미지 등이 포함되어 있다. 정확한 생성 경위는 문서에 기록되어 있지 않으며, 향후 정리(내용 확인 후 백업 후 삭제 또는 `dev/backup/`로 이동)가 필요할 수 있다.

## 주의: 서버 문서 루트 설정

`app/` 폴더 아래 contents가 서버 루트에 올라가므로, **서버의 문서 루트(document root)는 서버 루트 폴더 자체를 가리키면 된다.** 별도로 `app/`을 가리킬 필요가 없다.

로컬의 `app/` 아래 폴더 구조(`api/`, `config/`, `public/` 등)가 그대로 서버 루트에 펼쳐지므로, PHP 파일 경로(`APP_ROOT`)와 웹 URL 경로가 자연스럽게 일치한다.

---

## `.env` 관리 원칙

- `.env` = **실제 Credentials 포함. 로컬 전용. 업로드 금지.** (`app/.env` 위치)
- `.env.example` = **플레이스홀더만 포함. 참고용. 업로드 선택 (올려도 안전).**
- 서버 설정이 필요하면 `.env.example`을 복사해서 서버에서 `.env`를 만들고, 필요한 값을 직접 채운다.

---

## 새 작업 시작 시 규칙

- 서버 업로드 대상 코드는 무조건 `app/` 안에 만든다.
- 로컬 전용 작업(문서, 실험, 백업, 임시 파일)은 무조건 `dev/` 안에 만든다.
- 루트에 새 파일/폴더를 만들 때는 **서버 업로드 대상인지 먼저 판단**한다.
- `.env` 파일은 `app/.env` 위치에 두고, 업로드 시 항상 제외한다.
- 로그 파일은 작업이 끝나면 정리하거나 `dev/`로 옮긴다.

---

## 업로드 직전 체크리스트

- [ ] 업로드 대상을 `app/` 아래 contents로 한정했는가? (`app/` 자체는 올리지 않음)
- [ ] `app/.env`를 업로드에서 제외했는가?
- [ ] `dev/` 폴더를 업로드하지 않았는가?
- [ ] `.v1104/` 폴더를 업로드하지 않았는가?
- [ ] `public/uploads/` 기존 사용자 파일을 덮어쓰지 않도록 주의했는가?
- [ ] 서버 문서 루트가 서버 루트 폴더를 가리키도록 설정되어 있는가?

---

*Created: 2026-09-07 · 적용 완료: 2026-09-07*

---

## 7. 후속 확인·보완 항목 (STUDENT_PRIVACY_ARCHITECTURE.md §7 이관)

STUDENT_PRIVACY_ARCHITECTURE.md의 Phase 1~3은 HANDOFF.md 기준 전체 완료 상태이나, 아래 항목은 **코드 존재 여부와 별개로 실서버 정상 동작을 재확인할 필요가 있는 미세 항목**이다. 바이브 코딩 작업자가 검증을 이어가기 위한 체크리스트다.

> **완료 처리 규칙:** 이 절의 항목을 하나 완료할 때마다 [STUDENT_PRIVACY_ARCHITECTURE.md](<dev/docs/STUDENT_PRIVACY_ARCHITECTURE.md>) §7의 동일 항목도 함께 완료 표시하거나 제거한다. 두 문서는 서로 대응되므로 한쪽만 업데이트하면 상태가 어긋난다. 개인 메모장·체크리스트·이슈 트래커 등으로 양쪽 완료 여부를 동시에 관리하는 것을 권장한다.

### 7-1. `privacy_crypto.js` 배포 확인

- **파일**: `app/public/assets/js/privacy_crypto.js`
- **확인 내용**: 실서버 `public/assets/js/`에 업로드되어 있고, 클라이언트 HTML에서 `<script src="/assets/js/privacy_crypto.js">` 형태로 정상 로드되는지 확인.
- **문제 발생 시**: 404 응답이 뜨면 FTP 업로드 누락. `app/public/assets/js/` 아래 파일 목록을 실서버와 대조.

### 7-2. 학생 해시 로그인 엔드투엔드 재검증

- **파일**: `views/student/login.php`, `api/auth/verify_student_pin.php`
- **확인 내용**: 학생이 실명을 입력했을 때 브라우저에서 `privacy_crypto.js`의 `generateStudentHash()`가 호출되어 정확한 `student_hash`를 계산 후 서버로 전송하고, 서버가 DB에서 해당 해시로 학생을 조회해 로그인 처리하는지 확인.
- **체크 포인트**: 로그인 성공 시 서버 로그나 DB 쿼리에 학생 실명이 노출되지 않는지 확인.

### 7-3. 키오스크 In-Memory Transit 재확인

- **파일**: `api/classes/get_kiosk_students.php`, `views/input/kiosk.php`
- **확인 내용**: 교사 토큰으로 구글 시트 명단이 키오스크 브라우저 메모리에만 전달되고, `student_hash` 및 `student_id`로만 서버 제출이 이루어지는지 확인.
- **체크 포인트**: `get_kiosk_students.php` 응답에 학생 실명이 포함되어도 키오스크 브라우저 외 서버 DB에는 저장되지 않는지 로깅이나 DB 쿼리로 확인.

### 7-4. 구글 시트 명단 열 매핑 일관성 확인

- **파일**: `api/classes/sync_students.php`, `views/dashboard/main.php`, `privacy_crypto.js`
- **확인 내용**: 대시보드에서 학생 저장 시 구글 시트 A열=이름, B열=? 로 저장되는 규약이 `privacy_crypto.js`의 `studentLookupMap` 생성 로직과 일치하는지 확인.
- **체크 포인트**: 시트 열에 맞춰 `studentLookupMap`을 생성하는 JavaScript 코드가 실제 시트 열 인덱스(A=0, B=1)를 올바르게 사용하고 있는지 확인. 불일치 시 해시-실명 매핑이 깨져 화면에 잘못된 이름이 표시된다.

---

## 8. 향후 검토 과제

현재 코드베이스에서 발견되었으나 아직 결론을 내리지 않은 문제다. 해당 과제를 처리할 때 아래 항목을 함께 검토한다.

### 8-1. `app/grading/` vs `app/includes/grading/objective.php` 중복 정리

- **현상**: 채점 관련 PHP 파일이 두 곳에 나뉘어 있다.
  - `app/grading/objective.php`
  - `app/grading/roi_inspector.php`
  - `app/grading/get_results.php`
  - `app/grading/grading_review.php`
  - `app/grading/NanumGothic.ttf`
  - `app/includes/grading/objective.php` (별도 존재)
- **PROJECT_GUIDE_SSOT.md 기술과의 불일치**: 동 문서 §4 I/O 일람표는 `includes/grading/objective.php`를 채점 모듈로 기술하고 있으나, 실제 `app/grading/objective.php`가 별도로 존재한다. 어느 파일이 실제 채점을 담당하는지 확인이 필요하다.
- **`roi_inspector.php` 상태**: `리팩토링-ssot.md`(삭제됨)의 이행 표에서 "제거 대상"으로 언급된 파일이다. 현재 `app/grading/roi_inspector.php`가 남아 있는 이유(레거시 잔재인지, 아직 사용 중인지)를 확인해야 한다.
- **`NanumGothic.ttf` 위치**: 폰트 파일이 `app/grading/` 아래에 있다. `app/public/assets/fonts/`로 옮기는 것이 폴더 용도에 부합할 수 있다.

**처리 방향 (미정)**:
1. `app/grading/objective.php`와 `app/includes/grading/objective.php` 중 실제 채점을 담당하는 파일을 확인.
2. 중복이라면 한쪽으로 일원화하거나, 양쪽이 각각 다른 역할을 한다면 그 차이를 문서에 명시.
3. `roi_inspector.php`가 미사용 레거시라면 삭제, 사용 중이라면 `PROJECT_GUIDE_SSOT.md`에 역할 추가.
4. `NanumGothic.ttf`의 위치를 `public/assets/fonts/`로 이동할지 검토.

### 8-2. `.v1104/` 폴더 정리 (선택)

- `.upload_ignore`에 업로드 제외로 등록되어 있으나, 폴더 내 내용(뷰 백업, 템플릿 테스트 이미지, 크롭 이미지 등)이 현재 코드베이스와 어떤 관계인지 문서화되어 있지 않다.
- 정리 시 우선순위: 내용 확인 → 현재 `app/`에 동일 파일 존재 여부 확인 → 불필요 시 삭제, 백업이 필요하면 `dev/backup/`로 이동 후 문서화.

### 8-3. PIN brute-force 방어 추가 (보안 우선 과제)

- **현상:** 학생 PIN(4자리 숫자) 로그인 진입점 두 곳(`api/auth/verify_student_pin.php`, `views/student/login.php`) 모두 로그인 시도 횟수 제한, 속도 제한, 계정 잠금, CAPTCHA 등 brute-force 방어 수단이 없다.
- **코드상 확인 사항:**
  - `verify_student_pin.php` §56–§58: PIN 불일치 시 단순 `jsonError` 반환. 실패 횟수 기록, 계정 잠금, 지연 없음.
  - `views/student/login.php` §69: PIN 불일치 시 에러 메시지 표시 후 재입력 유도만 함. 실패 횟수 기록, 계정 잠금, 지연 없음.
- **잠재적 피해:** 방어 부재 + 4자리 PIN(10,000가지) 조합으로 특정 학생의 PIN을 자동화 도구로 찾을 수 있음. 탈취된 학생 계정으로 가짜 시험지 제출, 답안 열람, PIN 변경, 이의신청 악용 가능. 다수 학생 대상 자동화 공격도 가능.
- **상세 피해 시나리오:** [dev/docs/STUDENT_PRIVACY_ARCHITECTURE.md](<dev/docs/STUDENT_PRIVACY_ARCHITECTURE.md>) §7-5 및 세션 내 PIN 공격 검토 참고.

**처리 방향 (미정 — 우선 검토 필요):**
1. **최소 방어부터 검토:** 로그인 실패 횟수를 `students` 테이블 또는 별도 테이블에 기록하고, N회(예: 5회) 초과 시 계정 잠금 또는 PIN 재설정 요구. 잠금 해제 절차도 함께 설계.
2. **속도 제한 검토:** 동일한 student_hash/class_id 조합에 대해 단위 시간당 시도 횟수 제한(예: 분당 10회).
3. **CAPTCHA 도입 검토:** 자동화 스크립트 방어 목적. 학생-facing 로그인 화면에 적용 시 사용성 영향 평가 필요.
4. **세션 기반 방어 검토:** 이미 로그인된 세션에서의 PIN 변경(`change_pin.php`)은 별도 보호가 되어 있으나, 새 세션에서의 로그인 brute-force와는 별개. 혼동하지 말 것.
5. **`students.pin` 해시 전환 병행 검토:** PIN을 평문에서 해시로 변경하면 DB 유출 시 피해가 줄고 brute-force도 어려워짐. 다만 4자리 숫자라 해시해도 보안 효과가 제한적이므로, 해시 전환만으로는 충분하지 않고 위 방어 1~3이 반드시 병행되어야 함.

**우선순위:** §8-1, §8-2보다 우선. 로그인 보안은 기능 추가보다 먼저 확보하는 것이 원칙.

### 8-4. 클라이언트 마스킹 실패 시 전송 처리 (방안 A 채택)

- **현상:** `views/input/bulk.php` 및 `views/input/single.php`의 클라이언트 마스킹 파이프라인에서 `detectStudentInfoMaskRois()`(또는 이에 준하는 ROI 탐지)가 빈 배열을 반환하면, `applyClientPrivacyMask()`는 마스킹 연산을 수행하지 않고 마스킹되지 않은 원본 이미지가 그대로 서버로 전송된다. 서버 측 `submit_images.php`는 "단말기에서 이미 마스킹된 이미지를 디스크에 저장"이라는 전제로 작성되어 있어, 마스킹되지 않은 이미지가 도착해도 이를 감지하거나 거부하는 로직이 없다.
- **잠재적 피해:** 인적사항(학생 실명, 학교명 등)이 포함된 시험지 상단 영역이 마스킹되지 않은 상태로 서버 `public/uploads/originals/`에 저장되어, §3.2의 "서버 디스크에 실명이 0%도 남아있지 않다"는 보장이 깨진다. 업로드 뷰의 캔버스 사전 마스킹이 실패해도 서버 측에서 이를 보완하지 않는다.
- **채택 방안 (방안 A — 확인 후 전송):** 마스킹 ROI가 비어있는 상태에서 전송이 시도되면, 사용자에게 상황을 알리고 선택권을 주는 확인 모달을 표시한 후 전송 여부를 결정한다.
  - **모달 문구 예시:**
    > "[인적사항 영역 자동 탐지 실패]
    > 촬영된 시험지의 인적사항(학교, 학년, 반, 번호, 이름) 영역을 자동으로 탐지하지 못했습니다.
    > 현재 상태로 전송하면 실명이 포함된 원본 이미지가 서버로 전송됩니다.
    >
    > [다시 촬영]  [확인 후 전송]"
  - **두 버튼의 의미:**
    - **[다시 촬영]:** 마스킹이 제대로 적용되도록 재촬영을 유도. 기본 권장 동작.
    - **[확인 후 전송]:** 사용자가 상황을 인지하고 의도적으로 전송을 선택하는 경우에만 마스킹 없이 전송. 실수로 누르는 것을 완전히 막을 수는 없으나, 적어도 자동 전송은 방지함.
- **예외 상황 고려 — 개인정보 미수집 시험지:** 경우에 따라 인적사항란을 기재하지 않는 시험지(개인정보 수집이 없는 평가)도 존재할 수 있다. 이 경우 마스킹 ROI가 비어있어도 전송해도 문제가 없다.
  - **처리 방향 (미정):** 시험지 템플릿(`templates` 테이블) 수준에서 "인적사항 마스킹 필요 여부"를 구분하는 플래그 또는 메타데이터를 도입할지 검토. 마스킹이 불필요한 시험지라면 마스킹 ROI 비어있음이 오류가 아니므로 확인 모달 없이 전송 허용. 반대로 마스킹이 필요한 시험지인데 ROI가 비어있다면 방안 A의 모달 표시.
  - **구현 시 주의:** "마스킹 불필요"와 "마스킹 실패"를 구분해야 함. 전자는 정상 상황, 후자는 오류 상황. 두 경우를 혼동하면 마스킹이 필요한 시험지에서 마스킹 없이 전송되는 사고가 발생할 수 있음.
- **관련 코드:** `views/input/bulk.php`의 클라이언트 측 마스킹 로직, `api/grading/submit_images.php`의 서버 수신 로직. `app/includes/common/upstage.php`의 `getOrDetectPrivacyMaskRois()` 및 마스킹 ROI 탐지 결과 처리도 함께 확인.
---

> **주의:** 이 문서의 §7, §8은 진행 중인 확인·검토 항목이다. 각 항목이 해결되면 해당 절에서 완료 처리하고, 완전히 해소된 섹션은 삭제하거나 "완료" 표시를 남긴다.
