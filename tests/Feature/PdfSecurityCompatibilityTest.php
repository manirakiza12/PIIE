<?php

namespace Tests\Feature;

use Barryvdh\DomPDF\Facade\Pdf;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class PdfSecurityCompatibilityTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    public function test_admission_offer_download_generates_a_valid_pdf(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);
        $programme = $this->makeProgramme($school);
        $admission = $this->makeAdmission($school, ['programme_id' => $programme]);
        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.offer_letter', $admission));
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertValidPdf($response->getContent());
    }

    public function test_production_student_profile_template_generates_a_valid_pdf(): void
    {
        $pdf = Pdf::loadView('admin.student.profile_pdf', [
            'student_details' => ['name' => 'Test Student', 'code' => 'PDF-001',
                'school_name' => 'Test Institution', 'email' => 'student@example.test'],
            'profile' => null,
        ]);
        $this->assertValidPdf($pdf->output());
    }

    public function test_local_images_and_dejavu_fonts_are_embedded(): void
    {
        $imagePath = tempnam(storage_path('framework'), 'pdf-image-');
        try {
            $image = imagecreatetruecolor(12, 12);
            imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 140));
            $this->assertTrue(imagepng($image, $imagePath));
            imagedestroy($image);
            $pdf = Pdf::loadHTML('<html><head><meta charset="utf-8"></head><body style="font-family: DejaVu Sans">'
                . '<p>Institution — résumé</p><img src="' . $imagePath . '" width="12" height="12"></body></html>');
            $output = $pdf->output();
            $this->assertValidPdf($output);
            $this->assertStringContainsString('/Subtype /Image', $output);
            $this->assertStringContainsString('/FontFile2', $output);
            $this->assertNotNull($pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans'));
        } finally {
            unlink($imagePath);
        }
    }

    public function test_remote_resources_are_explicitly_disabled_without_network_requests(): void
    {
        $options = Pdf::loadHTML('<p>Offline PDF</p>')->getDomPDF()->getOptions();
        $this->assertFalse($options->isRemoteEnabled());
        [$allowed, $reason] = $options->validateRemoteUri('https://example.invalid/logo.png');
        $this->assertFalse($allowed);
        $this->assertStringContainsString('disabled', strtolower($reason));
    }

    private function assertValidPdf(string $output): void
    {
        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
        $this->assertStringContainsString('startxref', $output);
        $this->assertStringEndsWith('%%EOF', trim($output));
    }
}
