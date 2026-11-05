# 쌤글라스 (SsamGlass) — AGENTS 지침

> 교사 시험지 촬영 → OCR → 구글 시트 채점 연동 평가 보조 도구.
> 닷홈 서버: `https://jinsu2.dothome.co.kr`

## 저장소 구조

```
쌤글라스/
├── app/          # 서버 코드. contents를 닷홈 서버 루트에 업로드 (app/ 자체는 올리지 않음)
├── dev/          # 로컬 전용 작업물 (업로드 대상 아님)
│   ├── docs/     # 설계·기록 문서
│   └── SERVER_SYNC_PLAN.md  # 서버 동기화 계획 (업로드 대상·제외·절차)
├── .env          # 로컬만. 민감정보 포함. 절대 업로드 금지
└── README.md
```

## 서버 업데이트 시 기본 규칙

1. **업로드 대상:** 로컬 `app/` 아래 contents만 서버 `html/` 루트에 올린다.
   - 대상: `.htaccess`, `index.php`, `google_callback.php`, `api/`, `config/`,
     `grading/`, `includes/`, `views/`, `public/assets/`(css/js/fonts)
2. **업로드 제외 (절대):**
   - `app/.env` (Credentials 포함)
   - `public/uploads/` (사용자 시험지 사진 — 데이터 손실 위험)
   - `template/images/` (템플릿 이미지 — 동기화 대상 아님)
   - `dev/`, `.v1104/`, `*.log`, `.agents/`, `.upload_ignore`
3. **사용자 데이터 보호:** `public/uploads/crops/`, `public/uploads/originals/`는 서버에 이미 존재하는 사용자 데이터이므로 절대 덮어쓰지 않는다. 이 폴더는 업로드 대상에서 제외한다.
4. **자격증명 관리:** FTP 비밀번호(`jinsu2` 계정)를 파일·규칙·코드에 평문으로 저장하지 않는다. 서버 작업 시 세션마다 별도 전달한다.

## 서버 동기화 계획 문서

상세 절차·체크리스트·현재 서버 현황은 아래 문서를 참조한다.

- `[dev/docs/SERVER_SYNC_PLAN.md](<dev/docs/SERVER_SYNC_PLAN.md>)` — 서버 동기화 계획 (업로드 대상, 제외 항목, ftp 접속 방식, 절차, 체크리스트)

## ftp 접속 방식 요약

- **도구:** Python `ftplib`
- **서버:** `jinsu2.dothome.co.kr`, 계정: `jinsu2`
- **주의:** 서버는 SSL/TLS 미지원(평문 FTP). 비밀번호의 `!` 문자는 Bash 인라인 전달 시 오해석되므로 Python 스크립트 사용.
- **상세:** `dev/docs/SERVER_SYNC_PLAN.md` §4 참조.

## 관련 문서

- `[README.md](<README.md>)` — 프로젝트 개요·저장소 구성
- `[dev/SERVER_UPLOAD_GUIDE.md](<dev/SERVER_UPLOAD_GUIDE.md>)` — 서버 업로드 대상·제외 상세
- `[dev/WORKFLOW_IMPROVEMENT.md](<dev/WORKFLOW_IMPROVEMENT.md>)` — 업로드 대상 구분 규칙
- `[dev/docs/PROJECT_GUIDE_SSOT.md](<dev/docs/PROJECT_GUIDE_SSOT.md>)` — 전체 아키텍처
- `[dev/docs/STUDENT_PRIVACY_ARCHITECTURE.md](<dev/docs/STUDENT_PRIVACY_ARCHITECTURE.md>)` — 학생 개인정보 보호 설계
