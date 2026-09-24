<?php

namespace App\Ldap;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

#[AsAlias(UserDirectoryInterface::class)]
final class LdapDirectory implements UserDirectoryInterface
{
    private ?LdapInterface $ldap = null;

    public function __construct(
        #[Autowire(env: 'bool:LDAP_ENABLED')] private readonly bool $enabled,
        #[Autowire(env: 'LDAP_URL')] private readonly string $connectionString,
        #[Autowire(env: 'LDAP_BASE_DN')] private readonly string $baseDn,
        #[Autowire(env: 'LDAP_SEARCH_DN')] private readonly string $searchDn,
        #[Autowire(env: 'LDAP_SEARCH_PASSWORD')] private readonly string $searchPassword,
        #[Autowire(env: 'LDAP_USER_FILTER')] private readonly string $userFilter,
        #[Autowire(env: 'LDAP_ADMIN_GROUP_DN')] private readonly string $adminGroupDn,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function checkCredentials(string $dn, string $password): bool
    {
        // An empty password would perform an anonymous bind, which most servers accept.
        if (!$this->enabled || '' === $dn || '' === $password) {
            return false;
        }

        try {
            $this->ldap()->bind($dn, $password);

            return true;
        } catch (InvalidCredentialsException) {
            return false;
        }
    }

    public function fetchUsers(): iterable
    {
        if (!$this->enabled) {
            return;
        }

        $ldap = $this->ldap();
        $ldap->bind($this->searchDn, $this->searchPassword);
        $query = $ldap->query($this->baseDn, $this->userFilter, [
            'filter' => ['mail', 'givenName', 'sn', 'memberOf'],
        ]);

        foreach ($query->execute() as $entry) {
            $email = $this->first($entry, 'mail');
            if (null === $email) {
                continue;
            }

            yield new DirectoryUser(
                dn: $entry->getDn(),
                email: $email,
                firstName: $this->first($entry, 'givenName'),
                lastName: $this->first($entry, 'sn'),
                admin: '' === $this->adminGroupDn ? null : \in_array(
                    mb_strtolower($this->adminGroupDn),
                    array_map('mb_strtolower', $entry->getAttribute('memberOf') ?? []),
                    true,
                ),
            );
        }
    }

    private function first(Entry $entry, string $attribute): ?string
    {
        $value = $entry->getAttribute($attribute)[0] ?? null;

        return null === $value || '' === $value ? null : (string) $value;
    }

    private function ldap(): LdapInterface
    {
        return $this->ldap ??= Ldap::create('ext_ldap', ['connection_string' => $this->connectionString]);
    }
}
