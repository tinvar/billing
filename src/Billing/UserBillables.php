<?php

declare(strict_types=1);

namespace Tinvar\Billing;

use Tinvar\Auth\AuthContext;

/**
 * The signed-in user pays, for an application with no tenancy. Nobody
 * signed in is no payer, and every gate refuses.
 */
final readonly class UserBillables implements Billables
{
    public function __construct(private AuthContext $auth) {}

    public function kind(): BillableKind
    {
        return BillableKind::User;
    }

    public function current(): ?Billable
    {
        $id = $this->auth->id();

        return $id === null || $id === '' ? null : new Billable(BillableKind::User, $id);
    }
}
