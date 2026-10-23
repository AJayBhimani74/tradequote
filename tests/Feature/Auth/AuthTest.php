<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Asha Traders',
            'email' => 'asha@example.com',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'role' => 'vendor',
            'company_name' => 'Asha Traders Pvt Ltd',
        ], $overrides);
    }

    public function test_user_can_register_as_vendor_and_gets_a_token(): void
    {
        $response = $this->postJson('/api/register', $this->payload());

        $response->assertCreated()->assertJsonStructure(['user' => ['id', 'email', 'role'], 'token']);
        $this->assertSame(Role::Vendor, User::first()->role);
        $this->assertDatabaseHas('vendors', ['company_name' => 'Asha Traders Pvt Ltd']);
    }

    public function test_register_rejects_unknown_role_and_duplicate_email(): void
    {
        $this->postJson('/api/register', $this->payload(['role' => 'admin']))
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        User::factory()->create(['email' => 'asha@example.com']);
        $this->postJson('/api/register', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_vendor_registration_needs_a_company_name(): void
    {
        $this->postJson('/api/register', $this->payload(['company_name' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('company_name');

        $this->postJson('/api/register', $this->payload(['role' => 'buyer', 'company_name' => null]))
            ->assertCreated();
        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_login_with_valid_and_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'asha@example.com', 'password' => 'secret-pass']);

        $this->postJson('/api/login', ['email' => 'asha@example.com', 'password' => 'secret-pass'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/login', ['email' => 'asha@example.com', 'password' => 'wrong'])
            ->assertUnprocessable();
    }

    public function test_me_requires_a_token_and_logout_revokes_it(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();

        $token = $this->postJson('/api/register', $this->payload())->json('token');
        $headers = ['Authorization' => "Bearer $token"];

        $this->getJson('/api/me', $headers)->assertOk()->assertJsonPath('email', 'asha@example.com');
        $this->postJson('/api/logout', [], $headers)->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
