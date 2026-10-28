<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/audio')->assertRedirect(route('login'));
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_user_can_register_and_reaches_the_studio(): void
    {
        $response = $this->post(route('register.attempt'), [
            'name' => 'New Producer',
            'email' => 'producer@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertRedirect(route('audio.index'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'producer@example.com']);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->post(route('register.attempt'), [
            'name' => 'Weak Password',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_a_user_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertRedirect(route('audio.index'));

        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
