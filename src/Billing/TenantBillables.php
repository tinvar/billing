<?php

declare(strict_types=1);

namespace Tinvar\Billing;

use Tinvar\Orm\Orm;

/**
 * The tenant in force pays: the tenant the request, job or command runs as,
 * which the Orm holds once the entry point's #[TenantFrom] has placed it --
 * never anything the request says about itself. No tenant in force is no
 * payer, and every gate refuses.
 */
final readonly class TenantBillables implements Billables
{
    public function __construct(private Orm $orm)
    {
        if (!$orm->hasTenancy()) {
            throw new \InvalidArgumentException(
                'Billing by tenant needs tenancy: give the Orm its tenant tree (tinvar/tenancy), or bill users with UserBillables.',
            );
        }
    }

    public function kind(): BillableKind
    {
        return BillableKind::Tenant;
    }

    public function current(): ?Billable
    {
        $tenant = $this->orm->tenant();

        return $tenant === '' ? null : new Billable(BillableKind::Tenant, $tenant);
    }
}
