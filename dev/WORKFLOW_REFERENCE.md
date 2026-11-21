# 작업 흐름 참고 — 커밋 · 푸시 · 닷홈 업로드

> 작성일: 2026-11-21  
> 대상: 앞으로 쌤글라스 코드 변경 시 반복할 작업 흐름  
> 기본 규칙: 서버 업로드 대상은 `app/` 아래 contents로 한정. `.env`는 절대 커밋·업로드 금지.

---

## 1. 사용 레포지토리 (단일)

**오직 이 레포만 사용한다.** 이전 `ssg`(`https://github.com/edu25d/ssg.git`)은 신경 쓰지 않는다.

- 원격 이름: `ssgs`
- URL: `https://github.com/edu25d/ssgs.git`
- 브랜치: `master`
- 푸시 명령: `git push ssgs master`

현재 로컬 설정:
```
origin  →  https://github.com/edu25d/ssg.git   (무시, 사용 안 함)
ssgs    →  https://github.com/edu25d/ssgs.git   (실제 사용)
```

> **참고:** `git remote remove origin`은 하지 않았다. 단순히 `ssgs`로만 푸시하면 된다.

---

## 2. Python 실행 환경

**재설치한 Python 3.15 (64-bit) 사용.**

- 실행 파일 경로: `C:/Users/USER/AppData/Local/Programs/Python/Python315/python.exe`
- 버전: Python 3.15.2
- PATH에는 등록되지 않았으므로 **전체 경로로 직접 실행**한다.

ftp_sync.py 실행 예시:
```bash
cd C:/Users/USER/Documents/ssg-new
"C:/Users/USER/AppData/Local/Programs/Python/Python315/python.exe" dev/ftp_sync.py
```

스크립트는 `app/.env`에서 `FTP_PASSWORD`를 읽으므로, 명령행 인자로 비밀번호를 전달하지 않는다(히스토리 노출 방지).

> **주의:** 구 Python(`C:/Users/USER/AppData/Local/Python/pythoncore-3.14-64/python.exe`)은 출력 문제로 사용 불가. 반드시 위 3.15 경로로 실행한다.

---

## 3. 닷홈 서버 업로드

**업로드 대상: `app/` 아래 contents만.** (`app/` 자체, `dev/`, `.env` 등은 업로드 대상 아님)

실행:
```bash
cd C:/Users/USER/Documents/ssg-new
"C:/Users/USER/AppData/Local/Programs/Python/Python315/python.exe" dev/ftp_sync.py
```

스크립트 동작:
- FTP 호스트: `jinsu2.dothome.co.kr:21`, 사용자: `jinsu2`
- 서버 루트: `/html`
- `app/.env`에서 `FTP_PASSWORD` 읽음 (길이 10, `j!4r6y4r7u`)
- 제외 대상: `.env`, `public/uploads/`, `template/images/`
- `app/` 아래 내용을 재귀 업로드, 기존 파일 덮어쓰기 주의

업로드 결과 요약은 스크립트 출력에서 확인:
- 업로드 완료 파일 수, 제외 파일 수
- 오류 있으면 스크립트 종료 코드 1

---

## 4. 커밋 흐름

코드 변경이 생기면 아래 순서로 커밋한다.

```bash
# 1. 변경분 확인
git status
git diff

# 2. 커밋 대상 스테이징 (민감 파일 제외 — .gitignore 자동 적용)
git add <변경 파일 1> <변경 파일 2> ...

# 3. 커밋
git commit -m "<타입>(<범위>): <설명>"
```

커밋 메시지 예시:
- `fix(review): 서브 탭 문항 유형 라벨에 단답형·선잇기 구분 추가`
- `feat(input): 클라이언트 마스킹 ROI 비어있음 확인 모달 구현 (방안 A)`
- `cleanup(db_migration): students.name 컬럼 가정 dead code 제거`
- `docs: 소문항 묶음 보기/분리 보기 관련 문서 언급 정리`
- `chore: .gitignore 보강 — 임시 디버그 파일, 루트 복제본 api/ 등 제외`

**민감 파일(.env 등)은 절대 `git add`하지 않는다.** `.gitignore`에 등록되어 있어 실수로 스테이징해도 커밋되지 않지만, 명시적으로 제외 대상을 확인한다.

---

## 5. GitHub 푸시

커밋 후 ssgs 레포에 푸시:
```bash
git push ssgs master
```

푸시 확인:
```bash
git log --oneline ssgs/master..master   # 푸시되지 않은 커밋 확인 (없어야 함)
git status                                # 로컬 준비 상태 확인
```

---

## 6. 전체 흐름 (한 번에)

코드 변경 → 커밋 → 푸시 → 업로드의 전체 예시:

```bash
# 작업 디렉지로 이동
cd C:/Users/USER/Documents/ssg-new

# 1. 변경분 확인
git status
git diff

# 2. 스테이징 & 커밋
git add app/views/grading/review.php app/includes/common/db_migration.php
git commit -m "fix(db_migration): dead code 제거 및 review.php 라벨 수정"

# 3. GitHub 푸시
git push ssgs master

# 4. 닷홈 업로드
"C:/Users/USER/AppData/Local/Programs/Python/Python315/python.exe" dev/ftp_sync.py
```

---

## 7. 체크리스트 (업로드 직전)

- [ ] 커밋 대상 파일이 `app/` 아래 contents인가? (또는 커밋할 의미가 있는 변경인가?)
- [ ] `.env`는 커밋·업로드 대상에서 제외됐는가?
- [ ] `dev/` 폴더는 업로드 대상이 아닌가? (ftp_sync.py가 앱만 업로드함)
- [ ] `python.exe` 경로가 `C:/Users/USER/AppData/Local/Programs/Python/Python315/python.exe`로 맞는가?
- [ ] `dev/ftp_sync.py`가 최신 상태를 반영하는가? (스크립트 자체 변경 시)

---

## 8. 참고

- `dev/ftp_sync.py`: 닷홈 FTP 업로드 스크립트. `app/.env`의 `FTP_PASSWORD` 읽음.
- `dev/WORKFLOW_IMPROVEMENT.md`: 서버 업로드 대상 구분 규칙 원문 (§1~§6).
- `dev/WORKFLOW_REFERENCE.md`: 이 파일. 앞으로의 작업 흐름 참고용.

> **갱신:** Python 경로, 원격 레포, 업로드 규칙 등 핵심 설정이 바뀌면 이 파일을 함께 갱신한다.
