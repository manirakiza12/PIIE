<?php

namespace Tests\Feature;

use App\Support\BrandingUploads;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BrandingUploadsTest extends TestCase
{
    private string $relativeDir = 'assets/uploads/branding_test';

    protected function tearDown(): void
    {
        $full = public_path($this->relativeDir);
        if (is_dir($full)) {
            foreach (glob($full . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($full);
        }
        parent::tearDown();
    }

    public function test_successful_image_upload_stores_file_and_calls_save(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 10, 10)->size(100);
        $request = \Illuminate\Http\Request::create('/', 'POST', [], [], ['school_logoo' => $file]);

        $saved = [];
        BrandingUploads::persist($request, ['school_logoo' => $this->relativeDir], function (array $stored) use (&$saved) {
            $saved = $stored;
        });

        $this->assertArrayHasKey('school_logoo', $saved);
        $this->assertFileExists(public_path($this->relativeDir . '/' . $saved['school_logoo']));
    }

    public function test_invalid_file_type_throws_validation_exception(): void
    {
        $file = UploadedFile::fake()->create('evil.php', 10, 'application/x-php');
        $request = \Illuminate\Http\Request::create('/', 'POST', [], [], ['school_logoo' => $file]);

        $called = false;
        try {
            BrandingUploads::persist($request, ['school_logoo' => $this->relativeDir], function () use (&$called) {
                $called = true;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            // expected
        }
        $this->assertFalse($called);
    }
}
