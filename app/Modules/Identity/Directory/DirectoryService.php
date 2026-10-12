<?php

namespace App\Modules\Identity\Directory;

/**
 * The institutional directory. The only place that knows about LDAP;
 * everything else works with DirectoryUser.
 */
interface DirectoryService
{
    /**
     * Verify a username/password against the directory.
     *
     * Returns the directory entry on success and null for bad credentials
     * (unknown user and wrong password are deliberately indistinguishable).
     *
     * @throws DirectoryUnavailable when the directory cannot be reached or
     *                              the service bind fails. Callers must fail closed.
     */
    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?DirectoryUser;

    /** @throws DirectoryUnavailable */
    public function find(string $username): ?DirectoryUser;

    /**
     * Every person entry matching the configured filter.
     *
     * @return iterable<DirectoryUser>
     *
     * @throws DirectoryUnavailable
     */
    public function all(): iterable;

    /** Whether the directory answers a service bind right now (health checks). */
    public function ping(): bool;
}
