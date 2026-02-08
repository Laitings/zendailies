<?php

namespace App\Services;

use App\Repositories\AccountRepository;

final class AuthService
{
    public function __construct(private AccountRepository $repo) {}

    public function attempt(string $email, string $password): ?array
    {
        $acc = $this->repo->findActiveByEmail($email);
        if (!$acc) return null;

        if (!password_verify($password, $acc['password_hash'])) return null;

        // person_id is still handy to have
        $personUuid = $this->repo->firstPersonIdForAccount($acc['id']);

        return [
            'account_id'   => $acc['id'],                 // UUID string
            'person_id'    => $personUuid,                // may be null
            'email'        => $acc['email'],
            'first_name'   => $acc['first_name'] ?? null,
            'last_name'    => $acc['last_name'] ?? null,
            'display_name' => $acc['display_name'] ?? null,
            'is_superuser' => (bool)$acc['is_superuser'],
            'user_role'    => $acc['user_role'] ?? 'regular',   // <-- add this
            'status'       => $acc['status'] ?? null,           // <-- and this (optional but useful)
            'mfa_policy'   => $acc['mfa_policy'],
        ];
    }
}
