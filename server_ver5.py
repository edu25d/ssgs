import mimetypes
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import quote, urlsplit

ROOT = Path(__file__).resolve().parent
FILES = {
    "/": "index_ver5.html",
    "/index_ver5": "index_ver5.html",
    "/index_ver5.html": "index_ver5.html",
    "/styles_ver5.css": "styles_ver5.css",
    "/app_ver5.js": "app_ver5.js",
    "/favicon_ver5.svg": "favicon_ver5.svg",
    "/favicon.ico": "favicon_ver5.svg",
    "/__preview-bridge.js": "__preview-bridge.js",
    "/download/apps-script-login-ver5.zip": "AppsScript_Login_Source_ver5.zip",
    "/download/student-code-ver5.gs": "AppsScript_Student_Code_ver5.gs",
    "/download/pin-setup-diagnostic-ver6.gs": "AppsScript_Student_PinSetup_Diagnostic_ver6.gs",
    "/download/pin-setup-diagnostic-ver7.gs": "AppsScript_Student_PinSetup_Diagnostic_ver7.gs",
    "/download/student-page-ver5.html": "AppsScript_Student_Index_ver5.html",
    "/download/student-manifest-ver5.json": "AppsScript_Student_appsscript_ver5.json",
    "/download/teacher-code-ver5.gs": "AppsScript_Teacher_Code_ver5.gs",
    "/download/teacher-access-setup-ver6.gs": "AppsScript_Teacher_Access_Setup_ver6.gs",
    "/download/teacher-context-check-ver7.gs": "AppsScript_Teacher_Context_Check_ver7.gs",
    "/download/teacher-authorize-sheet-ver6.gs": "AppsScript_Teacher_Authorize_Spreadsheet_ver6.gs",
    "/download/teacher-setup-guide-ver6.md": "교사_계정_등록_절차_ver6.md",
    "/download/teacher-page-ver5.html": "AppsScript_Teacher_Index_ver5.html",
    "/download/teacher-manifest-ver5.json": "AppsScript_Teacher_appsscript_ver5.json",
    "/download/login-guide-ver5.md": "Google_Sheets_Apps_Script_로그인_설계_ver5.md",
    "/download/school-admin-code-ver6.gs": "AppsScript_Teacher_Admin_Menu_Code_ver6.gs",
    "/download/school-admin-guide-ver6.md": "Google_Sheets_학교관리자_메뉴_가이드_ver6.md",
    "/download/school-admin-menu-ver6.zip": "School_Admin_Menu_Source_ver6.zip",
}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.serve_file()

    def do_HEAD(self):
        self.serve_file(send_body=False)

    def serve_file(self, send_body=True):
        path = urlsplit(self.path).path
        name = FILES.get(path)
        if not name:
            self.send_error(404)
            return

        try:
            content = (ROOT / name).read_bytes()
        except OSError:
            self.send_error(404)
            return

        content_type = mimetypes.guess_type(name)[0] or "application/octet-stream"
        if name.endswith(".gs"):
            content_type = "text/plain"
        elif name.endswith(".md"):
            content_type = "text/markdown"
        self.send_response(200)
        self.send_header("Content-Type", f"{content_type}; charset=utf-8")
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "no-store")
        if path.startswith("/download/"):
            filename = Path(name).name
            ascii_name = (
                filename.encode("ascii", "ignore").decode("ascii") or "download"
            )
            self.send_header(
                "Content-Disposition",
                f"attachment; filename=\"{ascii_name}\"; filename*=UTF-8''{quote(filename)}",
            )
        self.end_headers()
        if send_body:
            self.wfile.write(content)

    def log_message(self, format, *args):
        return


port = int(os.environ.get("PORT", 3000))
ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()
