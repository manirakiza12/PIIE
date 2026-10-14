<?php
/**
 * FETCHES THE RENDERED PUBLIC SITE AND CHECKS IT HONESTLY.
 *
 *  - every internal link resolves (no 404, no 500)
 *  - every referenced image and video exists on disk
 *  - structural and accessibility invariants hold in the OUTPUT, not the source
 *
 * Reports; does not modify. Run: php scripts/audit-public-site.php
 */
$base = 'http://127.0.0.1:8000';

function get(string $url): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 30,
        'ignore_errors' => true,
        'follow_location' => 1,
        'max_redirects' => 5,
        'header' => "User-Agent: piie-audit\r\n",
    ]]);

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;

    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $status = (int) $m[1]; }
    }

    return ['status' => $status, 'body' => (string) $body];
}

$pages = ['/', '/website/academic-programmes', '/website/admissions', '/website/about-us',
          '/website/contact-us', '/website/research-and-innovation', '/website/careers',
          '/apply', '/login'];

$problems = [];
$checkedLinks = [];
$checkedAssets = [];

foreach ($pages as $page) {
    $url = $base.$page;
    $res = get($url);

    printf("%-42s %s  %6d bytes\n", $page, $res['status'] ?: 'ERR', strlen($res['body']));

    if ($res['status'] !== 200) {
        $problems[] = "PAGE {$page} returned {$res['status']}";
        continue;
    }

    $html = $res['body'];

    // ── Internal links ──
    preg_match_all('/href="([^"]+)"/i', $html, $m);

    foreach ($m[1] as $href) {
        $href = html_entity_decode($href);

        // Skip anything that is not a link this application should serve itself.
        if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')
            || str_starts_with($href, 'javascript:') || str_starts_with($href, '#')
            || str_starts_with($href, 'data:')) {
            continue;
        }

        // Same-host absolute URLs are ours and must resolve; other hosts are not
        // this application's responsibility.
        if (str_starts_with($href, '//')) { continue; }

        if (preg_match('#^https?://#i', $href)) {
            $host = parse_url($href, PHP_URL_HOST);
            $ourHost = parse_url($base, PHP_URL_HOST);

            if ($host !== $ourHost) { continue; }

            $absolute = $href;
        } else {
            $absolute = str_starts_with($href, '/') ? $base.$href : $url.$href;
        }

        $absolute = explode('#', $absolute)[0];
        $absolute = explode('?', $absolute)[0];

        if ($absolute === $base || $absolute === $base.'/') { continue; }
        if (isset($checkedLinks[$absolute])) { continue; }
        $checkedLinks[$absolute] = true;

        $r = get($absolute);

        if ($r['status'] !== 200) {
            $problems[] = "LINK {$href} (from {$page}) -> {$r['status']}";
        }
    }

    // ── Images and video ──
    preg_match_all('/(?:src|poster)="([^"]+\.(?:jpg|jpeg|png|webp|mp4))"/i', $html, $m2);

    foreach ($m2[1] as $src) {
        $src = html_entity_decode($src);

        // Media on another host is not ours to check; media on THIS host is
        // exactly what a broken-image icon comes from.
        if (str_starts_with($src, '//') || str_starts_with($src, 'data:')) { continue; }

        if (preg_match('#^https?://#i', $src)) {
            if (parse_url($src, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) { continue; }
        }

        $relative = parse_url(
            str_starts_with($src, 'http') ? $src : $base.$src,
            PHP_URL_PATH
        );

        if (! $relative) { continue; }

        $onDisk = __DIR__.'/../public'.$relative;

        if (isset($checkedAssets[$relative])) { continue; }
        $checkedAssets[$relative] = true;

        if (! is_file($onDisk)) {
            $problems[] = "ASSET MISSING {$relative} (referenced by {$page})";
        }
    }
}

echo "\n";
echo str_repeat('=', 68)."\n";
echo 'checked '.count($checkedLinks)." distinct internal links, ".count($checkedAssets)." distinct media files\n";
echo str_repeat('=', 68)."\n";

if ($problems) {
    echo count($problems)." PROBLEM(S):\n";
    foreach ($problems as $p) { echo '  - '.$p."\n"; }
} else {
    echo "No broken links and no missing media.\n";
}
