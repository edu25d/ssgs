# 서버 동기화 계획 (쌤글라스 ↔ 닷홈)

> 로컬 `app/` 내용을 닷홈 서버 `html/`(웹 루트)에 동기화하는 절차와 규칙.
> 서버 동기화 대상은 `app/` 아래 contents만이며, `dev/`는 서버에 올리지 않는다(깃허브에는 포함).
> 사용자 데이터(`public/uploads/`, `template/images/`)는 동기화 대상에서 제외한다.

**대상 서버:** `https://jinsu2.dothome.co.kr`  
**FTP 계정:** `jinsu2` / 비밀번호는 세션마다 별도 전달 (평문 저장 금지)  
**문서 버전:** 2026-02-28  
**최초 동기화 완료:** 2026-02-28 (89개 파일 업로드)  
**참조:** `[SERVER_UPLOAD_GUIDE.md](<../SERVER_UPLOAD_GUIDE.md>)`, `[WORKFLOW_IMPROVEMENT.md](../WORKFLOW_IMPROVEMENT.md)`

---

## 1. 개요

로컬 프로젝트 폴더 `쌤글라스/app/` 아래의 코드 파일들을 닷홈 서버 `html/` 루트 폴더에 업로드하여 서버와 로컬을 동기화한다.

**핵심 규칙:**
- `app/`의 **내용만** 서버 루트에 올린다 (`app/` 폴더 자체는 올리지 않음).
- `public/uploads/`(사용자 업로드 시험지 사진)와 `template/images/`(템플릿 이미지)는 동기화 대상에서 **제외**한다.
- `app/.env`(실제 Credentials 포함)는 절대 업로드하지 않는다.

---

## 2. 업로드 대상

### 2-1. 코드 파일 (전면 업로드, 덮어쓰기 허용)

로컬 `app/` 아래 다음 파일·폴더를 서버 `html/`에 업로드한다.

| 대상 | 서버 저장 위치 | 비고 |
|------|---------------|------|
| `.htaccess` | `/html/.htaccess` | Apache 라우팅/보안 설정 |
| `index.php` | `/html/index.php` | 메인 진입점 |
| `google_callback.php` | `/html/google_callback.php` | Google OAuth 콜백 |
| `api/` | `/html/api/` | 백엔드 엔드포인트 전체 (auth, classes, google, grading, student, templates) |
| `config/` | `/html/config/` | `db.php`, `env.php` |
| `grading/` | `/html/grading/` | 채점 PHP + `NanumGothic.ttf` |
| `includes/` | `/html/includes/` | 공통 include (common, google, grading) |
| `views/` | `/html/views/` | 모든 뷰 (auth, dashboard, grading, input, student, templates) |
| `public/assets/` | `/html/public/assets/` | CSS, JS, 폰트 |

> `public/assets/`에는 `css/`, `js/`, `fonts/`가 포함된다. 업로드 가이드 §1에 명시된 대상이다.

### 2-2. 업로드하지 않는 것

| 대상 | 이유 |
|------|------|
| `app/.env` | 실제 DB 비밀번호, API 키 등 Credentials 포함. 절대 업로드 금지 |
| `public/uploads/` | 사용자 업로드 데이터 (시험지 사진 등). 동기화 대상 아님 |
| `template/images/` | 템플릿 이미지. 동기화 대상 아님 |
| `dev/` 전체 | 로컬 전용 작업물 |
| `.v1104/` | 로컬 백업 폴더 (Credentials 복제본 포함) |
| `*.log` | 로그 파일 |
| `.agents/` | 에이전트 규칙 폴더 |
| `%SystemDrive%/` | 윈도우 임시 폴더 |
| `.upload_ignore` | 이 파일 자체도 업로드 불필요 |

---

## 3. 특별 관리 대상 (동기화 제외)

### 3-1. `public/uploads/` — 건너뜀

로컬 `app/public/uploads/`에는 `crops/`, `originals/` 아래에 학생별 시험지 이미지가 대량으로 있다. 서버에도 `html/public/uploads/originals/`, `html/public/uploads/crops/` 구조가 존재한다.

**처리 방침:** 이 폴더는 **업로드하지 않는다.** 서버 기존 사용자 데이터는 그대로 유지한다. 로컬 파일을 서버에 업로드하면 기존 데이터가 덮어쓰기되거나 손실될 위험이 있기 때문이다.

### 3-2. `template/images/` — 건너뜀

서버 `html/template/images/`(2jinsu/, test/ 등)에 이미 대량의 템플릿 이미지(PDF, JSON, JPG)가 존재한다. 로컬과 1:1 일치하지 않을 수 있다.

**처리 방침:** 이 폴더는 **업로드하지 않는다.** 템플릿 이미지 변경·추가가 필요한 경우 별도 작업으로 판단하며, 일괄 동기화 대상이 아니다.

---

## 4. ftp 접속 방식

- **도구:** Python `ftplib` (PHP curl 확장 미지원, Git Bash `curl`/`ftp` 명령어는 비밀번호 `!` 문자로 불안정)
- **프로토콜:** 평문 FTP (포트 21). 서버가 `AUTH SSL`/`AUTH TLS` 미지원 (`500 AUTH 이해 불가`)
- **비밀번호 입력 (표준 방식):**
  - 대화 기록에 남지 않도록 **터미널에서 직접 입력**한다.
  - 새 터미널을 열고 아래 명령어로 비밀번호를 입력하면, 스크립트 실행 시 `input()`으로 읽어온다.
  - Bash 인라인 전달은 `!` 문자 오해석 위험이 있으므로 사용하지 않는다.

  ```bash
  # 터미널에서 비밀번호 입력 (대화 기록에 남지 않음)
  python -c "import ftplib; ftp=ftplib.FTP('jinsu2.dothome.co.kr'); ftp.login('jinsu2', input('Password: '))"
  ```

  또는 Python 스크립트에서 `getpass` 사용:

  ```python
  import getpass
  import ftplib

  ftp = ftplib.FTP('jinsu2.dothome.co.kr')
  ftp.login('jinsu2', getpass.getpass('FTP 비밀번호: '))
  ftp.cwd('/html')
  # ... 업로드 작업 ...
  ftp.quit()
  ```

- **기본 패턴:**

```python
import ftplib
import getpass

ftp = ftplib.FTP('jinsu2.dothome.co.kr')
ftp.login('jinsu2', getpass.getpass('FTP 비밀번호: '))
ftp.cwd('/html')

# 파일 업로드 예시
with open('local_file.php', 'rb') as f:
    ftp.storbinary('STOR remote_file.php', f)

# 디렉토리 생성 후 업로드
ftp.mkd('/html/new_dir')
ftp.cwd('/html/new_dir')
with open('local_file.php', 'rb') as f:
    ftp.storbinary('STOR remote_file.php', f)

ftp.quit()
```

- **실제 사용 스크립트:** `dev/ftp_sync.py` — 재귀적 디렉토리 업로드, 제외 대상 자동 처리, 결과 요약 출력

- 비밀번호의 `!` 문자는 Bash에서 히스토리 확장 특수문자이므로, 인라인 명령어 전달 시 오해석된다. Python 스크립트 또는 사전에 비밀번호를 받아둔 변수를 사용한다.
- 콘솔 인코딩이 cp949라서 한글 출력이 깨질 수 있다. 결과 판단은 로직 성공 여부로 한다.

---

## 5. 동기화 절차

```
[0] 사전 확인
    - 업데이트할 로컬 app/ 내용이 최신 상태인지 확인
    - app/.env가 업로드 대상에 포함되지 않았는지 확인

[1] FTP 접속
    → Python ftplib, 호스트: jinsu2.dothome.co.kr, 계정: jinsu2
    → 비밀번호는 작업 요청 시 별도 전달 (Terminal에서 getpass로 입력)

[2] 서버 html/ 접속 확인
    → ftp.cwd('/html') 성공 여부 확인
    → 필요시 서버 현재 파일 목록 조회 (의도치 않은 파일 존재 여부 점검)

[3] 코드 파일 업로드 (전면)
    대상: .htaccess, index.php, google_callback.php,
           api/, config/, grading/, includes/, views/, public/assets/
    방법: dev/ftp_sync.py 실행 (재귀적 STOR 업로드, 제외 대상 자동 처리)
    주의: public/assets/까지만 업로드. public/uploads/는 제외.

[4] 업로드 제외 대상 확인
    → app/.env가 업로드되지 않았는지 확인
    → public/uploads/, template/images/가 업로드되지 않았는지 확인
    → dev/, .v1104/, 로그 파일 등이 업로드되지 않았는지 확인

[5] 업로드 후 서버 목록 재확인
    → 의도하지 않은 파일(특히 dev/ 문서, 로그)이 서버에 올라가지 않았는지 확인
    → 주요 코드 파일·디렉토리가 서버에 존재하는지 확인
    → dev/check_server.py로 서버 구조 확인 가능

[6] 완료 보고
    → 업로드한 파일/폴더 목록, 제외된 항목, 특이사항 보고
```

---

## 6. 사전 준비사항

1. **FTP 비밀번호** — 평문 저장 금지. 대화 기록에 남지 않도록 **터미널에서 직접 입력**한다. (`input()` 또는 `getpass.getpass()` 사용)
2. **로컬 app/ 상태** — 업데이트 대상 파일이 로컬에서 최종 확정 상태인지 확인.
3. **`config/env.php` 경로** — 서버에는 `app/` 폴더가 없으므로, `env.php`가 참조하는 `.env` 경로가 서버 루트 기준으로 되어 있는지 확인. 필요하면 업로드 전 로컬에서 수정 또는 서버 측에서 별도 조정.
4. **서버 문서 루트** — 서버의 문서 루트가 서버 루트 폴더(`html/`)를 가리키고 있는지 확인 (별도 `app/` 경로 지정 불필요).

---

## 7. 업로드 직전 체크리스트

- [ ] 업로드 대상을 `app/` 아래 contents로 한정했는가? (`app/` 자체는 올리지 않음)
- [ ] `app/.env`를 업로드에서 제외했는가?
- [ ] `dev/` 폴더를 업로드하지 않았는가?
- [ ] `.v1104/` 폴더를 업로드하지 않았는가?
- [ ] `public/uploads/`를 업로드하지 않았는가? (사용자 데이터)
- [ ] `template/images/`를 업로드하지 않았는가? (템플릿 이미지)
- [ ] `*.log` 파일을 업로드하지 않았는가?
- [ ] 서버 문서 루트가 서버 루트 폴더를 가리키도록 설정되어 있는가?
- [ ] FTP 비밀번호가 평문으로 저장되지 않았는가? (터미널에서 직접 입력, 대화 기록에 남지 않도록)

---

## 8. 서버 현황 참고 (2026-02-28 첫 동기화 후)

**서버(html/)에 존재하는 코드 디렉토리 (확인 완료):**
`api/`, `config/`, `public/`, `views/`, `grading/`, `includes/`, `template/`

**서버(html/)에 존재하는 핵심 파일 (확인 완료):**
`index.php`, `google_callback.php`, `.htaccess`, `.env.example`

**서버에 없어야 하는 파일 — 없음 확인 완료:**
`HANDOFF.md`, `gemini_debug.log`, `STUDENT_PRIVACY_ARCHITECTURE.md`,
`PROJECT_GUIDE_SSOT.md`, `GEMINI.md`, `UPSTAGE_ROI_STRATEGY.md`,
`dev_check_students_columns.php`, `db_clean_reset.sql`
> 참고: 위 파일들은 첫 동기화 전부터 서버에 존재했으나, 동기화 과정에서 업로드되지 않음 (로컬 dev/ 문서이기 때문)

**서버 `public/uploads/` 상태:**
`originals/`, `crops/` 하위 구조 존재 (내부 파일 수 미확인 — 사용자 데이터, 동기화 제외)

**로컬 app/ 구조 (전체):**
`.env`(제외), `.env.example`(선택), `.htaccess`, `api/`, `config/`, `google_callback.php`,
`grading/`, `includes/`, `index.php`, `public/`(assets/만 업로드, uploads/ 제외),
`template/`(images/ 제외), `views/`

---

## 9. 첫 동기화 완료 기록 (2026-02-28)

- **업로드 스크립트:** `dev/ftp_sync.py` (Python ftplib + getpass)
- **업로드 파일:** 89개
- **제외 파일/폴더:** 3개 (`.env`, `public/uploads/`, `template/images/`)
- **오류:** 없음
- **특이사항:**
  - 첫 실행 시 디렉토리 생성 로직 문제로 18개 오류 발생 → 스크립트 수정 후 재실행하여 해결
  - 서버에 이미 존재했던 dev/ 문서들(GEMINI.md 등)은 업로드되지 않고 기존 상태 유지
  - 비밀번호는 터미널에서 getpass로 입력받아 대화 기록에 남지 않도록 처리

---

*문서 생성: 2026-11-05  
최초 동기화 완료: 2026-02-28*
