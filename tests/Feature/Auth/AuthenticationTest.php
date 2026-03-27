<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_page_for_series_page(): void
    {
        $response = $this->get(route('series-infos.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@gmail.com',
            'password' => 'admin@gmail.com',
        ]);

        $response = $this->post(route('login.store'), [
            'email' => 'admin@gmail.com',
            'password' => 'admin@gmail.com',
        ]);

        $response->assertRedirect(route('series-infos.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@gmail.com',
            'password' => 'admin@gmail.com',
        ]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'admin@gmail.com',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }
}
