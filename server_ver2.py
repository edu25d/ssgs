import mimetypes
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent
FILES = {
    "/": "index_ver2.html",
    "/index_ver2": "index_ver2.html",
    "/index_ver2.html": "index_ver2.html",
    "/styles_ver2.css": "styles_ver2.css",
    "/app_ver2.js": "app_ver2.js",
    "/favicon_ver2.svg": "favicon_ver2.svg",
    "/favicon.ico": "favicon_ver2.svg",
    "/__preview-bridge.js": "__preview-bridge.js",
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
        self.send_response(200)
        self.send_header("Content-Type", f"{content_type}; charset=utf-8")
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        if send_body:
            self.wfile.write(content)

    def log_message(self, format, *args):
        return


port = int(os.environ.get("PORT", 3000))
ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()
