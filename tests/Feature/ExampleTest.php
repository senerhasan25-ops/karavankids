<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Guest kullanıcı için '/' login sayfasına yönlendirir (dashboard auth ister).
     */
    public function test_root_redirects(): void
    {
        $response = $this->get('/');

        // '/' → '/dashboard' (auth middleware) → '/login'
        $response->assertStatus(302);
    }
}
