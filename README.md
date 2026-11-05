# 쌤글라스 (SsamGlass)

교사가 학생 시험지를 촬영하면 OCR로 답안을 읽고, 구글 시트에 채점 결과를 연동하는 평가 보조 도구.

## 무엇을 하는가

- 교사가 시험지를 일괄/개별 촬영하면, 인적사항(학교·학년·반·번호·이름) 영역을 서버 전송 전 클라이언트에서 마스킹한 뒤 업로드한다.
- 업스테이지 OCR로 답안 영역을 읽어 채점에 활용한다.
- 채점 결과는 교사의 구글 시트에 동기화·기록하고, 서버의 원본 이미지는 평가 마감 시 자동 삭제한다.
- 학생 인증은 학급별 PIN(4자리)으로 하고, 서버 DB에는 실명 대신 `student_hash`(가명 식별자)만 저장한다.
- 교사는 교육청 공식 Google Workspace 계정으로 로그인한다.

## 저장소 구성

```
쌤글라스/
├── app/          # 실제 서버 코드. 닷홈 서버 루트에 올릴 대상.
│   ├── api/      # 백엔드 엔드포인트
│   ├── config/   # DB·환경 설정
│   ├── public/   # 정적 자산 (JS, CSS, 폰트 등)
│   ├── views/    # 화면 PHP
│   ├── grading/  # 채점 관련
│   ├── includes/ # 공통 라이브러리
│   ├── template/ # 시험지 템플릿 이미지
│   ├── index.php
│   ├── .htaccess
│   └── google_callback.php
├── dev/          # 로컬 작업 문서.
│   ├── docs/     # STUDENT_PRIVACY_ARCHITECTURE.md 등 설계·기록 문서
│   ├── SERVER_UPLOAD_GUIDE.md
│   └── WORKFLOW_IMPROVEMENT.md
└── .gitignore    # 커밋 배제 규칙
```

- `app/` 아래가 서버 코드다. 닷홈 서버에는 `app/`의 내용만 올리면 된다(`app/` 자체나 `dev/`는 업로드 대상 아님).
- `.env`는 실제 Credentials가 들어 있어 커밋하지 않는다. 서버에서는 `.env.example`을 복사해 값을 채워 사용한다.

## 학생 개인정보 처리 (개요)

학생 실명·출석번호는 서버 DB에 평문으로 저장하지 않는 것이 설계 방향이다. 핵심 아이디어:

- 학생 식별은 `class_salt` 기반 `student_hash`(SHA-256 자르기)로 한다.
- 학생 실명은 교사의 구글 시트에만 두고, 서버는 해시만 주고받는다.
- 시험지 촬영 시 인적사항 영역은 클라이언트 캔버스에서 미리 마스킹해 서버로 보낸다.

세부 명세는 `dev/docs/STUDENT_PRIVACY_ARCHITECTURE.md`를 본다.

## 환경 설정

1. `app/.env.example`을 복사해 `app/.env`를 만들고, DB 접속 정보 등 필요한 값을 채운다.
2. `app/` 내용을 닷홈 서버 루트로 올린다. DB 스키마는 `db_migration.php` 실행 시 자동 생성·마이그레이션된다.

업로드 대상·순서는 `dev/SERVER_UPLOAD_GUIDE.md`와 `dev/WORKFLOW_IMPROVEMENT.md`를 본다.

## 운영 서버

- 닷홈 웹호스팅: `https://jinsu2.dothome.co.kr`
- DB: `jinsu2`

## 문서

- `dev/docs/STUDENT_PRIVACY_ARCHITECTURE.md` — 학생 개인정보 보호 아키텍처 및 확인 과제
- `dev/docs/PROJECT_GUIDE_SSOT.md` — 전체 아키텍처·역할 분담
- `dev/WORKFLOW_IMPROVEMENT.md` — 서버 업로드 대상 구분 규칙, 향후 검토 과제 (DB 스키마 파일은 2026-11-07 삭제, 스키마는 코드 기반으로 관리)
- `dev/SERVER_UPLOAD_GUIDE.md` — 서버 업로드 실무 가이드

## 상태

작업 진행 중. 로그인 보안(PIN brute-force 방어), 마스킹 실패 처리, 코드 정리 등이 과제로 남아 있다. 상세는 `dev/WORKFLOW_IMPROVEMENT.md` §8을 본다.
