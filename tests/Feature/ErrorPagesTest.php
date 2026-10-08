<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Professional error pages: correct HTTP status, a clear next step, and never
 * any internals (stack trace, SQL, file paths, environment values) — with
 * APP_DEBUG off, as in production. The pages are self-contained so they render
 * even when the database is unavailable.
 */
class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        Route::get('/_errors/throw', fn () => throw new \RuntimeException('SQLSTATE[42S22] secret C:\\Users\\HP\\app\\X.php DB_PASSWORD=hunter2'));
        Route::get('/_errors/{code}', fn (int $code) => abort($code))->where('code', '4\d\d|5\d\d');
    }

    public static function pages(): array
    {
        return [
            '403' => [403, 'Access denied', 'Go to my dashboard'],
            '419' => [419, 'Your session has expired', 'Sign in again'],
            '429' => [429, 'Too many requests', 'Try again'],
            '500' => [500, 'Something went wrong', 'Try again'],
            '503' => [503, 'Temporarily unavailable', 'Try again'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_branded_page_keeps_the_status_and_offers_a_next_step(int $code, string $heading, string $action): void
    {
        $this->get("/_errors/{$code}")->assertStatus($code)->assertSee($heading)->assertSee($action)
            ->assertDontSee('Symfony')->assertDontSee('Whoops');
    }

    public function test_an_unexpected_exception_shows_no_internals(): void
    {
        $response = $this->get('/_errors/throw');

        $response->assertStatus(500)->assertSee('Something went wrong');
        foreach (['SQLSTATE', 'hunter2', 'DB_PASSWORD', 'RuntimeException', 'Stack trace', '.php', 'C:\\', 'vendor'] as $internal) {
            $response->assertDontSee($internal, false);
        }
    }

    public function test_json_clients_get_a_json_error_without_internals(): void
    {
        $response = $this->getJson('/_errors/throw');

        $response->assertStatus(500)->assertJson(['message' => 'Server Error']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_the_error_pages_do_not_touch_the_database(): void
    {
        foreach (['_piie', '403', '419', '429', '500', '503'] as $view) {
            // Code only: the Blade doc comment names these calls precisely to forbid them.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(resource_path("views/errors/{$view}.blade.php")));
            foreach (['DB::', 'get_phrase', 'auth()->user()', '::where(', '::find('] as $dependency) {
                $this->assertStringNotContainsString($dependency, $source, "errors/{$view}");
            }
        }
    }
}
