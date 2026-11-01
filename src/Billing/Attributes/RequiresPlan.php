<?php

declare(strict_types=1);

namespace Tinvar\Billing\Attributes;

use Tinvar\Billing\Http\EnforceBilling;
use Tinvar\Billing\Plan;
use Tinvar\Http\MiddlewareAttribute;

/**
 * Lets in only a payer entitled now to one of the plans named:
 *
 *     #[Page('/reports', view: 'reports/index'), Authorize('viewAny', Report::class), RequiresPlan(Plan::Pro, Plan::Team)]
 *
 * On a page, a layout (and so every page under it), an action, an
 * endpoint, a channel or an export, on the class or its entry method,
 * inherited as #[Middleware] is. Anyone else is answered 402
 * (`plan_required`) before the loader or the action runs; nobody to bill --
 * no tenant in force, nobody signed in -- is 403 or 401. It fails closed:
 * an application that has not registered Billing with its container is
 * refused every such request (ADR 0150).
 *
 * It says what the payer bought, not who may ask: it is no #[Authorize],
 * and the build rules still want one, or #[PublicAccess], beside it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresPlan implements MiddlewareAttribute
{
    /** @var non-empty-list<Plan> */
    public array $plans;

    public function __construct(Plan ...$plans)
    {
        if ($plans === []) {
            throw new \InvalidArgumentException('#[RequiresPlan] names at least one plan.');
        }
        $this->plans = array_values($plans);
    }

    public function middleware(): string
    {
        return EnforceBilling::class;
    }
}
