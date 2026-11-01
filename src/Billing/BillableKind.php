<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/**
 * Who pays: the tenant in force, in an application with tenancy, or the
 * signed-in user, in one without. One application bills one kind.
 */
enum BillableKind: string
{
    case Tenant = 'tenant';
    case User = 'user';
}
