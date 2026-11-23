import json
import mimetypes
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent
FILES = {
    "/": "index_ver4.html",
    "/index_ver4": "index_ver4.html",
    "/index_ver4.html": "index_ver4.html",
    "/styles_ver4.css": "styles_ver4.css",
    "/app_ver4.js": "app_ver4.js",
    "/favicon_ver4.svg": "favicon_ver4.svg",
    "/favicon.ico": "favicon_ver4.svg",
    "/__preview-bridge.js": "__preview-bridge.js",
}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        path = urlsplit(self.path).path
        if path == "/runtime-config.js":
            self.serve_runtime_config()
            return
        self.serve_file(path)

    def do_HEAD(self):
        path = urlsplit(self.path).path
        if path == "/runtime-config.js":
            body = self.runtime_config_body()
            self.send_response(200)
            self.send_header("Content-Type", "application/javascript; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            return
        self.serve_file(path, send_body=False)

    def runtime_config_body(self):
        config = {
            "supabaseUrl": os.environ.get("SUPABASE_URL", ""),
            "supabasePublishableKey": os.environ.get("SUPABASE_PUBLISHABLE_KEY", ""),
        }
        return f"window.RUNTIME_CONFIG = {json.dumps(config)};".encode("utf-8")

    def serve_runtime_config(self):
        body = self.runtime_config_body()
        self.send_response(200)
        self.send_header("Content-Type", "application/javascript; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def serve_file(self, path, send_body=True):
        name = FILES.get(path)
        if not name:
            self.send_error(404)
            return
        try:
            body = (ROOT / name).read_bytes()
        except OSError:
            self.send_error(404)
            return
        content_type = mimetypes.guess_type(name)[0] or "application/octet-stream"
        self.send_response(200)
        self.send_header("Content-Type", f"{content_type}; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        if send_body:
            self.wfile.write(body)

    def log_message(self, format, *args):
        return


port = int(os.environ.get("PORT", 3000))
ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()
