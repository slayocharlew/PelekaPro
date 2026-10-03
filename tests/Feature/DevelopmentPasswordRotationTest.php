<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\PrivateCredentialHandoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevelopmentPasswordRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_count_without_changing_passwords_tokens_or_writing_files(): void
    {
        $user = $this->user('12345678');
        $user->createToken('existing-device');
        $originalHash = $user->password;
        $this->mock(PrivateCredentialHandoff::class)->shouldNotReceive('write');

        $this->assertSame(0, Artisan::call('security:rotate-development-passwords'));
        $this->assertStringContainsString('Found 1 active account(s)', Artisan::output());
        $this->assertStringNotContainsString($user->email, Artisan::output());
        $this->assertSame($originalHash, $user->refresh()->password);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_apply_rotates_only_matching_active_accounts_revokes_tokens_and_preserves_login(): void
    {
        $first = $this->user('12345678');
        $second = $this->user('password');
        $strong = $this->user('already-strong-test-42');
        $inactive = $this->user('password', 'inactive');
        $deleted = $this->user('password');
        $deleted->delete();
        $originalStrongHash = $strong->password;
        $originalInactiveHash = $inactive->password;
        $originalRemember = $first->remember_token;
        $oldToken = $first->createToken('first-device')->plainTextToken;
        $first->createToken('second-device');
        $second->createToken('third-device');
        $strong->createToken('unaffected-device');
        $captured = [];
        $this->mock(PrivateCredentialHandoff::class)->shouldReceive('write')->once()
            ->andReturnUsing(function (array $credentials) use (&$captured): string {
                $captured = $credentials;

                return storage_path('app/private/security/test-only-not-written.json');
            });

        $this->assertSame(0, Artisan::call('security:rotate-development-passwords', ['--apply' => true]));
        $this->assertStringContainsString('Rotated 2 active account(s)', Artisan::output());
        $this->assertCount(2, $captured);
        $this->assertNotSame($captured[0]['password'], $captured[1]['password']);

        foreach ($captured as $credential) {
            $this->assertSame(24, strlen($credential['password']));
            $this->assertTrue(Hash::check($credential['password'], User::findOrFail($credential['user_id'])->password));
            $this->assertStringNotContainsString($credential['password'], Artisan::output());
            $this->assertStringNotContainsString($credential['login'], Artisan::output());
        }

        $this->assertNotSame($originalRemember, $first->refresh()->remember_token);
        $this->assertSame(0, $first->tokens()->count());
        $this->assertSame(0, $second->tokens()->count());
        $this->assertSame(1, $strong->tokens()->count());
        $this->assertSame($originalStrongHash, $strong->refresh()->password);
        $this->assertSame($originalInactiveHash, $inactive->refresh()->password);
        $this->assertTrue(Hash::check('password', $deleted->refresh()->password));

        $this->withToken($oldToken)->getJson('/api/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/auth/login', ['email' => $first->email, 'password' => '12345678'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['login' => $captured[0]['login'], 'password' => $captured[0]['password']])->assertOk();

        $this->assertSame(0, Artisan::call('security:rotate-development-passwords', ['--apply' => true]));
        $this->assertStringContainsString('Rotated 0 active account(s)', Artisan::output());
    }

    public function test_handoff_failure_rolls_back_password_and_token_changes(): void
    {
        $user = $this->user('password');
        $hash = $user->password;
        $remember = $user->remember_token;
        $user->createToken('preserve-on-failure');
        $this->mock(PrivateCredentialHandoff::class)->shouldReceive('write')->once()
            ->andThrow(new \RuntimeException('private-error-detail-not-for-output'));

        $this->assertSame(1, Artisan::call('security:rotate-development-passwords', ['--apply' => true]));
        $this->assertStringNotContainsString('private-error-detail-not-for-output', Artisan::output());
        $this->assertSame($hash, $user->refresh()->password);
        $this->assertSame($remember, $user->remember_token);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_command_refuses_production_execution(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->assertSame(1, Artisan::call('security:rotate-development-passwords', ['--apply' => true]));
            $this->assertStringContainsString('restricted to local development', Artisan::output());
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_private_handoff_uses_exclusive_files_with_owner_only_permissions(): void
    {
        $handoff = app(PrivateCredentialHandoff::class);
        $paths = [];

        try {
            foreach (range(1, 2) as $attempt) {
                $paths[] = $handoff->write([['user_id' => 1, 'login' => 'safe@example.test', 'password' => 'test-only-not-a-live-password']]);
            }

            $this->assertNotSame($paths[0], $paths[1]);

            foreach ($paths as $path) {
                $this->assertStringStartsWith(storage_path('app/private/security/'), $path);
                $this->assertSame(0600, fileperms($path) & 0777);
                $this->assertSame(0700, fileperms(dirname($path)) & 0777);
                $this->assertSame('safe@example.test', json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['accounts'][0]['login']);
            }
        } finally {
            // Only these exact, newly created test artifacts are removed.
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }

    private function user(string $password, string $status = 'active'): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'business_owner'], ['display_name' => 'Business owner']);

        return User::factory()->create(['role_id' => $role->id, 'password' => $password, 'status' => $status]);
    }
}
