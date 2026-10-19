import contextlib
import importlib.util
import io
from pathlib import Path
import unittest
from unittest.mock import patch
from email.message import Message

spec = importlib.util.spec_from_file_location('asset_check', Path(__file__).resolve().parents[2] / 'deploy/check-public-assets.py')
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)


class Response:
    status = 200

    def __init__(self, content, mime):
        self.content = content
        self.headers = Message()
        self.headers['Content-Type'] = mime

    def __enter__(self):
        return self

    def __exit__(self, *args):
        pass

    def read(self):
        return self.content


class PublicAssetCheckTest(unittest.TestCase):
    def run_check(self, opener):
        with patch.object(check, 'urlopen', side_effect=opener), contextlib.redirect_stdout(io.StringIO()):
            check.check('https://piie.ac.ug')

    def test_rejects_mixed_content_even_when_http_would_redirect(self):
        with self.assertRaisesRegex(AssertionError, 'Mixed content'):
            self.run_check(lambda *a, **k: Response(b'<link rel="stylesheet" href="http://piie.ac.ug/main.css">', 'text/html'))

    def test_rejects_html_fallback_for_missing_css(self):
        def opener(url, **kwargs):
            return Response(b'<link rel="stylesheet" href="/main.css">', 'text/html')
        with self.assertRaisesRegex(AssertionError, 'type=text/html'):
            self.run_check(opener)

    def test_fetches_css_and_javascript_with_expected_types(self):
        seen = []
        def opener(url, **kwargs):
            seen.append(url)
            if url.endswith('.css'):
                return Response(b'body { color: black; }', 'text/css')
            if url.endswith('.js'):
                return Response(b'console.log("ok");', 'text/javascript')
            return Response(b'<link rel="stylesheet" href="/main.css"><script src="/app.js"></script>', 'text/html')
        self.run_check(opener)
        self.assertIn('https://piie.ac.ug/main.css', seen)
        self.assertIn('https://piie.ac.ug/app.js', seen)


if __name__ == '__main__':
    unittest.main()
