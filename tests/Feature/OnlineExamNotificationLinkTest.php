<?php

namespace Tests\Feature;

use App\Support\OnlineExams\OnlineExamNotificationLink as Link;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * WHERE A NOTIFICATION LINK POINTS, DRIVEN WITH EXPLICIT HOSTS.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * An administrator clicked an Online Exam notification and left the application:
 * the link said `http://localhost/admin/online-exams/17`, the app was being served
 * from another host and port, and the browser landed on a 404 from a web server
 * that has no document root there.
 *
 * The cause was that `OnlineExamPortalNotifier` stored `route()`, which returns an
 * ABSOLUTE url rooted at `config('app.url')`. The host and port in that string are
 * decided when the row is WRITTEN, and have nothing to do with where the reader is
 * when the link is CLICKED.
 *
 * So the stored value is now a location inside this application, with no origin, and
 * it is resolved against the request in flight at click time.
 *
 * ── WHY THIS IS A UNIT TEST AND NOT A FEATURE TEST ──────────────────────────
 *
 * Because the whole point is the host, and the feature harness has no real one. A
 * feature test would assert against whatever `url()->to('/')` happened to return in
 * the harness and would pass regardless of whether the code reads the request. Here
 * the host is set explicitly for each case, so a resolver that ignored the request
 * and used the configured value would fail.
 */
class OnlineExamNotificationLinkTest extends TestCase
{
    /** Bind a request on a given origin, so `url()->to()` has a host to use. */
    private function onHost(string $url): void
    {
        $this->app['request'] = Request::create($url);
    }

    public function test_a_relative_path_is_returned_UNCHANGED(): void
    {
        $this->assertSame(
            '/admin/online-exams/17',
            Link::toRelative('/admin/online-exams/17')
        );
    }

    public function test_an_ABSOLUTE_url_for_THIS_APPLICATION_is_reduced_to_a_PATH(): void
    {
        $this->assertSame(
            '/admin/online-exams/17',
            Link::toRelative('http://localhost/admin/online-exams/17')
        );
    }

    public function test_a_QUERY_and_FRAGMENT_survive_the_reduction(): void
    {
        $this->assertSame(
            '/admin/online-exams/17?submission=10#submission-10',
            Link::toRelative('http://localhost/admin/online-exams/17?submission=10#submission-10')
        );
    }

    public function test_a_genuinely_EXTERNAL_link_is_never_touched(): void
    {
        $external = 'https://some-other-service.example/reports/42';

        $this->assertSame(
            $external,
            Link::toRelative($external),
            'another application is not ours to rewrite'
        );
        $this->assertSame(
            $external,
            Link::toAbsolute($external),
            'and rewriting it would turn a working link into a 404'
        );
    }

    /**
     * THE REPORTED BUG. A row written while the app was on one origin, clicked while
     * it is on another, must land on the origin the reader is actually using.
     */
    public function test_a_STALE_absolute_link_is_RE_ROOTED_onto_the_CURRENT_host(): void
    {
        $this->onHost('http://127.0.0.1:8000/teacher/online-exams');

        $resolved = Link::toAbsolute('http://localhost/admin/online-exams/17');

        $this->assertStringStartsWith(
            'http://127.0.0.1:8000',
            $resolved,
            'a link stored against APP_URL must not send the reader to APP_URL'
        );
        $this->assertStringEndsWith('/admin/online-exams/17', $resolved);
    }

    /**
 * A LEGACY `localhost` LINK IS REPAIRED EVEN AFTER `APP_URL` HAS BEEN CORRECTED.
 *
 * `APP_URL=localhost` used to mint every link as `http://localhost/...` with no port.
 * Once `APP_URL` is fixed to a real origin, a row still holding the old value matches
 * neither the configured host nor the reader's, so it is classified EXTERNAL and
 * returned untouched — and clicking it goes to port 80, where Apache answers 404.
 *
 * That is the originally reported bug surviving its own fix, so loopback origins are
 * treated as ours unconditionally. Nothing outside this machine is reachable at
 * `localhost` or `127.0.0.1`, so this cannot swallow a genuinely external link.
 */
public function test_a_LEGACY_localhost_link_is_repaired_AFTER_APP_URL_is_corrected(): void
{
    config(['app.url' => 'http://127.0.0.1:8000']);
    $this->onHost('http://127.0.0.1:8000/student/online-exams');

    foreach ([
        'http://localhost/admin/online-exams/17',
        'http://localhost:8000/teacher/online-exams/marking/queue',
        'http://127.0.0.1/teacher/online-exams/17/results?submission=12',
    ] as $stale) {
        $resolved = Link::toAbsolute($stale);

        $this->assertStringStartsWith(
            'http://127.0.0.1:8000',
            $resolved,
            "a stored loopback link must be re-rooted onto the live origin: {$stale}"
        );
    }

    // And a real external host is still left completely alone.
    $this->assertSame(
        'https://example.com/external',
        Link::toAbsolute('https://example.com/external')
    );
}

public function test_a_RELATIVE_link_resolves_onto_WHICHEVER_host_the_reader_is_on(): void
    {
        foreach ([
            'http://127.0.0.1:8000/teacher/online-exams',
            'http://localhost/teacher/online-exams',
            'https://piie.ac.ug/teacher/online-exams',
        ] as $origin) {
            $this->onHost($origin);

            $resolved = Link::toAbsolute('/admin/online-exams/17');

            $this->assertStringStartsWith(
                parse_url($origin, PHP_URL_SCHEME).'://'.parse_url($origin, PHP_URL_HOST),
                $resolved,
                "a stored location must resolve onto the reader's own origin ({$origin})"
            );
            $this->assertStringEndsWith('/admin/online-exams/17', $resolved);
        }
    }

    public function test_null_and_empty_stay_null(): void
    {
        $this->assertNull(Link::toRelative(null));
        $this->assertNull(Link::toRelative('   '));
        $this->assertNull(Link::toAbsolute(null));
        $this->assertNull(Link::toAbsolute(''));
    }

    /**
     * `route()` still decides WHICH page - so a renamed or moved route cannot leave
     * a stale link behind - but the origin is dropped.
     */
    public function test_the_ROUTE_helper_still_picks_the_page_from_the_ROUTING_table(): void
    {
        $relative = Link::route('admin.online_exams.show', 17);

        $this->assertSame('/admin/online-exams/17', $relative);
        $this->assertStringNotContainsString('://', $relative);
    }

    public function test_the_ROUTE_helper_can_pin_a_query_and_a_fragment(): void
    {
        $this->assertSame(
            '/admin/online-exams/17/results?submission=10#submission-10',
            Link::route('admin.online_exams.results', 17, 'submission=10', 'submission-10')
        );
    }
}