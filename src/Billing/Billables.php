<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/**
 * Who pays for the request in force: TenantBillables or UserBillables.
 */
interface Billables
{
    /** Which kind this application bills. */
    public function kind(): BillableKind;

    /** The payer for the request in force, or null for none: no tenant, nobody signed in. */
    public function current(): ?Billable;
}
