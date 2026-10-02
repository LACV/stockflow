<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

class MethodNotAllowedRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_to_livewire_update_endpoint_returns_clean_405_json(): void
    {
        $this->get(EndpointResolver::updatePath())
            ->assertStatus(405)
            ->assertJson([]);
    }

    public function test_get_to_livewire_upload_endpoint_returns_clean_405_json(): void
    {
        $this->get(EndpointResolver::uploadPath())
            ->assertStatus(405)
            ->assertJson([]);
    }
}
