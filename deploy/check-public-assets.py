"""Read-only HTTPS page/asset smoke test; no credentials or browser dependencies."""
import sys
from html.parser import HTMLParser
from urllib.parse import urljoin, urlsplit
from urllib.request import urlopen


class Assets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.assets = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'link' and 'stylesheet' in attrs.get('rel', '').split():
            self.assets.append((attrs.get('href', ''), 'css'))
        if tag == 'script' and attrs.get('src'):
            self.assets.append((attrs['src'], 'js'))


def fetch(url, kind):
    with urlopen(url, timeout=25) as response:
        mime = response.headers.get_content_type()
        expected = {'html': {'text/html'}, 'css': {'text/css'},
                    'js': {'text/javascript', 'application/javascript'}}[kind]
        body = response.read()
        if response.status != 200 or mime not in expected or not body.strip():
            raise AssertionError(f'{url}: status={response.status}, type={mime}, bytes={len(body)}')
        print(f'PASS {response.status} {mime} {len(body)} bytes {url}')
        return body


def check(base):
    if urlsplit(base).scheme != 'https':
        raise AssertionError('Public origin must use HTTPS')
    assets = set()
    for path in ['/', '/login', '/admin/course-registrations', '/careers', '/applicant/login']:
        parser = Assets()
        parser.feed(fetch(urljoin(base, path), 'html').decode('utf-8'))
        if not any(kind == 'css' for _, kind in parser.assets):
            raise AssertionError(f'No stylesheets in {path}')
        for raw, kind in parser.assets:
            url = urljoin(base, raw)
            if urlsplit(url).scheme != 'https':
                raise AssertionError(f'Mixed content in {path}: {raw}')
            if urlsplit(url).netloc == urlsplit(base).netloc:
                assets.add((url, kind))
    for url, kind in sorted(assets):
        fetch(url, kind)
    print(f'PASS: {len(assets)} same-origin CSS/JS assets; no mixed content')


if __name__ == '__main__':
    check(sys.argv[1] if len(sys.argv) > 1 else 'https://piie.ac.ug')
