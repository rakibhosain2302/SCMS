<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The dashboard at / needs a signed-in user.
     */
    public function test_guests_are_sent_to_the_login_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
