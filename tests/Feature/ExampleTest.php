<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_root_redirects_to_the_portal_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
