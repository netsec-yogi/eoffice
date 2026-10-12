<?php

namespace App\Modules\Identity\Directory;

use LdapRecord\Connection;
use LdapRecord\LdapRecordException;
use LdapRecord\Query\EscapedValue;
use Throwable;

/**
 * OpenLDAP via LdapRecord.
 *
 * Authentication = search with the read-only service account, then a
 * simple bind as the user's DN on a separate connection. Transport is
 * LDAPS or StartTLS with the server certificate validated against the
 * configured CA; there is no "allow invalid certificate" switch.
 */
class LdapDirectoryService implements DirectoryService
{
    public function authenticate(string $username, #[\SensitiveParameter] string $password): ?DirectoryUser
    {
        // An empty password makes a simple bind "unauthenticated" and many
        // servers report success. Never let one through.
        if ($password === '' || ! $this->validUsername($username)) {
            return null;
        }

        $user = $this->find($username);
        if ($user === null) {
            return null;
        }

        $connection = $this->connection();
        try {
            return $connection->auth()->attempt($user->dn, $password) ? $user : null;
        } catch (LdapRecordException $e) {
            throw new DirectoryUnavailable('Directory error during user bind.', previous: $e);
        } finally {
            $connection->disconnect();
        }
    }

    public function find(string $username): ?DirectoryUser
    {
        $username = strtolower(trim($username));
        if (! $this->validUsername($username)) {
            return null;
        }

        $filter = sprintf(
            '(&%s(%s=%s))',
            config('directory.user_filter'),
            config('directory.login_attribute'),
            (new EscapedValue($username))->forDnAndFilter(),
        );

        $entries = $this->search($filter, 2);

        // Zero or ambiguous matches are both "no such user".
        return count($entries) === 1 ? $entries[0] : null;
    }

    public function all(): iterable
    {
        return $this->search(config('directory.user_filter'));
    }

    public function ping(): bool
    {
        try {
            $connection = $this->connection();
            $connection->connect();
            $connection->disconnect();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<DirectoryUser> */
    private function search(string $filter, int $limit = 0): array
    {
        $connection = $this->connection();
        try {
            $connection->connect(); // binds as the service account
            $query = $connection->query()
                ->setDn(config('directory.people_dn'))
                ->rawFilter($filter)
                ->select(['*']);
            if ($limit > 0) {
                $query->limit($limit);
            }

            $users = [];
            foreach ($query->get() as $entry) {
                $dn = $entry['dn'] ?? null;
                if (! is_string($dn)) {
                    continue;
                }
                $user = DirectoryUser::fromAttributes($dn, $this->normalise($entry));
                if ($user->username !== '') {
                    $users[] = $user;
                }
            }

            return $users;
        } catch (LdapRecordException $e) {
            // Never include bind credentials; the exception message from
            // LdapRecord does not contain them.
            throw new DirectoryUnavailable('Directory search failed: '.$e->getMessage(), previous: $e);
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @param  array<string|int, mixed>  $entry
     * @return array<string, list<string>>
     */
    private function normalise(array $entry): array
    {
        $out = [];
        foreach ($entry as $key => $values) {
            if (is_int($key) || $key === 'count') {
                continue;
            }
            $values = is_array($values) ? $values : [$values];
            unset($values['count']);
            $out[strtolower($key)] = array_values(array_filter($values, 'is_string'));
        }

        return $out;
    }

    private function validUsername(string $username): bool
    {
        return (bool) preg_match(config('directory.username_pattern'), $username);
    }

    protected function connection(): Connection
    {
        $c = config('directory.connection');

        // php-ldap only honours TLS options set globally, before the handle
        // is created. Certificate validation is always demanded.
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_HARD);
        if (! empty($c['ca_cert'])) {
            ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $c['ca_cert']);
        }

        return new Connection([
            'hosts' => $c['hosts'],
            'port' => $c['port'],
            'base_dn' => $c['base_dn'],
            'username' => $c['username'],
            'password' => $c['password'],
            'use_tls' => $c['use_ssl'],
            'use_starttls' => $c['use_tls'] && ! $c['use_ssl'],
            'timeout' => $c['timeout'],
            'follow_referrals' => false,
        ]);
    }
}
