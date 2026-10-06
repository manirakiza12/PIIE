<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentHealthTest extends TestCase
{
    /** @test */
    public function deployment_health_endpoint_boots_the_application(): void
    {
        $response = $this->get('/health');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSeeText('PIIE-APP-OK');
    }
}