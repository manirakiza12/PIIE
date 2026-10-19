<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductionHttpsUrlsTest extends TestCase
{
    public function test_production_http_upstream_generates_https_assets_and_routes(): void
    {
        $this->app->instance('env', 'production');
        URL::setRequest(Request::create('http://piie.ac.ug/login', 'GET', [], [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example',
        ]));
        (new AppServiceProvider($this->app))->boot();

        foreach (['frontend/assets/css/bootstrap.min.css', 'assets/css/main.css',
            'frontend/assets/js/jquery-3.6.1.min.js', 'assets/js/custom.js'] as $path) {
            $this->assertSame('https://piie.ac.ug/'.$path, asset($path));
            $this->assertFileExists(public_path($path));
        }
        $this->assertSame('https://piie.ac.ug/login', url('/login'));
    }

    public function test_local_http_development_keeps_http_urls(): void
    {
        $this->app->instance('env', 'local');
        URL::setRequest(Request::create('http://localhost:8000/login'));
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame('http://localhost:8000/assets/css/main.css', asset('assets/css/main.css'));
    }
}
