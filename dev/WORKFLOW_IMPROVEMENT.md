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
│   └── google_callback.php
├── dev/              ← 로컬 전용 작업물 (서버 업로드 대상 아님)
│   ├── docs/
│   ├── GEMINI.md
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

1. 서버 대상 파일/폴더 (`api/`, `config/`, `public/`, `template/`, `views/`, `grading/`, `includes/`, `index.php`, `.htaccess`, `google_callback.php`, `db 스키마.sql`) → `app/`으로 이동 (완료, `db 스키마.sql`은 이후 삭제됨)
2. 로컬 전용 파일/폴더 (`docs/`, `GEMINI.md`, `db_clean_reset.sql`, `gemini_debug.log`(이후 정리됨), `whoami.txt`(이후 삭제됨), `SERVER_UPLOAD_GUIDE.md`, `WORKFLOW_IMPROVEMENT.md`) → `dev/`로 이동 (완료, `db_clean_reset.sql`은 이후 삭제됨)
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

### 7-1. `privacy_crypto.js` 배포 확인 ✅ 완료 (2026-09-07)

- **파일**: `app/public/assets/js/privacy_crypto.js`
- **확인 결과**:
  - 실서버 `public/assets/js/`에 업로드 확인: `https://jinsu2.dothome.co.kr/public/assets/js/privacy_crypto.js` → Status 200 ✅
  - 클라이언트 HTML 로드: `app/views/input/kiosk.php` L379 `<script src="/public/assets/js/privacy_crypto.js">` ✅
  - 로컬-서버 파일 내용 일치 ✅
- **문제 발생 시**: 404 응답이 뜨면 FTP 업로드 누락. `app/public/assets/js/` 아래 파일 목록을 실서버와 대조.

### 7-2. 학생 해시 로그인 엔드투엔드 재검증 ✅ 완료 (2026-09-07)

- **파일**: `views/student/login.php`, `api/auth/verify_student_pin.php`
- **확인 결과**:
  - `views/student/login.php`: 학생 실명 + PIN을 POST로 수신 → 서버 측 `makeStudentHash()` (PHP SHA-256)로 `student_hash` 계산 → `student_hash`로 DB 조회 → PIN 일치 시 로그인. 브라우저 `generateStudentHash()` 호출은 없음 (서버 측 계산이 의도된 설계).
  - `api/auth/verify_student_pin.php` (키오스크용): 클라이언트가 사전 계산한 `student_hash` 수신 → DB 조회 → PIN 검증.
  - PHP `makeStudentHash()`와 JS `generateStudentHash()` 모두 SHA-256 → 32자리 hex로 **결과 동일** 확인 ✅
  - DB 쿼리는 `student_hash` 기준 (실명 미노출). 실명은 세션(`$_SESSION['student_name']`)에만 일시 저장.
- **체크 포인트**: 로그인 성공 시 서버 로그나 DB 쿼리에 학생 실명이 노출되지 않는지 → DB 쿼리는 `student_hash` 기준, 실명 노출되지 않음 ✅ (단, `students.name` 컬럼 존재 및 세션 실명 보관은 §7-5, §7-8 별도 항목)
- **참고**: 브라우저 `generateStudentHash()` 호출 부재는 서버 측 해시 계산 설계로 확인. 키오스크(view/input/kiosk.php)는 브라우저 계산 사용.

### 7-3. 키오스크 In-Memory Transit 재확인 ✅ 완료 (2026-09-07)

- **파일**: `api/classes/get_kiosk_students.php`, `views/input/kiosk.php`
- **확인 결과**:
  - 구글 시트(SSOT) A5:B55에서 실명(name) 조회 → 서버 측에서 `makeStudentHash()`로 해시 계산 → 키오스크 브라우저에만 응답으로 전달.
  - `get_kiosk_students.php` 응답에는 `{name, student_hash, student_id}` 포함 — 실명은 키오스크 브라우저 transit용이며 서버 DB 저장 없음 ✅.
  - `kiosk.php` 학생 카드 클릭 시 `handleStudentClick(id, name, hash)`로 name+hash 브라우저 전달 → PIN 인증(`verify_student_pin.php`) 시 `student_hash` 기반 조회, 실명은 세션 저장 목적으로만 전송.
  - 서버 DB는 `student_hash` 기반으로만 학생 조회·저장. 실명 DB 영구 저장 없음 ✅.
- **체크 포인트**: `get_kiosk_students.php` 응답 실명 → 키오스크 브라우저 외 서버 DB 미저장 ✅ (응답 transit 데이터, DB 저장 로직 없음)

### 7-4. 구글 시트 명단 열 매핑 일관성 확인 ✅ 완료 (2026-09-07)

- **파일**: `api/classes/sync_students.php`, `views/input/kiosk.php`, `api/classes/get_kiosk_students.php`, `privacy_crypto.js`
- **확인 결과**:
  - 구글 시트 쓰기(`sync_students.php` L119): A열=name, B열=pin → `[$st['name'], $st['pin']]`
  - 구글 시트 읽기(`get_kiosk_students.php` L65, `kiosk.php` L80): A열(index 0)=name만 읽음 → `$name = trim($row[0] ?? '')`
  - A/B 열 매핑 쓰기-읽기 간 **일관됨** ✅ (읽기는 B열 미사용, 충돌 없음)
  - `studentLookupMap` JavaScript 생성 로직: `privacy_crypto.js` 및 전체 코드베이스에 **존재하지 않음**. PHP 서버 측 `makeStudentHash()`로 동일 기능 수행 (kiosk.php L83-91에서 서버 측 해시 계산 후 렌더링).
  - 체크 포인트(시트 열 인덱스 A=0, B=1 올바른 사용): A(index 0)에서 이름 읽기 정상 ✅

---

## 8. 향후 검토 과제

현재 코드베이스에서 발견되었으나 아직 결론을 내리지 않은 문제다. 해당 과제를 처리할 때 아래 항목을 함께 검토한다.

### 8-1. `app/grading/` vs `app/includes/grading/objective.php` 중복 정리 ✅ 완료 (2026-11-05)

- **현상(이전)**: 채점 관련 파일이 `app/grading/`와 `app/includes/` 양쪽에 나뉘어 있었다.
  - `app/grading/objective.php` — 하위 호환 래퍼 (실제 로직 없음, `includes/grading/objective.php`로 포워딩)
  - `app/grading/get_results.php` — 하위 호환 래퍼 (`api/grading/get_results.php`로 포워딩)
  - `app/grading/grading_review.php` — 하위 호환 프록시 (`views/grading/review.php`로 포워딩)
  - `app/grading/NanumGothic.ttf` — 중복 폰트 (실제 사용처는 `public/assets/fonts/NanumGothic.ttf`)
  - `app/includes/grading/objective.php` — 실제 채점 모듈 (464줄, Gemini API 기반 객관식 OMR 크롭 합성 및 자동 채점)
- **확인 결과**:
  - 실제 호출자는 모두 `app/includes/grading/objective.php`, `app/api/grading/get_results.php`, `app/views/grading/review.php`를 직접 참조. `app/grading/` 아래 파일을 직접 `require`/`include`하는 호출 경로 없음.
  - `app/grading/` 폴더는 과거 경로를 유지하기 위한 래퍼/프록시 모음으로만 남아 있었고, 실제 동작에는 관여하지 않음.
  - 폰트도 `public/assets/fonts/NanumGothic.ttf`로 이미 단일화되어 있었고, `includes/grading/objective.php`도 해당 경로를 우선 사용.
- **처리 결과 (2026-11-05)**:
  1. `app/grading/` 폴더 전체 삭제 (`objective.php`, `get_results.php`, `grading_review.php`, `NanumGothic.ttf`).
  2. 실제 채점 담당 파일은 `app/includes/grading/objective.php`로 확정. `PROJECT_GUIDE_SSOT.md` §4 I/O 일람표와 일치함.
  3. `NanumGothic.ttf`는 `public/assets/fonts/`로 이미 존재했으므로 추가 이동 불필요.
  4. `app/grading/` 폴더 자체가 제거되어 폴더 용도 불일치도 해소.
- **문서 대응**: `PROJECT_GUIDE_SSOT.md` §2 레거시 잔재 행 갱신, 본 절 완료 처리.

### 8-2. `.v1104/` 폴더 정리 (선택)

- `.upload_ignore`에 업로드 제외로 등록되어 있으나, 폴더 내 내용(뷰 백업, 템플릿 테스트 이미지, 크롭 이미지 등)이 현재 코드베이스와 어떤 관계인지 문서화되어 있지 않다.
- 정리 시 우선순위: 내용 확인 → 현재 `app/`에 동일 파일 존재 여부 확인 → 불필요 시 삭제, 백업이 필요하면 `dev/backup/`로 이동 후 문서화.

### 8-3. PIN brute-force 방어 추가 (보안 우선 과제) ✅ 완료 (2026-11-07)

- **현상(과거):** 학생 PIN(4자리 숫자) 로그인 진입점 두 곳(`api/auth/verify_student_pin.php`, `views/student/login.php`) 모두 로그인 시도 횟수 제한, 계정 잠금 등 brute-force 방어 수단이 없었다.
- **현재 상태: 최소 방어 이미 구현됨**
  - DB 스키마: `students` 테이블에 `login_fail_count int NOT NULL DEFAULT 0`, `locked_until datetime DEFAULT NULL` 컬럼 존재 (현행 스키마에 포함, 구 `app/db 스키마.sql`·`dev/db_clean_reset.sql` 참조 파일은 2026-11-07 삭제됨).
  - `api/auth/verify_student_pin.php` L61–76: PIN 불일치 시 `login_fail_count` +1, 5회 도달 시 `locked_until`을 현재 시각 + 30분으로 설정. 잠금 상태이면 L57–59에서 사전 차단.
  - `views/student/login.php` L68–78: 동일 패턴 — PIN 불일치 시 `login_fail_count` 증가, 5회 이상 시 30분 잠금. PIN 성공/LOCK 해제 시 L51–52에서 `login_fail_count = 0`, `locked_until = NULL` 초기화.
  - 화면에 잠금 안내 표시: `verify_student_pin.php`는 `jsonError('계정이 잠겨있습니다.')`, `login.php`는 `$error_message`로 "계정이 잠겨있습니다. 잠금 해제 후 다시 시도해주세요." 표시.
- **완료 근거:** §8-3에서 요구한 **최소 방어 — 실패 횟수 기록 + N회(5회) 초과 시 계정 잠금 + 잠금 해제 절차(성공 시 초기화)** 가 두 진입점에 모두 구현되어 있다.

**잔여 검토 항목 (미처리 — 별도 과제로 이관):**
1. **속도 제한(rate limiting):** 동일 `student_hash`/`class_id` 조합의 단위 시간당 시도 횟수 제한은 현재 없음.
2. **CAPTCHA 도입:** 자동화 스크립트 방어 목적. 학생-facing 화면 적용 시 사용성 영향 평가 필요.
3. **`students.pin` 해시 전환:** PIN이 `varchar(10)` 평문으로 저장됨 (현행 스키마상 `students.pin varchar(10) NOT NULL`). 4자리 숫자라 해시 전환만으로는 방어 효과가 제한적이나, DB 유출 대비책으로 별도 검토 가치 있음. 위 1, 2번과 병행 검토 필요.

- **우선순위:** §8-3의 최소 방어는 완료. 잔여 3개 항목은 §8-1, §8-2와 병행 검토 가능. 로그인 보안은 기능 추가보다 먼저 확보하는 것이 원칙이나, 최소 방어는 이미 확보됨.

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
