<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PrivateCredentialHandoff;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class RotateDevelopmentPasswords extends Command
{
    protected $signature = 'security:rotate-development-passwords {--apply : Rotate matching passwords and revoke their API tokens}';

    protected $description = 'Audit known local development passwords; --apply saves replacements to a private local handoff';

    public function handle(PrivateCredentialHandoff $handoff): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This maintenance command is restricted to local development.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $path = null;

        try {
            $count = DB::transaction(function () use ($apply, $handoff, &$path): int {
                $users = User::query()->where('status', 'active')->whereNotNull('password')
                    ->lockForUpdate()->get();
                $credentials = [];
                $count = 0;

                foreach ($users as $user) {
                    if (! Hash::check('12345678', $user->password) && ! Hash::check('password', $user->password)) {
                        continue;
                    }

                    $count++;

                    if (! $apply) {
                        continue;
                    }

                    $password = Str::password(24, spaces: false);
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(64),
                    ])->save();
                    $user->tokens()->delete();

                    $credentials[] = [
                        'user_id' => (int) $user->id,
                        'login' => $user->email ?? $user->phone,
                        'password' => $password,
                    ];
                }

                if ($credentials !== []) {
                    // A failed handoff rolls back the passwords and token revocations.
                    $path = $handoff->write($credentials);
                }

                return $count;
            });
        } catch (Throwable $exception) {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }

            // Exception messages can contain identifiers or paths; never log credentials.
            $this->error('Rotation failed ('.$exception::class.'). No changes were committed.');

            return self::FAILURE;
        }

        $this->info($apply ? "Rotated {$count} active account(s); their API tokens were revoked." : "Found {$count} active account(s) using known development passwords. No changes made.");

        if ($path !== null) {
            $this->line('Private handoff: '.$path);
            $this->line('Move the replacements into your password manager, then remove this handoff file.');
        }

        return self::SUCCESS;
    }
}
