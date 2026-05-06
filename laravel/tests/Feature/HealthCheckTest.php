<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_healthz_returns_ok_payload(): void
    {
        $response = $this->get('/healthz');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'app' => 'archibot',
            ]);
    }
}
