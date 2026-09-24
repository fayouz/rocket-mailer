<?php

namespace App\Ldap;

interface UserDirectoryInterface
{
    public function isEnabled(): bool;

    public function checkCredentials(string $dn, string $password): bool;

    /** @return iterable<DirectoryUser> */
    public function fetchUsers(): iterable;
}
