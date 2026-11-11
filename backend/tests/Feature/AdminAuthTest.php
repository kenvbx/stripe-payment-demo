<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::forceCreate(['name' => 'Administrator', 'email' => 'admin@example.com', 'password' => 'secure-test-password', 'role' => $role]);
    }

    private function payment(): Payment
    {
        return Payment::create(['customer_name' => 'Private Customer', 'customer_email' => 'private@example.com', 'product_name' => 'Pro', 'amount' => 8900, 'currency' => 'usd', 'status' => 'succeeded', 'webhook_status' => 'processed', 'idempotency_key' => 'auth-payment']);
    }

    public function test_all_admin_endpoints_reject_anonymous_requests(): void
    {
        $payment = $this->payment();
        foreach (['/api/payments', '/api/payments/'.$payment->id, '/api/webhook-events', '/api/admin/me'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['refund', 'retry-webhook'] as $action) {
            $this->postJson('/api/payments/'.$payment->id.'/'.$action)->assertUnauthorized();
        }
        $this->postJson('/api/admin/logout')->assertUnauthorized();
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_api_auth_returns_json_even_without_a_json_accept_header(): void
    {
        $this->get('/api/payments', ['Accept' => '*/*'])
            ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_login_issues_a_hashed_expiring_admin_token(): void
    {
        $this->user();
        $response = $this->postJson('/api/admin/login', ['email' => 'ADMIN@example.com', 'password' => 'secure-test-password'])
            ->assertOk()->assertJsonPath('user.role', 'admin')->assertHeader('Cache-Control', 'no-store, private');
        $token = $response->json('token');
        $stored = \Laravel\Sanctum\PersonalAccessToken::firstOrFail();
        $this->assertSame(hash('sha256', explode('|', $token, 2)[1]), $stored->token);
        $this->assertSame(['admin'], $stored->abilities);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $stored->expires_at->timestamp, 2);
        $this->withToken($token)->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.email', 'admin@example.com')->assertJsonMissingPath('data.password');
        $this->withToken($token)->getJson('/api/payments')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_invalid_password_and_non_admin_login_issue_no_tokens(): void
    {
        $user = $this->user('staff');
        foreach (['wrong-password', 'secure-test-password'] as $password) {
            $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => $password])
                ->assertUnauthorized()->assertJsonPath('message', 'Invalid administrator credentials.');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_email_returns_the_same_login_error(): void
    {
        $this->postJson('/api/admin/login', ['email' => 'unknown@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized()->assertJsonPath('message', 'Invalid administrator credentials.');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/login', ['email' => 'unknown@example.com', 'password' => 'wrong-password'])->assertUnauthorized();
        }
        $this->postJson('/api/admin/login', ['email' => 'unknown@example.com', 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_staff_bearer_token_cannot_read_or_mutate_admin_resources(): void
    {
        $payment = $this->payment();
        $token = $this->user('staff')->createToken('staff', ['admin'])->plainTextToken;
        foreach (['/api/payments', '/api/payments/'.$payment->id, '/api/webhook-events'] as $url) {
            $this->withToken($token)->getJson($url)->assertForbidden();
        }
        foreach (['refund', 'retry-webhook'] as $action) {
            $this->withToken($token)->postJson('/api/payments/'.$payment->id.'/'.$action)->assertForbidden();
        }
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_admin_token_requires_the_admin_ability(): void
    {
        $token = $this->user()->createToken('limited', ['read-only'])->plainTextToken;
        $this->withToken($token)->getJson('/api/payments')->assertForbidden();
    }

    public function test_expired_and_invalid_tokens_are_rejected(): void
    {
        $token = $this->user()->createToken('expired', ['admin'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/payments')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken('invalid-token')->getJson('/api/payments')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->user();
        $token = $user->createToken('current', ['admin'])->plainTextToken;
        $other = $user->createToken('other', ['admin'])->plainTextToken;
        $this->withToken($token)->postJson('/api/admin/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/payments')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/payments')->assertOk();
    }

    public function test_role_revocation_blocks_an_existing_admin_token(): void
    {
        $user = $this->user();
        $token = $user->createToken('admin', ['admin'])->plainTextToken;
        $user->forceFill(['role' => 'staff'])->save();
        $this->withToken($token)->getJson('/api/payments')->assertForbidden();
    }

    public function test_customer_routes_and_signature_validation_remain_public(): void
    {
        $this->getJson('/api/products')->assertOk();
        $this->postJson('/api/payments/checkout')->assertUnprocessable();
        $this->getJson('/api/checkout/sessions/cs_unknown')->assertNotFound();
        $this->postJson('/api/webhooks/stripe')->assertStatus(400);
    }

    public function test_bootstrap_seeder_is_optional_and_does_not_reset_passwords(): void
    {
        config(['admin.email' => null, 'admin.password' => null]);
        $this->seed(AdminUserSeeder::class);
        $this->assertDatabaseCount('users', 0);
        config(['admin.email' => 'bootstrap@example.com', 'admin.password' => 'secure-bootstrap-password']);
        $this->seed(AdminUserSeeder::class);
        $user = User::firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('secure-bootstrap-password', $user->password));
        config(['admin.password' => 'different-bootstrap-password']);
        $this->seed(AdminUserSeeder::class);
        $this->assertTrue(Hash::check('secure-bootstrap-password', $user->fresh()->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_bootstrap_cannot_promote_an_existing_staff_user(): void
    {
        $user = $this->user('staff');
        config(['admin.email' => $user->email, 'admin.password' => 'secure-bootstrap-password']);
        try {
            $this->seed(AdminUserSeeder::class);
            $this->fail('Expected the conflicting bootstrap account to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('staff', $user->fresh()->role);
        }
    }

    public function test_cli_creates_admin_without_a_public_registration_endpoint(): void
    {
        $this->artisan('admin:create', ['email' => 'owner@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'secure-owner-password')
            ->expectsQuestion('Confirm password', 'secure-owner-password')
            ->expectsOutput('Administrator created. Sign in from the dashboard.')
            ->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'role' => 'admin']);
        $this->postJson('/api/admin/register')->assertNotFound();
    }
}
