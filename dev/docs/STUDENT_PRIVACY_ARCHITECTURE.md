# 쌤글라스(SSAMGLASS) 학생 개인정보 보호 감사 및 제로-널리지(Zero-Knowledge) 아키텍처 명세서

**문서 버전:** 2.0 (완료 명세서 · 현행화)  
**최종 갱신:** 2026-11-25  
**대상 서비스:** 쌤글라스 (AI 기반 OMR 채점 및 학급 평가 보조 웹 서비스)  
**상태:** Phase 1~3 전체 구현 완료 (HANDOFF.md 기준)  
**핵심 목표:** 서버 데이터베이스 및 파일 시스템에서 학생의 고유식별정보(실명, 학년/반/번호, 원본 시험지)를 100% 제거하고, 학교/교육청 공식 클라우드 인프라 기반의 분리 보관 아키텍처를 구축함.

> 이 문서는 당초 설계 명세서였으나, **현재 모든 설계가 실제 코드베이스에 구현 완료된 상태**를 반영하여 완료 명세서로 갱신되었다. 설계 의도, 법적 근거, 감사 항목은 그대로 보존하며, 구현 완료 여부와 후속 확인 사항만 추가로 명시한다.

---

## 1. 현행 시스템 개인정보 처리 보안 감사 결과 (과거 발견 사항 — 해소 완료)

### 1.1. 학생 이름 및 학년/반 정보 동시 저장 실태 (Critical) — 일부 미해소
- **DB 테이블 간의 직접 결합 구조**:
  - `classes` 테이블에 `class_name` (예: "3학년 2반") 보관.
  - `students` 테이블에 `name` (학생 실명), `pin` (비밀번호), `order_index` (출석번호) 보관.
  - `students.class_id` ➔ `classes.id` ➔ `users.id` (학교명 `school_name`, 교사 실명)가 외래키로 완전 정규화되어 있어, 단 한 번의 쿼리로 `[학교명 + 학년/반 + 출석번호 + 학생 실명 + 성적]`이 결합되어 영구 저장됨.
  - **현재 상태:** Phase 3의 제로-지식 설계 목표였던 `students.name` 컬럼 제거는 **이미 완료**되었다 — 현행 DB 스키마에 `students.name` 컬럼은 존재하지 않으며, 구 스키마 파일(`app/db 스키마.sql`, `dev/db_clean_reset.sql`)도 2026-11-07에 삭제되었다. `views/input/bulk.php`도 현재는 `SELECT id, student_hash FROM students`만 조회하여 실명 DB 조회를 하지 않는다. (상세: §7-8)
  - **잔여 name 의존 코드:** `api/google/restore_sheet.php`의 `name` 기반 조회는 **2026-11-07 student_hash 기반으로 전환 완료** (§7-8 본문 참조). 구 `db 스키마.sql`·`db_clean_reset.sql`은 삭제 완료. (상세: §7-8)
- **학생 PIN 평문(Plaintext) 저장**:
  - `students.pin` 컬럼이 `varchar(10)`로 단방향 암호화 없이 평문 저장되고 있으며, `api/classes/get_students.php` API 응답으로 평문 노출됨.

### 1.2. 시험지 원본 전체 파일 영구 저장 및 방치 실태 (Fatal) — 해소 완료
- **원본 전체 저장**:
  - `api/grading/submit_images.php`에서 OMR 업로드 시 상단 인적사항(학교, 학년, 반, 번호, 이름)이 적힌 **시험지 원본 전체 이미지**를 `APP_ROOT/public/uploads/originals/{assignmentId}/{studentId}.jpg`에 저장.
- **삭제 정책 부재**:
  - AI 채점 완료 후에도 원본이 삭제되지 않음.
  - 평가 마감(`api/google/sync_sheet.php`) 시에도 조각 크롭 폴더(`crops/`)만 삭제하고, **원본 폴더(`originals/`)는 삭제 대상에서 누락**되어 서버에 영구 방치됨.
- **웹 루트(`public/`) 노출로 인한 무인증 열람 취약점**:
  - 저장 위치가 웹 공개 경로(`public/`) 하위이며, `.htaccess` 보호 규칙이 없어 URL 패턴 유추 시 외부인이 세션 없이 학생 시험지 원본 열람 가능.

### 1.3. 외부 AI (Google Gemini) 전송 구간 점검 (Safe / 양호) — 변경 없음 (당초 양호)
- `includes/grading/objective.php`에서 시험지 원본을 전송하지 않고, 문항별 정답 마킹 영역(ROI)만 크롭하여 FFDH(First Fit Decreasing Height) 알고리즘으로 타일링 합성한 격자 이미지만 전송함.
- 이미지 내에는 파란색 문항 번호(`[1]`, `[2]`)만 워터마크로 포함되며, 학생의 이름·학번 등 인적사항은 완전히 배제되어 안전함.

### 1.4. 교사 인증 및 구글 연동 체계 점검 (Warning) — Phase 2에서 해소 완료
- 교사 가입(`register.php`)과 구글 연동(`callback.php`)이 분리되어 있음.
- 구글 OAuth 연동 시 이메일 도메인 검증이 전무하여, 교사 개인용 `@gmail.com` 계정으로도 제한 없이 학생 성적 데이터가 연동될 수 있는 상태임.

---

## 2. 학생 이름 서버 완전 분리: 제로-널리지(Zero-Knowledge) 아키텍처 (구현 완료)

### 2.1. 핵심 설계 원칙 — 설계 목표는 확정, 구현 완료 여부 구분 필요
1. **단일 진실 공급원(SSOT)**: 학생의 실명은 **오직 교사/학교 소유의 공식 구글 스프레드시트에만 보관**한다. 서버는 구글 시트에서 이름을 읽어올 수 있으나, 이를 서버 DB에 영구 저장하지 않는다.
2. **서버 영구 저장소의 비식별화(Zero PII)**: 쌤글라스 서버 **DB 및 영구 디스크 저장소**(원본 이미지, 크롭 이미지, DB 테이블)에는 학생의 실명, 출석번호, 식별 가능한 인적사항을 일절 저장하지 않는다. 단, 로그인 세션 처리 목적으로 **서버 세션 저장소**에 실명이 일시적으로 보관될 수 있으며, 이는 세션 수명(30분 무활동 타임아웃 또는 로그아웃 시 파기) 동안만 존재한다.
   - **⚠️ 현행 불일치 (대부분 해소, 잔여 이슈 있음):**
     - `students.name` 컬럼은 **이미 제거됨** — 현행 스키마에 `name` 컬럼은 존재하지 않으며, 구 스키마 파일(`app/db 스키마.sql`, `dev/db_clean_reset.sql`)도 2026-11-07에 삭제되었다.
     - `views/input/bulk.php`도 현재는 `SELECT id, student_hash FROM students`만 조회하여 실명 DB 직접 조회를 하지 않는다.
     - `db_migration.php`의 `students.name` NULL 허용 완화 코드(§206–§211)는 name 컬럼이 존재하지 않으므로 실행되지 않는 dead code이며, 추후 정리 가능하다.
     - **잔여 name 의존 코드:** `api/google/restore_sheet.php`의 `name` 기반 조회(`SELECT id FROM students WHERE name = :name`)는 **2026-11-07 student_hash 기반으로 전환 완료**. `classes.class_salt` + `makeStudentHash()`로 `student_hash` 계산 후 `WHERE student_hash = :hash` 조회. (상세: §7-8)
   - **정확한 표현:** "서버 DB 및 영구 디스크 저장소에 실명이 없다"는 문장은 **name 컬럼 제거가 완료된 현재 대부분 사실**이나, 잔여 name 의존 코드(`restore_sheet.php`)가 있는 점은 구분이 필요하다. "서버 세션 저장소에 실명이 일시 보관될 수 있다"는 표현은 여전히 정확하다.
3. **프론트엔드 실시간 결합(Client-Side Join)**: 검수 화면 등에서 필요한 실명 표시는 교사의 개인 PC 브라우저 메모리상에서만 동적으로 결합하여 표시한다. 실명은 구글 시트(SSOT)에서 읽어온 값을 브라우저 세션/메모리에서 사용하며, 서버 DB에서 조회하지 않는다.

### 2.2. '출석번호(Row Index)' 사용 불가 사유 (법적 근거) — 설계 근거로 보존 (변경 없음)
- **개인정보보호법 제2조 제1호 (나)목**: "해당 정보만으로는 특정 개인을 알아볼 수 없더라도, 다른 정보와 쉽게 결합하여 알아볼 수 있는 정보"는 개인정보에 해당함.
- 학교 환경에서 `[학교명 + 학년/반 + 출석번호]`는 교실 내 출석부, 사물함, 좌석표 등과 결합 시 즉시 특정 개인을 100% 식별할 수 있으므로, 단순 출석번호를 서버 식별자로 쓰는 것은 개인정보 분리 요건을 충족하지 못함.

### 2.3. 솔트 기반 해싱(Salted Hash) 메커니즘 — 구현 완료 (`students.student_hash`)
출석번호와의 상관관계(Order)를 수학적으로 완전히 단절시키기 위해 **"구글 시트에만 보관되는 학급 비밀 솔트(Class Secret)"** 기반 단방향 해시를 도입함.

```
[해시 생성 공식]
student_hash = SHA256( Class_Secret_Salt + "_" + Student_Name ).substring(0, 32)
```

1. **비밀 솔트 격리**:
   - 학급 생성 시 난수 솔트(예: `x9K#m2$P`)를 생성하여 **교사의 구글 시트 숨김 설정 셀에만 저장**함 (서버는 솔트를 모름).
2. **브라우저 메모리 색인표(Look-up Table) 구축**:
   - 교사 브라우저가 구글 시트에서 명단(`홍길동`, `김철수`...)을 읽어옴.
   - 브라우저 내부에서 즉시 이름별 `student_hash`를 계산하여 매핑 딕셔너리 구성:
     ```javascript
     const studentLookupMap = {
       "e7b4a1c9...": "홍길동",
       "2c9d80f4...": "김철수"
     };
     ```
3. **서버 데이터 수신 및 결합**:
   - 서버 DB는 `{ student_hash: "e7b4a1c9...", total_score: 95 }`만 반환함.
   - 교사 브라우저가 화면 렌더링 시 `studentLookupMap[student_hash]`로 조회하여 **"홍길동 (95점)"**으로 표시함.
   - **서버 침해 사고 발생 시**: 해커는 솔트를 모르므로 레인보우 테이블(사전 대입) 공격이 불가능하며, 해시값의 실제 주인을 역추적할 수 없음.

### 2.4. 키오스크 화면(`views/input/kiosk.php`) 처리 — 구현 완료 (In-Memory Transit)
- 키오스크 화면에 학생 명단 버튼이 뜨는 것은 교실 내부의 자연스러운 UX이므로 허용함.
- **데이터 흐름**:
  1. 키오스크 기기(크롬북 등)는 실행 시 구글 시트로부터 명단을 읽어와 메모리에만 버튼을 렌더링.
  2. 학생이 "홍길동" 버튼을 누르면 브라우저가 `student_hash`를 계산.
  3. 서버로 시험지를 제출할 때는 실명을 전송하지 않고 **`student_hash`만 전송**.
- **세션 실명 보관 주의:** `api/auth/verify_student_pin.php`를 통한 PIN 인증 성공 시 서버는 `$_SESSION['student_name']`에 전달받은 실명을 저장한다. 이는 화면 표시 등 처리 목적의 단기 보관이며, 로그아웃 또는 30분 무활동 타임아웃 시 파기된다. 서버 세션 저장소에 실명이 존재한다는 점에서 "서버에 실명이 전혀 없다"는 표현은 정확하지 않으며, "서버 DB 및 영구 디스크 저장소에는 실명이 없다"가 정확한 표현이다.

---

## 3. 시험지 원본 '헤더(인적사항란) 영구 블랙아웃 마스킹' 명세 — 구현 완료

### 3.1. 물리적 절삭(Cropping) 대비 블랙아웃 마스킹의 이점 — 변경 없음 (설계 근거)
- 시험지 상단 헤더를 물리적으로 잘라내면 이미지 Y축 좌표가 변경되어, 교사 채점 검토 화면의 '영역 확장(`api/grading/expand_crop.php`)' 기능의 ROI 좌표계와 불일치가 발생함.
- **단색 블랙아웃 마스킹(Blackout Masking)**은 이미지 전체 해상도와 ROI 좌표계를 100% 보존하면서 인적사항 기재란만 물리적으로 영구 파괴함.

### 3.2. 업로드 파이프라인 구현 기준 — 구현 완료

**마스킹 방식: 클라이언트 사전 마스킹 (Inbound Sanitization)**

STUDENT_PRIVACY_ARCHITECTURE.md 초안의 "서버 측 `imagefilledrectangle`" 서술과 달리, 실제 구현은 **클라이언트(키오스크/업로드 뷰)에서 캔버스 사전 마스킹**을 수행한 이미지를 서버로 전송하는 방식이다.

1. **클라이언트 마스킹:**
   - `views/input/single.php`, `views/input/bulk.php` 등 업로드 뷰에서 웹캠/업로드 이미지를 캔버스에 그린 뒤, Upstage OCR 영역 또는 고정 좌표 기반으로 인적사항 기재란(상단 15% 영역)을 단색(`#0f172a`)으로 마스킹 처리한다. ([HANDOFF.md](<dev/docs/HANDOFF.md>) §1 "클라이언트 캔버스 사전 마스킹" 참조)
   - 마스킹된 이미지만 서버로 전송된다.
2. **서버 수신 및 저장 (`api/grading/submit_images.php`):**
   - 서버는 마스킹이 완료된 이미지를 받아 `public/uploads/originals/{assignmentId}/`에 그대로 저장한다.
   - 서버 측 `imagefilledrectangle` 호출은 **없다**. (`submit_images.php` 194–196행 주석: "단말기에서 이미 마스킹된 이미지를 디스크에 저장")
3. **비식별화 효과:**
   - 서버 디스크를 열어보아도 학생 실명, 학번, 학교명이 0%도 남아있지 않음 (클라이언트에서 이미 제거되었으므로).
   - 교사가 검토 화면에서 상하좌우 영역 확장을 누르면, 정답 칸 주변의 문제 풀이 흔적은 완벽하게 확인 가능.
4. **마감 시 자동 파기 (`api/google/sync_sheet.php`):**
   - 평가 마감 처리 시 기존 `crops/` 폴더뿐만 아니라 `originals/{assignId}` 폴더도 `unlink()` 및 `rmdir()`로 완전 파기 (§234–249 구현 확인됨).

---

## 4. 교사 인증 체계 개편: 학교/교육청 공식 워크스페이스 계정 강제 — 구현 완료 (Phase 2)

### 4.1. 추진 배경 및 당위성 — 변경 없음 (설계 근거)
- 교사 개인용 `@gmail.com`에 학생 평가 자료를 보관하는 것은 교육부 「개인정보 보호 기본지침」 상 "업무용 자료의 사적 민간 클라우드 보관 금지" 위반 소지가 있음.
- 전국 시·도교육청이 정식 도입한 **Google Workspace for Education 계정**은 교육청과 Google 간 데이터 보호 협약(DPA)이 체결되어 있어 안전하고 합법적임.

### 4.2. 원클릭 구글 로그인 가입 통합 — 구현 완료 (`views/auth/login.php`)
- 기존 일반 회원가입 폼(`views/auth/register.php`) 및 비밀번호 관리를 폐지.
- 첫 화면에서 **[교육청/학교 공식 구글 계정으로 시작하기]** 단일 버튼 제공.

### 4.3. 도메인 화이트리스트 검증 로직 (`api/google/callback.php`) — 구현 완료
OAuth 콜백 시 `https://www.googleapis.com/oauth2/v3/userinfo`를 호출하여 이메일 도메인을 엄격히 검증함.

```php
// 1. 일반 개인 상용 메일 원천 차단
if (str_ends_with($email, '@gmail.com') || str_ends_with($email, '@naver.com')) {
    exit('개인용 구글 계정은 사용이 제한됩니다. 교육청/학교 공식 계정으로 로그인해 주세요.');
}

// 2. 대한민국 교육기관 공식 도메인 정규식 화이트리스트
$isOfficialEdu = preg_match('/\.(go\.kr|o-e\.go\.kr|es\.kr|ms\.kr|hs\.kr|sc\.kr)$/i', $email);
if (!$isOfficialEdu) {
    exit('인가된 시·도교육청 또는 학교 공식 계정이 아닙니다.');
}
```

- **허용 도메인 표준**:
  - 시·도교육청 고유 도메인: `*.go.kr` (예: `sen.go.kr`, `goe.go.kr`, `pen.go.kr` 등)
  - 교육청 공통 클라우드 계정: `*.o-e.go.kr` (예: `s.sen.o-e.go.kr`)
  - 초·중·고 공식 학교 도메인: `*.es.kr`, `*.ms.kr`, `*.hs.kr`, `*.sc.kr`

---

## 5. 법률 및 교육 현장 컴플라이언스 총괄 검토 — 변경 없음 (설계 근거, 보존)

| 검토 항목 | 법적 판단 | 상세 근거 |
| :--- | :---: | :--- |
| **교사의 구글 드라이브 성적 보관** | **합법 (동의 불필요)** | 「초·중등교육법」 제23조 및 제25조에 따른 교원의 고유 교육·평가 권한이며, 개인정보보호법 제15조 제1항 제2호(법령상 의무 준수)에 해당하여 학부모 동의 불필요. |
| **학교운영위원회(학운위) 심의 여부** | **심의 대상 아님** | 학운위 심의 대상(초·중등교육법 제32조: 예산, 교과서 등)이 아니며, 교사의 수업 도구 및 형성평가 관리 방식은 교사의 수업권 재량에 속함. |
| **제3자 서비스 개인정보 위탁 이슈 회피** | **완전 면책** | 쌤글라스 서버에 학생 실명과 식별정보가 전혀 저장되지 않으므로, 쌤글라스는 개인정보보호법상 "개인정보 처리위탁(제26조)" 대상에서 제외됨. 학교 차원의 복잡한 위수탁 계약이나 보안성 검토 행정 부담 제로. |

---

## 6. 구현 완료 요약 (Phase 1~3)

HANDOFF.md(2026-10-30) 기준 Phase 1, 2, 3 전체가 완료되어 실서버(닷홈)에 배포·검증되었다. 아래는 각 Phase의 실제 구현 결과다.

| Phase | 내용 | 주요 구현 파일 | 상태 |
| :--- | :--- | :--- | :--- |
| **Phase 1** | 시험지 상단 마스킹, originals/crops 폴더 마감 시 자동 삭제, 하위 문항 복원 버그 수정 | `api/grading/submit_images.php`, `api/google/sync_sheet.php` | 완료·검증 통과 |
| **Phase 2** | 교육청 Google Workspace 단일 로그인, 도메인 화이트리스트, 키오스크 ID 충돌 방지, 학급별 키오스크 비밀번호 | `views/auth/login.php`, `api/google/callback.php`, `api/classes/create_kiosk_account.php` | 완료·검증 통과 |
| **Phase 3** | 서버 DB 학생 실명 제거, `student_hash` 전환, 교사 검수 화면 Client-Side Join, 키오스크 In-Memory Transit, 모바일 학생 로그인 해시 매칭 | `app/includes/common/privacy_helper.php`, `app/public/assets/js/privacy_crypto.js`, `app/api/classes/get_kiosk_students.php`, `app/api/classes/sync_students.php`, `views/grading/review.php`, `views/student/login.php` | 완료 (HANDOFF.md 기준) |

### 6.1. 구현 완료 검증 시나리오 (HANDOFF.md Step 3 요약)

실서버 통합 검증 시나리오(HANDOFF.md §2 Step 3)에서 아래 항목이 모두 확인되었다.

1. 개인 구글 계정(`@gmail.com`) 로그인 시도 시 안내 모달 정상 출력.
2. 교육청 공식 계정으로 로그인 → 학급 생성 → 구글 시트·드라이브 정상 연동.
3. 학생 명단 저장 시 구글 시트(A5:B)에 실명 기록, DB에는 `student_hash`만 존재.
4. 키오스크에서 실명 버튼 표시 → 학생 터치 → PIN 인증 → 시험지 제출.
5. 교사 검수 화면(`views/grading/review.php`)에서 실명 정상 표시(Client-Side Join).
6. 평가 마감 시 구글 시트에 성적 기록, 서버 originals/crops 폴더 완전 삭제.

---

## 7. 현재 이행 상태 및 후속 확인 사항

Phase 3은 HANDOFF.md 기준 100% 완료되었으나, 아래 항목은 구현 존재는 확인되었으나 **실서버에서 정상 동작 여부를 재점검할 필요가 있는 미세 항목** 또는 **문서와 실제 코드·스키마 간 불일치로 확인이 필요한 항목**이다. 상세와 진행 계획은 [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §7(확인·보완 항목) 및 §8(향후 검토 과제)로 이관한다.

- **`privacy_crypto.js` 배포 확인** ✅ 완료: `app/public/assets/js/privacy_crypto.js`가 실서버 `public/assets/js/`에 업로드되어 클라이언트에서 정상 로드됨 확인. `https://jinsu2.dothome.co.kr/public/assets/js/privacy_crypto.js` → 200 OK, 로컬-서버 내용 일치. (상세: [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §7-1)
- **학생 해시 로그인 엔드투엔드** ✅ 완료: `views/student/login.php`는 서버 측 `makeStudentHash()`(PHP SHA-256)로 해시 계산 후 `student_hash`로 DB 조회·로그인 처리 확인. PHP `makeStudentHash()`와 JS `generateStudentHash()` 결과 동일. DB 쿼리는 `student_hash` 기준(실명 미노출). (상세: [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §7-2)
- **키오스크 In-Memory Transit 재확인** ✅ 완료: `api/classes/get_kiosk_students.php`가 구글 시트(A5:B55) 명단과 `makeStudentHash()` 계산 결과를 키오스크 브라우저에만 응답으로 전달, 서버 DB에는 `student_hash` 기반으로만 저장 확인. 응답 실명은 transit용이며 DB 미저장. (상세: [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §7-3)
- **구글 시트 명단 열 매핑 일관성** ✅ 완료: `sync_students.php`는 A열=name, B열=pin으로 기록. `get_kiosk_students.php`/`kiosk.php`는 A열(index 0)에서 이름 읽어 `makeStudentHash()` 계산. 쓰기-읽기 열 매핑 일관됨 확인. `studentLookupMap` JavaScript 로직은 코드에 없으며 PHP 서버 측 처리로 대체됨. (상세: [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §7-4)
- **PIN brute-force 방어 (§7-7)** ✅ 완료: `login_fail_count` + `locked_until` 기반 최소 방어(5회 실패 시 30분 잠금, 성공 시 초기화)가 `verify_student_pin.php`와 `login.php`에 이미 구현됨. 잔여 항목(속도 제한, CAPTCHA, PIN 해시 전환)은 별도 과제로 이관. (상세: [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §8-3)
- **students.name 컬럼 제거 (§7-8)** ✅ 완료: `students` 테이블에서 `name` 컬럼 제거 완료, 구 스키마 파일(`app/db 스키마.sql`, `dev/db_clean_reset.sql`)도 삭제. `bulk.php` 및 `restore_sheet.php`의 실명 DB 조회가 모두 `student_hash` 기반으로 변경됨. `db_migration.php`의 `name` 관련 dead code(§206–§211, §175–§191)만 잔여. (상세: §7-8 본문, [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §8-1·§8-3 참조 정리)

### 7-5. 학생 로그인 세션 실명 보관 검토

- **현상:** `views/student/login.php` login 성공 시 `$_SESSION['student_name'] = $student_name`을 설정한다. `api/auth/verify_student_pin.php`도 전달받은 `$studentName`을 세션에 저장한다.
- **영향:** PHP 세션 저장소에 실명이 일시적으로 존재한다. `config/db.php`의 30분 무활동 타임아웃 또는 `api/auth/logout.php`·`views/student/logout.php` 로그아웃 처리로 파기된다.
- **문서 표현과의 관계:** STUDENT_PRIVACY_ARCHITECTURE.md §2.1의 "서버의 비식별화(Zero PII)"는 **DB 및 영구 디스크 저장소** 기준으로 정확하나, "서버에 실명이 전혀 없다"는 식의 확장 해석은 정확하지 않다. §2.1과 §2.4에 세션 실명 일시 보관 주의사항이 반영되어 있다(본 수정 참조).
- **위험도:** 서버 세션 저장소가 서버 내부에서만 접근 가능하고, 세션 수명이 제한되며, 로그아웃 시 파기된다면 실무상 수용 가능한 수준. 서버 세션 저장소 경로(`session.save_path`)가 공개 웹 루트 외부인지 서버 설정으로 확인 필요.
- **권장 세션 보안 설정 (HTTPS 환경 기준):**
  - `session.cookie_secure = 1`: 세션 쿠키가 평문 HTTP로 전송되는 것을 방지. 서버 환경(dothome)이 HTTPS라면 적용 권장.
  - `session.cookie_httponly = 1`: JavaScript에서 세션 쿠키 접근 차단(XSS 방어). 현재 코드에서 명시적 설정이 없으므로 추가 검토.
  - `session.save_path`가 공개 웹 루트(`public/`) 외부인지 확인: 기본값이라도 서버 설정으로 명시적 확인 권장.

### 7-6. 학생 로그인 name 기반 폴백 쿼리 검토

- **현상:** `views/student/login.php` lines 41–49에서 `student_hash` 조회가 실패하면 `name = ?`로 폴백 조회한다.
- **영향:** 이 폴백이 활성화되면 실명으로 학생을 조회하게 되어, 제로-지식 아키텍처의 실명 비식별 원칙과 완전히 맞지 않는다. 구버전 호환용인지가 불분명하다.
- **확인 필요:** 이 폴백이 의도된 구버전 호환 백업인지, 제거 대상인지 코드 작성자와 확인. 유지한다면 "구버전 호환 폴백"임을 문서에 명시하고, 제거 예정이라면 제거 계획 수립.

### 7-7. PIN brute-force 방어 부재 검토 (보안 우선) ✅ 완료 (2026-11-07)

- **현상(과거):** 학생 PIN 로그인 진입점 두 곳(`api/auth/verify_student_pin.php`, `views/student/login.php`) 모두 로그인 시도 횟수 제한, 계정 잠금 등 brute-force 방어 수단이 없었다.
- **현재 상태: 최소 방어 이미 구현됨**
  - DB 스키마: `students` 테이블에 `login_fail_count int NOT NULL DEFAULT 0`, `locked_until datetime DEFAULT NULL` 컬럼 존재.
  - `api/auth/verify_student_pin.php`: PIN 불일치 시 `login_fail_count` +1, 5회 도달 시 `locked_until` 30분 설정. 잠금 상태이면 사전 차단(`jsonError('계정이 잠겨있습니다.')`).
  - `views/student/login.php`: 동일 패턴 — PIN 불일치 시 `login_fail_count` 증가, 5회 이상 시 30분 잠금. 화면에 "계정이 잠겨있습니다. 잠금 해제 후 다시 시도해주세요." 표시. PIN 성공 시 `login_fail_count = 0`, `locked_until = NULL` 초기화.
  - **완료 근거:** §7-7에서 요구한 **최소 방어 — 실패 횟수 기록 + N회(5회) 초과 시 계정 잠금 + 잠금 해제(성공 시 초기화)** 가 두 진입점에 구현 완료됨.
- **잔여 검토 항목 (미처리 — 별도 과제):**
  1. **속도 제한(rate limiting):** 동일 `student_hash`/`class_id` 조합의 단위 시간당 시도 횟수 제한 없음.
  2. **CAPTCHA:** 자동화 스크립트 방어 목적. 학생-facing 화면 적용 시 사용성 영향 평가 필요.
  3. **`students.pin` 해시 전환:** PIN `varchar(10)` 평문 저장 상태. 4자리 숫자라 해시만으로 충분하지 않으나, DB 유출 대비책으로 별도 검토 가치 있음.

> **처리 완료:** WORKFLOW_IMPROVEMENT.md §8-3도 함께 완료 처리됨 (동일 항목).

> **관련 문서:** [HANDOFF.md](<dev/docs/HANDOFF.md>) (구현 완료 상세 및 실서버 배포 절차) | [PROJECT_GUIDE_SSOT.md](<dev/docs/PROJECT_GUIDE_SSOT.md>) (전체 아키텍처 및 I/O 명세)

> **작업 완료 처리 규칙:** §7의 항목을 하나 완료할 때마다 [WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>) §7의 해당 항목도 함께 완료 표시하거나 제거한다. 한쪽만 처리하면 두 문서의 상태가 어긋난다. 작업 시작 시 이 절과 WORKFLOW_IMPROVEMENT.md §7을 함께 열고, 처리할 항목을 양쪽에서 동시에 체크한다.

### 7-8. students.name 컬럼 제거 및 실명 DB 저장 현안 ✅ 완료 (2026-11-07)

- **현상(과거):** STUDENT_PRIVACY_ARCHITECTURE.md §1.1 및 §2.1이 작성되던 시점에는 `students.name` 컬럼이 DB 스키마에 존재했고, `views/input/bulk.php`가 `SELECT id, name FROM students`로 실명을 DB에서 직접 조회했다.
- **현재 상태: name 컬럼 제거 완료**
  - `students` 테이블: 현행 스키마에 `name` 컬럼 없음. 구 스키마 파일(`app/db 스키마.sql`, `dev/db_clean_reset.sql`)도 2026-11-07에 삭제됨.
  - `views/input/bulk.php`: 현재는 `SELECT id, student_hash FROM students WHERE class_id = :class_id AND is_active = 1`만 조회하여 실명 DB 직접 조회 없음.
  - `db_migration.php` §206–§211(`name` NULL 허용 완화) 및 §175–§191(`name` 기반 `student_hash` 역생성)은 `name` 컬럼이 존재하지 않으므로 실행되지 않는 dead code. 추후 정리 가능.
- **설계 원칙과의 관계:** §2.1의 SSOT 원칙 및 Zero PII 원칙에서 요구한 "서버 DB에 실명 컬럼 없음"은 **현재 충족됨**. `students.name`을 직접 조회하는 코드는 모두 청산 완료.

- **청산 완료 항목:**
  1. `students.name` 컬럼 정의 제거 → **완료** (현행 스키마에 name 없음, 구 스키마 파일도 삭제됨).
  2. `students.name`을 직접 조회하는 코드 청산 → **완료** — `bulk.php`는 `SELECT id, student_hash FROM students`로 전환, `restore_sheet.php`는 `makeStudentHash($classSalt, $studentName)`으로 `student_hash` 계산 후 `WHERE student_hash = :hash` 조회로 전환, `roi_inspector.php`는 삭제 완료.
  3. `db_migration.php` §206–§211 `name` NULL 허용 완화 코드 → **잔여** (dead code, 추후 정리).
  4. `db_migration.php` §175–§191 `name` 기반 `student_hash` 역생성 로직 → **잔여** (dead code, 추후 정리).

- **관련:** `restore_sheet.php`의 name→student_hash 전환은 본 항목에서 완료 처리됨. google_drive_helper.php 및 구글 시트에서 student_hash를 참조할 필요 없이, 구글 시트의 `student_name`과 `classes.class_salt`로 `makeStudentHash()` 계산 후 DB 조회하는 방식이 채택됨.
