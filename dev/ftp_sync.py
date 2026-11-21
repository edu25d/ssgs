#!/usr/bin/env python3
"""
쌤글라스 서버 동기화 스크립트
- 로컬 app/ 내용을 닷홈 서버 html/에 FTP로 업로드
- 제외 대상: .upload_ignore 패턴 + .env + public/uploads/ + template/images/
- 비밀번호는 getpass로 입력받아 대화 기록에 남지 않음
"""

import fnmatch
import os
import sys
import ftplib
import getpass
from pathlib import Path

# 설정
FTP_HOST = 'jinsu2.dothome.co.kr'
FTP_USER = 'jinsu2'
FTP_PORT = 21
LOCAL_APP_DIR = Path('app')
SERVER_ROOT = '/html'

# 업로드 제외 패턴
EXCLUDE_DIRS = {
    'public/uploads',           # 사용자 데이터
    'template/images',          # 템플릿 이미지
}

# .upload_ignore에서 로드한 패턴 (파일 glob + 디렉토리 prefix)
IGNORE_FILE_GLOBS: set[str] = set()
IGNORE_DIR_PREFIXES: set[str] = set()

# 명시적 파일 제외 (기본 하드코딩 + .upload_ignore glob로 보완)
EXCLUDE_FILES = {
    '.env',                     # Credentials
}

# 로그
uploaded_files = []
skipped_files = []
errors = []

def should_exclude(path: Path, base: Path) -> bool:
    """경로가 업로드 제외 대상인지 확인"""
    rel = path.relative_to(base)
    parts = rel.parts

    if path.is_file():
        # 명시적 파일 제외 (.env 등)
        if path.name in EXCLUDE_FILES:
            return True
        # .upload_ignore 파일 glob 패턴 (*.log, *.bak 등)
        for glob_pat in IGNORE_FILE_GLOBS:
            if fnmatch.fnmatch(path.name, glob_pat):
                return True

    # 디렉토리 제외 확인 (어떤 하위 경로든 포함)
    for i in range(len(parts)):
        if '/'.join(parts[:i+1]) in EXCLUDE_DIRS:
            return True
        if '/'.join(parts[:i+1]) in IGNORE_DIR_PREFIXES:
            return True

    return False

def load_upload_ignore(ignore_path: Path) -> None:
    """ .upload_ignore를 파싱해 IGNORE_FILE_GLOBS / IGNORE_DIR_PREFIXES를 채운다.

    - '/'가 없는 줄 → 파일 glob 패턴으로 해석 (예: *.log, *.bak)
    - '/'가 있는 줄 → 디렉토리 prefix로 해석 (예: dev/, .v1104/)
    - app/ 아래만 순회하므로, dev/·.v1104/ 등 app 밖 디렉토리는 무시해도 무방하나
      혹시나 app 안에 같은 이름의 폴더가 생기면 대비하려고 파싱해 둔다.
    """
    global IGNORE_FILE_GLOBS, IGNORE_DIR_PREFIXES
    if not ignore_path.exists():
        return
    try:
        for raw in ignore_path.read_text(encoding='utf-8', errors='replace').splitlines():
            line = raw.strip()
            if not line or line.startswith('#'):
                continue
            if '/' in line:
                # 디렉토리 패턴: 마지막 슬래시 제거 후 prefix로 저장
                prefix = line.rstrip('/')
                # app/ 기준 prefix만 의미 있음 (app/ 바깥은 순회 대상이 아님)
                if prefix.startswith('app/'):
                    prefix = prefix[len('app/'):]
                IGNORE_DIR_PREFIXES.add(prefix)
            else:
                IGNORE_FILE_GLOBS.add(line)
    except (OSError, UnicodeDecodeError):
        pass

def ftp_upload_dir(ftp: ftplib.FTP, local_path: Path, remote_path: str, base: Path):
    """디렉토리 재귀 업로드"""
    try:
        # 디렉토리 생성 시도 (이미 존재하면 무시)
        try:
            ftp.mkd(remote_path)
            print(f'    [+] 디렉토리 생성: {remote_path}')
        except ftplib.error_perm:
            pass  # 이미 존재

        # 디렉토리로 이동
        try:
            ftp.cwd(remote_path)
        except ftplib.error_perm as e:
            errors.append(f'{remote_path}: 이동 실패 - {e}')
            print(f'    [!] 디렉토리 이동 실패: {remote_path}')
            return

        for item in sorted(local_path.iterdir()):
            if item.is_dir():
                if should_exclude(item, base):
                    skipped_files.append(str(item.relative_to(base)))
                    print(f'  [-] 제외: {item.relative_to(base)}/')
                    continue
                ftp_upload_dir(ftp, item, item.name, base)
            elif item.is_file():
                if should_exclude(item, base):
                    skipped_files.append(str(item.relative_to(base)))
                    print(f'  [-] 제외: {item.relative_to(base)}')
                    continue

                with open(item, 'rb') as f:
                    ftp.storbinary(f'STOR {item.name}', f)
                uploaded_files.append(str(item.relative_to(base)))
                print(f'  [+] 업로드: {item.relative_to(base)}')

        ftp.cwd('..')
    except Exception as e:
        errors.append(f'{remote_path}: {e}')
        print(f'  [!] 오류: {remote_path} - {e}')

def read_ftp_password_from_env(env_path: Path) -> str | None:
    """app/.env에서 FTP_PASSWORD 키를 읽어 반환. 없으면 None."""
    if not env_path.exists():
        return None
    try:
        for line in env_path.read_text(encoding='utf-8', errors='replace').splitlines():
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            name, _, value = line.partition('=')
            name = name.strip()
            value = value.strip().strip('"').strip("'")
            if name == 'FTP_PASSWORD':
                return value
    except (OSError, UnicodeDecodeError):
        return None
    return None

def main():
    print('=' * 50)
    print('쌤글라스 서버 동기화 (FTP)')
    print('=' * 50)
    print()

    # .upload_ignore 로드
    load_upload_ignore(Path('.upload_ignore'))
    if IGNORE_FILE_GLOBS:
        print(f'[0] .upload_ignore 파일 glob 패턴: {", ".join(sorted(IGNORE_FILE_GLOBS))}')
    if IGNORE_DIR_PREFIXES:
        print(f'[0] .upload_ignore 디렉토리 prefix: {", ".join(sorted(IGNORE_DIR_PREFIXES))}')
    print()

    # 비밀번호 결정: 명령행 인자 > .env(FTP_PASSWORD) > getpass
    if len(sys.argv) > 1:
        password = sys.argv[1]
        print(f'비밀번호 인자 받음 (길이: {len(password)})')
    else:
        env_password = read_ftp_password_from_env(Path('app') / '.env')
        if env_password is not None:
            password = env_password
            masked = '*' * max(1, len(env_password))
            print(f'.env에서 FTP_PASSWORD 읽음 (길이: {len(env_password)}, 표시: {masked})')
        else:
            password = getpass.getpass(f'FTP 비밀번호 ( {FTP_USER}@{FTP_HOST} ): ')
    print()

    # FTP 접속
    print(f'[1] FTP 접속: {FTP_HOST}:{FTP_PORT}')
    try:
        ftp = ftplib.FTP(FTP_HOST)
        ftp.login(FTP_USER, password)
        ftp.set_pasv(True)
        print(f'    로그인 성공')
    except Exception as e:
        print(f'    [!] 접속 실패: {e}')
        sys.exit(1)

    # 서버 루트 이동
    print(f'[2] 서버 루트 이동: {SERVER_ROOT}')
    try:
        ftp.cwd(SERVER_ROOT)
        print(f'    현재 디렉토리: {ftp.pwd()}')
    except Exception as e:
        print(f'    [!] 이동 실패: {e}')
        ftp.quit()
        sys.exit(1)

    # 로컬 app/ 존재 확인
    if not LOCAL_APP_DIR.exists():
        print(f'[!] 로컬 app/ 디렉토리가 존재하지 않습니다: {LOCAL_APP_DIR.absolute()}')
        ftp.quit()
        sys.exit(1)

    # 업로드 대상 목록 출력
    print()
    print('[3] 업로드 대상 확인')
    print(f'    로컬: {LOCAL_APP_DIR.absolute()}')
    print(f'    서버: {SERVER_ROOT}/')
    print()

    # 업로드 실행
    print('[4] 파일 업로드 시작')
    print('-' * 40)

    # 최상위 파일 먼저 업로드
    for item in sorted(LOCAL_APP_DIR.iterdir()):
        if item.is_file():
            if should_exclude(item, LOCAL_APP_DIR):
                skipped_files.append(item.name)
                print(f'  [-] 제외: {item.name}')
                continue

            with open(item, 'rb') as f:
                ftp.storbinary(f'STOR {item.name}', f)
            uploaded_files.append(item.name)
            print(f'  [+] 업로드: {item.name}')

        elif item.is_dir():
            if should_exclude(item, LOCAL_APP_DIR):
                skipped_files.append(item.name)
                print(f'  [-] 제외: {item.name}/')
                continue

            ftp_upload_dir(ftp, item, item.name, LOCAL_APP_DIR)

    print('-' * 40)
    print()

    # 결과 요약
    print('[5] 업로드 결과')
    print(f'    업로드 완료: {len(uploaded_files)}개 파일')
    print(f'    제외: {len(skipped_files)}개 파일/폴더')
    if errors:
        print(f'    오류: {len(errors)}개')
        for e in errors:
            print(f'      - {e}')

    print()
    print('업로드된 파일 목록:')
    for f in uploaded_files:
        print(f'  - {f}')

    if skipped_files:
        print()
        print('제외된 파일/폴더:')
        for f in skipped_files:
            print(f'  - {f}')

    # FTP 종료
    ftp.quit()
    print()
    print('=' * 50)
    print('동기화 완료')
    print('=' * 50)

    # 오류 있으면 종료 코드 1
    if errors:
        sys.exit(1)

if __name__ == '__main__':
    main()
