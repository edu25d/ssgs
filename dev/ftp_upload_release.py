import ftplib
import os
import sys

# 1. app/.env 에서 FTP 접속 정보 로드
base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
env_path = os.path.join(base_dir, "app", ".env")

config = {}
if os.path.exists(env_path):
    with open(env_path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if "=" in line and not line.startswith("#"):
                key, val = line.split("=", 1)
                config[key] = val.strip().strip('"')

FTP_HOST = config.get("FTP_HOST", "jinsu2.dothome.co.kr")
FTP_PORT = int(config.get("FTP_PORT", "21"))
FTP_USER = config.get("FTP_USER", "jinsu2")
FTP_PASS = config.get("FTP_PASSWORD", "j!4r6y4r7u")

print(f"FTP 서버: {FTP_HOST}:{FTP_PORT} (계정: {FTP_USER})")

# 2. 업로드 대상 파일 목록 (로컬 상대 경로 -> 원격 서버 경로)
upload_files = [
    ("app/api/grading/regrade_pending.php", "/html/api/grading/regrade_pending.php"),
    ("app/api/grading/save_score.php", "/html/api/grading/save_score.php"),
    ("app/api/grading/submit_images.php", "/html/api/grading/submit_images.php"),
    ("app/api/grading/regrade_by_template.php", "/html/api/grading/regrade_by_template.php"),
    ("app/includes/common/gemini_matching.php", "/html/includes/common/gemini_matching.php"),
    ("app/includes/grading/objective.php", "/html/includes/grading/objective.php"),
    ("app/views/grading/review.php", "/html/views/grading/review.php"),
    ("app/views/grading/reverse_print_modal.php", "/html/views/grading/reverse_print_modal.php"),
    ("app/public/assets/css/reverse_print.css", "/html/public/assets/css/reverse_print.css"),
    ("app/public/assets/js/reverse_print.js", "/html/public/assets/js/reverse_print.js"),
    ("app/public/assets/js/pdf-lib.min.js", "/html/public/assets/js/pdf-lib.min.js"),
]

try:
    ftp = ftplib.FTP()
    ftp.connect(FTP_HOST, FTP_PORT, timeout=20)
    ftp.login(FTP_USER, FTP_PASS)
    print("FTP 서버 로그인 성공!\n")

    for rel_src, remote_dst in upload_files:
        local_path = os.path.join(base_dir, rel_src.replace("/", os.sep))
        if not os.path.exists(local_path):
            print(f"❌ 로컬 파일 없음: {local_path}")
            continue

        # 원격 디렉토리 생성 보장
        remote_dir = os.path.dirname(remote_dst).replace("\\", "/")
        dir_parts = [p for p in remote_dir.split("/") if p]
        curr = ""
        for p in dir_parts:
            curr += "/" + p
            try:
                ftp.mkd(curr)
            except Exception:
                pass

        with open(local_path, "rb") as f:
            ftp.storbinary(f"STOR {remote_dst}", f)

        size = os.path.getsize(local_path)
        print(f"✅ 업로드 완료: {rel_src} -> {remote_dst} ({size:,} bytes)")

    ftp.quit()
    print("\n🎉 모든 수정 파일 서버 업로드 완료!")
except Exception as e:
    print(f"\n❌ FTP 업로드 실패: {type(e).__name__}: {e}")
    sys.exit(1)
