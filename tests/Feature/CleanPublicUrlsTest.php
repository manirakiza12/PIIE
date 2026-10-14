<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CleanPublicUrlsTest extends TestCase
{
    use CreatesPublicSiteSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicSiteDatabase();
    }

    public function test_clean_pages_render_and_navigation_uses_clean_urls(): void
    {
        $this->assertSame(url('/about-us'), route('website.page', 'about-us'));
        foreach (['about-us', 'academic-programmes', 'contact-us'] as $slug) {
            $this->get('/'.$slug)->assertOk()->assertDontSee('/website/about-us', false);
        }
        $this->get('/')->assertOk()->assertSee(url('/about-us'), false)->assertDontSee('/website/about-us', false);
        $this->assertSame(url('/contact/enquiry'), route('website.enquiry.store'));
    }

    public function test_old_addresses_permanently_redirect_and_preserve_filters(): void
    {
        $this->get('/website/about-us')->assertStatus(301)->assertRedirect(url('/about-us'));
        $response = $this->get('/website/academic-programmes?category=masters&page=2&q=Business');
        $response->assertStatus(301);
        $target = $response->headers->get('Location');
        $this->assertSame('/academic-programmes', parse_url($target, PHP_URL_PATH));
        parse_str(parse_url($target, PHP_URL_QUERY), $query);
        $this->assertSame(['category'=>'masters', 'page'=>'2', 'q'=>'Business'], $query);
    }

    public function test_static_and_protected_routes_keep_their_existing_handlers(): void
    {
        foreach (['/login'=>'login', '/health'=>'deployment.health', '/admin/programmes'=>'admin.programmes.index'] as $path=>$name) {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($path, 'GET'));
            $this->assertSame($name, $route->getName());
        }
        $this->get('/health')->assertOk()->assertSee('PIIE-APP-OK');
        $application = Route::getRoutes()->match(\Illuminate\Http\Request::create(route('apply.form'), 'GET'));
        $this->assertSame('apply.form', $application->getName());
        $this->get('/not-a-published-page')->assertNotFound();
    }

    public function test_old_contact_forms_keep_the_same_handler_and_rate_limit(): void
    {
        $new = Route::getRoutes()->match(\Illuminate\Http\Request::create('/contact/enquiry', 'POST'));
        $old = Route::getRoutes()->match(\Illuminate\Http\Request::create('/website/contact/enquiry', 'POST'));
        $this->assertSame($new->getActionName(), $old->getActionName());
        $this->assertContains('throttle:6,1', $old->gatherMiddleware());
    }

    public function test_configured_legacy_canonicals_use_clean_local_urls(): void
    {
        $seo = \Illuminate\Support\Facades\DB::table('website_seo_settings');
        $id = $seo->insertGetId(['page_key'=>'about', 'canonical_url'=>url('/website/about-us'), 'status'=>1]);
        $this->get('/about-us')->assertOk()->assertSee('rel="canonical" href="'.url('/about-us').'"', false);
        $seo->where('id', $id)->update(['canonical_url'=>'/website/about-us']);
        $this->get('/about-us')->assertOk()->assertSee('rel="canonical" href="/about-us"', false);
        $seo->where('id', $id)->update(['canonical_url'=>'https://partner.example/website/reference']);
        $this->get('/about-us')->assertOk()->assertSee('rel="canonical" href="https://partner.example/website/reference"', false);
    }
}
