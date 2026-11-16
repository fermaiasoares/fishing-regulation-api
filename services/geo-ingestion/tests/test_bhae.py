import hashlib
import http.server
import json
import sys
import tempfile
import threading
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from bhae import archive_path, check, download  # noqa: E402

PAYLOAD = b"not really a geopackage, only bytes to hash" * 1000


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        self.send_response(200)
        self.send_header("Content-Length", str(len(PAYLOAD)))
        self.send_header("Last-Modified", "Mon, 04 Dec 2023 17:11:57 GMT")
        self.end_headers()
        self.wfile.write(PAYLOAD)

    def log_message(self, *args):
        pass


class BhaeArchiveTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def test_download_is_content_addressed_and_leaves_no_partial_file(self):
        server = http.server.HTTPServer(("127.0.0.1", 0), Handler)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        try:
            path, digest, size, modified = download(f"http://127.0.0.1:{server.server_port}/f.gpkg", self.root)
        finally:
            server.shutdown()
        self.assertEqual(hashlib.sha256(PAYLOAD).hexdigest(), digest)
        self.assertEqual(len(PAYLOAD), size)
        self.assertEqual("Mon, 04 Dec 2023 17:11:57 GMT", modified)
        self.assertEqual(archive_path(self.root, digest), path)
        self.assertEqual(f"sha256/{digest[:2]}/{digest}.gpkg", path.relative_to(self.root).as_posix())
        self.assertEqual([], list(self.root.glob("*.part")))

    def test_check_rejects_a_file_that_does_not_match_the_manifest(self):
        digest = hashlib.sha256(PAYLOAD).hexdigest()
        stored = archive_path(self.root, digest)
        stored.parent.mkdir(parents=True)
        stored.write_bytes(PAYLOAD)
        manifest_dir = self.root / "manifest"
        manifest_dir.mkdir()
        manifest = {"sha256": digest, "archive_path": stored.relative_to(self.root).as_posix()}
        (manifest_dir / "manifest.json").write_text(json.dumps(manifest))

        self.assertEqual(digest, check(self.root, manifest_dir)["sha256"])
        stored.write_bytes(PAYLOAD + b"tampered")
        with self.assertRaises(ValueError):
            check(self.root, manifest_dir)

    def test_check_explains_a_missing_archive_file(self):
        manifest_dir = self.root / "manifest"
        manifest_dir.mkdir()
        (manifest_dir / "manifest.json").write_text(json.dumps({"sha256": "0" * 64, "archive_path": "sha256/00/x.gpkg"}))
        with self.assertRaises(FileNotFoundError):
            check(self.root, manifest_dir)


if __name__ == "__main__":
    unittest.main()
