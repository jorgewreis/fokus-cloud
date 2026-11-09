<?php

namespace Tests\Feature\Deployment;

use Tests\TestCase;

class DeploymentIdentityTest extends TestCase
{
    public function test_unsealed_checkout_fails_closed_without_debug_details(): void
    {
        config(['app.debug' => true]);
        $this->get('/_deployment/release?deploy_probe='.str_repeat('a', 48))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'unavailable', 'release' => null, 'probe' => null])
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_invalid_or_missing_nonce_is_not_reflected(): void
    {
        foreach (['', '?deploy_probe=secret', '?deploy_probe[]=secret', '?deploy_probe='.str_repeat('A', 48), '?deploy_probe='.str_repeat('a', 48).'&debug=secret'] as $query) {
            $this->get('/_deployment/release'.$query)->assertStatus(400)
                ->assertExactJson(['status' => 'invalid_probe', 'release' => null, 'probe' => null]);
        }
        $this->post('/_deployment/release?deploy_probe='.str_repeat('a', 48))
            ->assertStatus(400);
    }

    public function test_existing_health_endpoint_is_preserved(): void
    {
        $this->get('/up')->assertOk();
    }
}
