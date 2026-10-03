<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class PrivateCredentialHandoff
{
    /**
     * Save a one-time administrator handoff outside public storage and logs.
     *
     * @param  array<int, array{user_id: int, login: ?string, password: string}>  $credentials
     */
    public function write(array $credentials): string
    {
        $directory = storage_path('app/private/security');
        $path = $directory.'/password-rotation-'.Str::uuid().'.json';
        $mask = umask(0077);
        $stream = false;

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                throw new RuntimeException('Cannot create the private handoff directory.');
            }

            if (! chmod($directory, 0700) || ($stream = fopen($path, 'x')) === false) {
                throw new RuntimeException('Cannot create a private credential handoff.');
            }

            $contents = json_encode([
                'created_at' => now()->toISOString(),
                'accounts' => $credentials,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

            if (! chmod($path, 0600)
                || fwrite($stream, $contents) !== strlen($contents)
                || ! fflush($stream)
            ) {
                throw new RuntimeException('Cannot finish the private credential handoff.');
            }
        } catch (\Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
                $stream = false;
                unlink($path);
            }

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            umask($mask);
        }

        return $path;
    }
}
