# 템플릿 인식·저장 로직 디버깅 계획

> **대상:** 쌤글라스 템플릿 등록·정답표 파싱·에디터 저장 흐름에서 발생한 여러 오류
> **작성 시점:** 2026-11-05
> **상태:** 2026-11-06 디버그 스크립트 작성·서버 Upstage 파싱 실행 완료, 정답표 파싱·문제지 ROI·바인딩 단계 엔진 정상 동작 확인. 실제 서비스 흐름(view→API→save) 오류 지점 좁혀진 상태
> **참고 메모(품질 문제, 2026-11-06):** 작동 crash보다는 **인식 결과의 유형 분류가 기대와 어긋나는 문제**를 해결하고자 함. 검수화면에서 observed된 유형 흔들림 예시:
>   - 12번: 선잇기(matching_line) 문항이 객관식으로 처리됨
>   - 14번: 단답형 소문항 문항이 주관식으로 처리됨 + 8·10번처럼 소문항이 처음부터 묶여 있어야 하는데 14번만 소문항이 분리됨
>   - 18번: 단답형 문항으로 인식됐는데 검수화면에선 객관식으로 표시됨
>   ⇒ 유형 문제는 정답표 파싱/바인딩 자체보다는 **문제지 ROI의 유형 분류**, **정답표 bind 단계에서의 type/sub_type 처리**, 또는 **저장 후 editor.php 렌더가 유형을 표시하는 방식** 중 어딘가에서 차이가 났을 가능성이 높음.
> **핵심 파일:**
> - `views/templates/upload.php`
> - `includes/common/answer_key_parser.php`
> - `views/templates/editor.php` → `api/templates/save.php`
> - `includes/common/gemini_matching.php`
> - 보조: `includes/common/upstage.php`, `api/templates/auto_extract.php`, `api/templates/parse_answer_key.php`, `api/templates/analyze_matching.php`

---

## 1. 현재까지 확인한 내용

### 1.1 핵심 흐름 (3갈래)

템플릿 등록·저장 흐름은 크게 다음 세 갈래로 나뉩니다.

**1) 템플릿 등록·업로드 (`views/templates/upload.php`)**
- 프론트 `submitTemplate()`이 `title`, `page_count`, `is_ajax`, 페이지 이미지들(`page_file_0`...), `pages_meta`를 POST
- `ai_auto_extract=1`이고 원본 문제지 PDF(`original_pdf`)가 있으면 `original_pdf` + `page_glyphs`(PDF.js 글리프 좌표)를 함께 전송하고, 서버에서 `parseDocumentWithUpstage` → `extractRoisFromUpstageData` → `detectStudentInfoMaskRois` 실행
- 정답표 파일(`answer_key_file`) 또는 텍스트(`answer_key_text`)가 함께 오면 `parseAnswerKeyPdf` / `parseAnswerKeyText`로 파싱
- 파싱된 정답을 `bindAnswerKeyToRois`로 추출된 ROI에 바인딩한 뒤 DB 저장
- 저장 후 `editor.php?id=...&ai=1`로 이동
- **재현 방식:** 프론트 없이 `app/config/env.php` → `app/config/db.php` 순으로 loaded 한 PHP CLI 스크립트에서 실제 첨부 PDF로 동일 엔진을 호출해 중간 결과를 파일로 남길 수 있음(Upstage/Gemini 인증정보는 `.env`에서 로딩)

**2) 정답표 파싱·바인딩 (`includes/common/answer_key_parser.php`)**
- Upstage elements에서 table HTML을 먼저 찾고, 없으면 전체 텍스트 폴백
- HTML 표를 2D 그리드로 전개(`rowspan`/`colspan` 처리) → 열 헤더 탐색 → 문항 번호·정답·배점·채점기준 추출
- `normalizeAnswerEntry`에서 다음 유형 분류:
  - 원문자(①~⑳)
  - 기호형(㉠~㉭)
  - 소문항 패턴(`(1) ○○ (2) ○○`, `㉠ 바다 ㉡ 육지`)
  - 선잇기 쌍(`(1)-㉢`)
- `bindAnswerKeyToRois`에서 ROI와 정답을 `q_num` 기준으로 묶고, 선잇기는 복수 `sub_item` 통합을 시도

**3) 에디터 저장 (`views/templates/editor.php` → `api/templates/save.php`)**
- 프론트 `saveTemplate()`이 `rois`, `pages`, `privacy_mask_data`를 JSON으로 POST
- 서버 `save.php`에서 정규화(페이지·`type`·`answer`·`score`·`matching_direction` 등 기본값 보강) 후 `templates` 테이블 UPDATE

**4) 선잇기 자동 분석 (`includes/common/gemini_matching.php`)**
- 선잇기 유형 ROI가 있으면 문항 영역을 크롭해 Gemini Vision에 보내고, 방향·기준점(dot)·`ans_roi`·`pairs`를 받아 반영
- `autoAnalyzeMatchingRoisServerSide`는 서버 측 자동 추출/정답표 바인딩 후에도 호출됨

### 1.2 함께 읽은 보조 파일

- `includes/common/upstage.php` — Upstage Document Parse 호출 및 ROI 추출(방안 A)
- `api/templates/auto_extract.php` — PDF 업로드 후 Upstage 파싱 → ROI·개인정보 마스킹 추출 → DB 반영
- `api/templates/parse_answer_key.php` — 정답표 파일/텍스트 수신 후 파싱·바인딩
- `api/templates/analyze_matching.php` — 프론트가 크롭 이미지를 넘겨 선잇기 분석
- `dev/docs/PROJECT_GUIDE_SSOT.md` — 전체 아키텍처·입출력 규약

---

## 2. 지금 부족한 정보

"꽤 많은 오류"라고 하셨고, 다음 정황이 확인되었습니다.

### 2.1 진행 정황 (확인됨)

- 문제지 PDF와 정답지 PDF를 첨부하고 **'평가지 등록 및 ROI 지정 이동'** 버튼을 눌렀을 때 여러 오류가 표시됨
- 첨부 파일:
  - 문제지: `3-2_과학_2단원_1회_문제.pdf`
  - 정답지: `3-2_과학_2단원_1회_정답.pdf`
- 원본 첨부 파일은 숨김 폴더 `.solar-work/attachments/` 안에 있음

### 2.2 오류 종류 (아직 수집 중)

- 화면 오류 메시지(팝업/토스트/콘솔)
- 서버 응답 JSON
- DB에 잘못 저장된 값
- 정답이 안 붙거나 배점이 이상하거나 ROI 위치가 틀리는 등 구체적 증상

### 2.3 두드러지는 문항/페이지 (아직 수집 중)

- 특정 번호 문항만 인식이 누락됐는지
- 선잇기 문항 쌍이 뒤집혔는지
- 소문항 정답이 옆 문항에 붙었는지

### 2.4 가능하면 콘솔/network 로그

- 브라우저 콘솔에 예외 스택이 남았는지
- `parse_answer_key.php` / `auto_extract.php` / `save.php` 응답에 어떤 `error`가 왔는지

**현재 조치:** 재현 정보가 완전히 모이기 전에도 실제 엔진 재현을 먼저 진행할 수 있어, PHP CLI 기반 디버그 스크립트로 정답표 파싱·문제지 OCR·바인딩 중간 결과를 확인하는 방식으로 넘어감.

### 2.5 재현 시도 경과 (2026-11-05)

- 로컬 PHP CLI(8.5.12)는 php.ini가 로드되지 않고 curl 확장이 비활성이라 `CURLFile` 클래스가 없음
  - 이로 인해 `parseAnswerKeyPdf()`와 `parseDocumentWithUpstage()`가 Upstage API 호출 직전 단계에서 `Class "CURLFile" not found`로 실패
  - 재현 결과가 0문항으로 나온 직접 원인
- 로컬만으로는 Upstage 호출까지 재현하기 어려워, 닷홈 서버에 임시 디버그 폴더를 올리고 실제 엔진으로 실행하기로 결정
- 서버에 올릴 파일: `temp_debug_run.php`, 문제지 PDF, 정답지 PDF, `config/env.php`, `config/db.php`
- FTP 접속: Python `ftplib` + `getpass` 방식, 비밀번호는 로컬 `.env`의 `DB_PASS` 사용
- 서버 업로드 완료: 3개 파일(스크립트 + PDF 2개) + config 폴더 미업로드 상태로 1차 시도 → 서버에서 `config/env.php` 없음으로 실패
- 이후 로컬에서 `temp_debug/config/`에 `env.php`, `db.php` 복사 및 FTP 스크립트 수정 완료

### 2.6 재현 시도 경과 (2026-11-06)

- 중복 첨부 폴더(`LRXhWDpC/`, `rVncr0vz/`) 삭제, `dJeld8nm`(문제지)+`JiRscJEe`(정답지) 기준 확정
- `temp_debug/.env.debug` 생성: Upstage API 키만 포함 (서버용)
- `temp_debug/config/env.php` 작성: 서버는 `app/.env`가 없으므로 `.env.debug`에서 API 키 읽도록 수정
- `temp_debug/debug_answer_key.php` 작성: `?test=both` | `?test=problemOnly` 조건으로 Upstage 파싱 → 표 HTML → 2D 그리드 → `normalizeAnswerEntry` 결과 출력
- FTP로 서버 `html/temp_debug/`에 스크립트+PDF 2종 업로드 완료
- 서버 `html/temp_debug/config/` 디렉토리 없음 확인 → 생성 및 `config/env.php` 배치
- 서버 HTML 구조로 인해 include 경로 수정: `../app/includes/common/` → `../includes/common/`
- URL 실행 (`?test=both`) 결과:
  - Upstage elements 57개 추출 성공
  - 표 HTML 305바이트: 문제지 앞표지 헤더의 작은 정보 표만 인식됨 (문제지 자체에는 정답표가 없으므로 당연)
  - **정답지 이원목적분류표는 4702바이트 표 HTML로 정상 인식, `parseAnswerKeyFromHtmlTable()`이 20개 문항 모두 정확 파싱 (parsedAnswers.json 8460바이트)**
    - 유형별 분류: 1~6,13,15,16,17,20번 objective / 7,11,19번 subjective("아래 참조") / 8번 subjective+sub_item(㉠바다㉡육지) / 9번 subjective+sub_item((1)육지의물(2)바닷물) / 10번 short_ox+sub_item(OX 3문항) / 12번 matching_line+sub_item((1)㉡(2)㉠) / 14번 subjective+sub_item(㉠밀물㉡썰물) / 18번 short_ox(단답 "가치") — 모두 정답표 형식과 일치
    - 소문항 분해: 8번 sub_answers[1=바다,2=육지], 9번 [1=육지의물,2=바닷물], 10번 [1=○,2=○,3=✕], 12번 [1=㉡,2=㉠], 14번 [1=밀물,2=썰물] — 모두 정확
    - 선잇기 12번: pairs [{from:(1),to:㉡},{from:(2),to:㉠}] 정확 구성, matching_line 유형 정확 분류
    - 그리드 전개: 2D 배열 20행×9열, rowspan=2 헤더 처리 정상, 열 매핑 num=0, answer=2, score=3, criteria=4 정확 식별
  - **핵심 발견: 정답표 파싱 엔진은 정상 동작, 오류는 문제지 OCR/ROI 추출 단계 또는 바인딩·저장 단계로 좁혀짐**
- URL 실행 (`?test=problemOnly`) 결과: 문제지 단독 실행도 동일 — 표 HTML 305바이트, parsedAnswers 0개 (문제지 자체에는 정답표가 없으므로 정상)
- **핵심 발견:** Upstage Document Parse가 정답표 PDF의 이원목적분류표를 문제없이 표로 인식함. 정답표 파싱은 완벽.
- `temp_debug/debug_problem_roi.php` 작성·업로드·실행 결과:
  - 문제지 2페이지, 20개 ROI 추출 성공
  - 문항 번호(num_roi), 답안 영역(ans_roi), 유형(objective/subjective/matching_line), 배점(각 5점) 모두 식별됨
  - 유형별 분포: objective 11개, subjective 8개, matching_line 1개 — 문제지 문항 구성과 부합
- `temp_debug/debug_binding.php` 작성·업로드·실행 결과:
  - 문제지 ROI 20개 + 정답표 파싱 20문항 바인딩 결과: 20개 ROI 전부 정답 붙음, 누락 없음
  - (콘솔 출력 시 cp949 인코딩 경고 발생했으나 결과 데이터 자체는 정상)
- **결론:** 디버그 스크립트 기준 템플릿 등록·정답표 파싱·문제지 ROI·바인딩 엔진 단계는 정상 동작. 실제 서비스 흐름(view→API→save)의 다른 지점(프론트 전송, API 응답 처리, save.php 정규화, DB 저장 후 에디터 렌더링 등)에 오류 가능성 높음.
- **주의:** 위 디버그 결과는 정답표 파싱·ROI·바인딩 엔진이 개별 호출될 때의 결과이며, **편집 화면에서 사용자가 관찰한 유형(12번 객관식화, 14번 소문항 분리/주관식 처리, 18번 객관식 표시)과 동일하다고 단정할 수 없음.** 실제 검수화면에서 보신 유형이 어디에서 결정됐는지(문제지 ROI 추출 시 type, 정답표 bind 시 type, editor.php 로드/렌더 시 type, save.php 정규화 시 type)를 좁혀야 함.

---

## 3. 디버깅 진행 계획

데이터 흐름의 어느 단계(Upstage 파싱 → 정답표 파싱 → `bind` → Gemini 매칭 → 저장)에서 무너졌는지부터 재현으로 확인합니다.

### 3.1 단계별 확인 순서

1. **정답표 파싱 단계 (우선)**
   - 실제 정답표 PDF를 `parseAnswerKeyPdf()`로 파싱
   - Upstage elements → 표 HTML → 2D 그리드 전개 → 열 헤더 탐색 → 문항 번호·정답·배점·채점기준 추출 결과를 확인
   - `normalizeAnswerEntry` 유형 분류가 실제 정답표 형식과 맞는지 점검

2. **문제지 OCR·ROI 추출 단계**
   - 실제 문제지 PDF를 `parseDocumentWithUpstage()`로 파싱
   - ROI 추출 결과, 문항 번호·답란 괄호 감지, 2단 배치 처리 등 확인
   - 문항 번호 누락, 2단 배치 오분류, 답란 괄호 미검출이 여기서 시작됐는지 점검

3. **바인딩 단계 (`bindAnswerKeyToRois`)**
   - 파싱된 정답과 ROI의 `q_num` 매칭이 맞는지
   - 선잇기에서 복수 `sub_item` 통합이 의도한 대로 됐는지
   - 정답이 옆 문항에 붙거나 누락된 경우 여기일 가능성이 큼

4. **선잇기 자동 분석 단계 (`gemini_matching.php`)**
   - 크롭 이미지가 올바르게 전달됐는지
   - 방향·기준점(dot)·`ans_roi`·`pairs` 반환값이 기대한 형태인지
   - 쌍이 뒤집히거나 기준점이 틀린 경우 여기 집중

5. **저장 단계 (`save.php`)**
   - 프론트가 보낸 JSON(`rois`, `pages`, `privacy_mask_data`)과 서버가 정규화해 저장한 값이 일치하는지
   - `type`·`answer`·`score`·`matching_direction` 등 기본값 보강이 문제를 덮었는지도 같이 확인

### 3.2 재현 방식

- PHP CLI에서 `app/config/env.php` → `app/config/db.php` 순으로 loaded 한 디버그 스크립트를 실행
- 실제 첨부 PDF(문제지·정답지)를 엔진에 그대로 넣고, 각 단계의 중간 결과(Upstage elements, 표 HTML, 2D 그리드, 정규화된 정답 항목, 바인딩 결과, Gemini 매칭 결과 등)를 작업 폴더 루트에 새 파일로 출력
- API 호출은 `.env`에 있는 인증정보를 그대로 사용(코드에 키를 하드코딩하지 않음)

### 3.3 증상별 우선 의심 지점 (참고용)

아래 표는 재현 결과를 받기 전 추정 시작점입니다. 실제 재현이 나오면 그에 맞춰 좁혀갑니다.

| 증상 | 우선 확인할 단계 | 함께 볼 지점 |
| :--- | :--- | :--- |
| 문항 번호가 안 잡힘 | Upstage 파싱 → ROI 추출 | 번호 정규식 매칭, 페이지·블록 정렬 |
| 주관식인데 객관식으로 잡힘 | ROI 유형 분류 / 정답표 파싱 | 지문 키워드 판별, `type` 정규화 |
| 답란 박스가 엉뚱한 곳에 생김 | ROI 타겟팅 | 2단 격리 기준점, 괄호/밑줄 레이어 읽기 |
| 정답이 누락되거나 옆 문항에 붙음 | 정답표 파싱 → 바인딩 | 표 파싱 정확도, `q_num` 매칭, 선잇기 `sub_item` 통합 |
| 선잇기 쌍이 뒤집힘 | Gemini 매칭 / 바인딩 | 크롭 이미지, 방향·기준점, `pairs` 구성 |
| 배점이 이상함 | 정답표 파싱 → 저장 | 배점 추출, 100점 분배, `save.php` 정규화 |
| 저장 후 에디터에 이상하게 보임 | 저장 → 에디터 | `save.php` 정규화 결과, 프론트 렌더링 |

### 3.4 2026-11-06 발견 사항

- Upstage Document Parse가 정답표 PDF의 이원목적분류표를 **표로 정상 인식** (4702바이트 표 HTML)
- `parseAnswerKeyFromHtmlTable()`이 20개 문항 모두 정확 파싱 (parsedAnswers.json 8460바이트)
  - 유형별 분류(객관식/주관식/소문항/선잇기/OX/단답) 모두 정확
  - 소문항 분해 5개 문항(8,9,10,12,14번) 모두 정확
  - 선잇기 12번 pairs 구성 정확
  - 그리드 전개 20행×9열, rowspan=2 헤더 처리 정상
- 문제지 Upstage elements 57개, ROI 20개 추출 성공
  - 문항 번호, 답안 영역, 유형, 배점 모두 식별
  - 유형별 분포: objective 11개, subjective 8개, matching_line 1개
- 바인딩 결과: 20개 ROI 전부 정답 붙음, 누락 없음
- **엔진 단계(정답표 파싱·문제지 OCR·ROI·바인딩)는 정상. 실제 서비스 흐름(view→API→save)의 다른 지점에 오류 가능성 높음.**

---

## 4. 다음 행동

1. `.env`에 있는 인증정보(Upstage/Gemini/DB 키 이름·존재 여부) 확인 — **완료** (§2.6 참고)
2. 정답표 PDF를 로컬에서 먼저 열어 표 형태·열 구성을 확인(로컬 텍스트 추출이 어려우면 PHP 디버그 스크립트에서 Upstage 파싱 결과로 확인) — **완료** (서버 Upstage 파싱으로 확인, §2.6·§3.4 참고)
3. 정답표 파싱 재현용 디버그 스크립트 작성·실행 → `parsedAnswers`, 표 HTML, 2D 그리드, 정규화 결과를 출력 — **완료** (§2.6·§3.4 참고)
4. 문제지 OCR 재현용 디버그 스크립트를 같은 방식으로 실행 → Upstage elements, ROI 추출 결과 출력 — **완료** (§2.6·§3.4 참고)
5. 재현 결과에서 무너진 지점을 좁히고, 해당 지점 코드를 다시 읽은 뒤 최소 수정 방향을 잡음 — **진행 중**

### 5. 실제 서비스 흐름 오류 지점 확인을 위한 추가 수집 필요

디버그 스크립트 기준 엔진 단계는 정상이므로, 실제 **서비스 흐름**에서 오류가 발생하는 지점을 확인하기 위해 다음 정보가 필요합니다.

- **브라우저 콘솔 예외 스택**: '평가지 등록 및 ROI 지정 이동' 버튼 클릭 시 콘솔에 남은 오류
- **network 로그**: `parse_answer_key.php` / `auto_extract.php` / `save.php` 요청의 request/response (특히 response의 `error` 필드)
- **화면 오류 메시지**: 팝업/토스트/콘솔에 표시된 오류 텍스트
- **DB 저장 값**: `templates` 테이블에 저장된 `roi_data`, `pages_data`, 정답·배점 값
- **에디터 렌더링 이상**: 저장 후 `editor.php`에서 보이는 ROI 위치·정답·배점 이상 증상

위 정보를 수집하면 실제 서비스 흐름에서 오류가 발생하는 지점을 좁혀서 최소 수정 방향을 잡을 수 있습니다.
