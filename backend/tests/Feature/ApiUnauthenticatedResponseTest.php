<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiUnauthenticatedResponseTest extends TestCase
{
    public function test_api_guests_receive_json_401_without_json_accept_header(): void
    {
        $this->get('/api/v1/families')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->get('/api/v1/me', ['Accept' => 'text/html'])->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->get('/api/v1/families/999/children', ['Accept' => '*/*'])->assertUnauthorized();
    }
}
