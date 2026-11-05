# dothome 서버 업로드 가이드

이 문서는 `쌤글라스` 프로젝트를 dothome 서버에 올릴 때 **무엇을 올리고 무엇을 제외해야 하는지** 정리한 참고용 파일이다.
로컬 작업 폴더와 서버 업로드 대상이 헷갈리지 않도록 유지한다.

**현재 구조 (app/ 폴더 분리 적용됨):**

```
쌤글라스/
├── app/              ← 로컬에서만 구분하는 폴더. contents가 서버 루트로 올라감 (app/ 자체는 올리지 않음)
├── dev/              ← 로컬 전용 작업물 (서버 업로드 대상 아님, 깃허브 저장소에는 포함)
├── .env              ← 로컬만. 민감정보 포함. 절대 업로드 금지
├── .env.example      ← 참고용. 올려도 안전
└── .upload_ignore    ← 업로드 제외 패턴 목록 (참고용, 업로드 대상 아님)
```

---

## 1. 서버에 올려야 하는 것

**`app/` 폴더 아래 contents를 서버의 루트 폴더에 올린다.** `app/` 자체는 업로드하지 않으며, 서버에는 `app/` 폴더가 존재하지 않는다. 로컬 프로젝트 폴더에서만 `app/`로 구분할 뿐이다.

```
app/
├── api/                  → API 엔드포인트 전체 (auth, classes, google, grading, student, templates)
├── config/               → db.php, env.php
├── public/               → assets/(CSS/JS/이미지), uploads/(사용자 업로드 파일)
├── template/             → images/(템플릿 이미지)
├── views/                → 모든 뷰 (auth, dashboard, grading, input, student, templates)
├── grading/              → 채점 PHP 파일 + NanumGothic.ttf
├── includes/             → 공통 include (common, google, grading)
├── .htaccess             → Apache 라우팅/보안 설정
├── google_callback.php   → Google OAuth 콜백
└── index.php             → 메인 진입점
```

**선택적**

| 대상 | 판단 기준 |
|---|---|
| `app/config/env.php` | `.env` 파일 paths가 `app/.env`를 참조하도록 설정되어 있음. 서버에는 `app/` 폴더가 없으므로, 서버 루트의 `.env`를 참조하도록 조정 필요 |
| `db_clean_reset.sql` | 삭제됨 (2026-11-07). 과거 로컬 DB 초기화용 임시 파일. 현재 스키마는 코드(`db_migration.php` 등)에 내재된 정보로 확인. |

---

## 2. 서버에 올리면 안 되는 것 (절대 제외)

### 민감 정보 포함 — 유출 시 치명적

| 대상 | 이유 |
|---|---|
| **`app/.env`** | 실제 DB 비밀번호, Upstage/Google/Gemini API 키 등 실Credentials 포함. 절대 업로드 금지 |

### 업로드 제외 패턴 (`.upload_ignore` 참조)

| 대상 | 이유 |
|---|---|
| `dev/` | 로컬 전용 작업물 전체. 서버에는 올리지 않으나 깃허브 저장소에는 포함한다. |
| `*.log` | Gemini_debug.log 등 로그 파일 |
| `.v1104/` | 프로젝트 복제 백업 폴더 (내부에 Credentials 복제본 있음) |
| `.agents/` | 에이전트 규칙 폴더 |
| `%SystemDrive%/` | 윈도우 임시 폴더 |
| `whoami.txt` | 진단용 더미 파일 |
| `.upload_ignore` | 이 파일 자체도 업로드 불필요 (참고용) |

---

## 3. 업로드 전 체크리스트

1. **업로드 대상을 `app/` 아래 contents로 한정했는가?** — `app/`에 담긴 폴더와 파일들만 올리고 `app/` 자체와 `dev/` 등은 제외된다.
2. **`app/.env`를 제외했는가?** — 가장 중요. 포함되면 DB/API Credentials 유출.
3. **`dev/` 폴더를 업로드하지 않았는가?**
4. **`public/uploads/`는 기존 업로드된 사용자 파일이 있다면 유지** — 새로 올릴 때 기존 파일을 덮어쓰지 않도록 주의.

---

## 4. `.env` 관리 규칙

- `.env`는 **로컬에만 존재**한다 (`app/.env`). 서버에 절대 올리지 않는다.
- 실서버에 환경변수가 필요하면 `.env.example`을 참고해 서버에서 `.env`를 생성하고 값을 채운다.
- `.env.example`은 플레이스홀더만 들어있으므로 업로드해도 안전하다 (참고 용도로 올릴 수 있음).

---

## 5. 서버 문서 루트 설정

`app/` 폴더 아래 contents가 서버 루트에 올라가므로, **서버의 문서 루트(document root)는 서버 루트 폴더 자체를 가리키면 된다.** 별도로 `app/`을 가리킬 필요가 없다.

로컬의 `app/` 아래 폴더 구조(`api/`, `config/`, `public/` 등)가 그대로 서버 루트에 펼쳐지므로, PHP 파일 경로(`APP_ROOT`)와 웹 URL 경로가 자연스럽게 일치한다.

---

## 6. 새 작업 시작 시 규칙

- 서버 업로드 대상 코드는 무조건 `app/` 안에 만든다.
- 로컬 전용 작업(문서, 실험, 백업, 임시 파일)은 무조건 `dev/` 안에 만든다.
- 루트에 새 파일/폴더를 만들 때는 **서버 업로드 대상인지 먼저 판단**한다.
- `.env` 파일은 `app/.env` 위치에 두고, 업로드 시 항상 제외한다.

---

*Created: 2026-09-07*
