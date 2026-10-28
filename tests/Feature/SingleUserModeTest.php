<?php

namespace Tests\Feature;

use App\Http\Middleware\UseStudioUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SingleUserModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_redirects_to_the_studio(): void
    {
        $this->get('/')->assertRedirect(route('audio.index'));
    }

    public function test_login_and_registration_routes_are_not_available(): void
    {
        $this->get('/login')->assertNotFound();
        $this->post('/login')->assertNotFound();
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();

        $this->assertFalse(Route::has('login'));
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('logout'));
    }

    public function test_the_studio_is_available_without_signing_in(): void
    {
        $this->get(route('audio.index'))
            ->assertOk()
            ->assertSee('Audio Effects Studio');

        $this->assertDatabaseHas('users', ['email' => UseStudioUser::EMAIL]);
    }
}
