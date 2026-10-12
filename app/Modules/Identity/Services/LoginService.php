<?php

namespace App\Modules\Identity\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Directory\DirectoryService;
use App\Modules\Identity\Directory\DirectoryUnavailable;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifies credentials. Local accounts (vendors, external users) are
 * checked against their stored hash; everyone else against the directory.
 * If the directory cannot be reached the attempt fails (AUTH-002): there
 * is no cached-credential fallback.
 */
class LoginService
{
    public function __construct(
        private DirectoryService $directory,
        private UserProvisioner $provisioner,
        private AuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function attempt(string $username, #[\SensitiveParameter] string $password): User
    {
        $username = strtolower(trim($username));
        $local = User::query()->where('username', $username)->where('auth_source', 'local')->first();

        if ($local) {
            if (! $local->password || ! Hash::check($password, $local->password)) {
                $this->fail($username, 'invalid_credentials', $local);
            }
            $user = $local;
        } else {
            if (! config('directory.enabled')) {
                $this->fail($username, 'directory_disabled_in_config');
            }

            try {
                $entry = $this->directory->authenticate($username, $password);
            } catch (DirectoryUnavailable $e) {
                report($e);
                $this->fail($username, 'directory_unavailable', message: __('auth.directory_unavailable'));
            }

            if ($entry === null) {
                $this->fail($username, 'invalid_credentials');
            }

            $user = $this->provisioner->sync($entry);
        }

        if (! $user->canSignIn()) {
            $this->fail($username, $user->directory_disabled ? 'directory_account_disabled' : 'account_inactive', $user, __('auth.inactive'));
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, ['username' => $username, 'source' => $user->auth_source], actor: $user);

        return $user;
    }

    private function fail(string $username, string $reason, ?User $user = null, ?string $message = null): never
    {
        $this->audit->record('auth.login', $user, ['username' => $username, 'reason' => $reason], outcome: 'failure', actor: null);

        throw ValidationException::withMessages(['username' => $message ?? __('auth.failed')]);
    }
}
