<?php

namespace Tests\Support;

use App\Modules\Identity\Directory\DirectoryService;
use App\Modules\Identity\Directory\DirectoryUnavailable;
use App\Modules\Identity\Directory\DirectoryUser;

/** In-memory directory for tests. Bound in Tests\TestCase. */
class FakeDirectory implements DirectoryService
{
    /** @var array<string, array{user: DirectoryUser, password: string}> */
    public array $entries = [];

    public bool $down = false;

    public function add(string $username, string $password = 'secret-pass', array $overrides = []): DirectoryUser
    {
        $user = new DirectoryUser(...array_merge([
            'username' => $username,
            'dn' => "uid={$username},ou=people,dc=institute,dc=test",
            'name' => ucfirst($username).' Test',
            'email' => "{$username}@institute.test",
            'category' => 'staff',
        ], $overrides));
        $this->entries[$username] = ['user' => $user, 'password' => $password];

        return $user;
    }

    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?DirectoryUser
    {
        $this->guard();
        $entry = $this->entries[strtolower($username)] ?? null;

        return $entry && $password !== '' && hash_equals($entry['password'], $password) ? $entry['user'] : null;
    }

    public function find(string $username): ?DirectoryUser
    {
        $this->guard();

        return $this->entries[strtolower($username)]['user'] ?? null;
    }

    public function all(): iterable
    {
        $this->guard();

        return array_values(array_map(fn ($e) => $e['user'], $this->entries));
    }

    public function ping(): bool
    {
        return ! $this->down;
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new DirectoryUnavailable('Fake directory is down.');
        }
    }
}
