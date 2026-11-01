<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/**
 * A subscription's status, as Stripe names it. Only Trialing and Active
 * entitle on their own; PastDue and Unpaid entitle for the grace period
 * after the payment first failed; the rest entitle nothing (Subscription).
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Canceled = 'canceled';
    case Paused = 'paused';

    /** Whether a payment for it has failed and not yet been made good: what a grace period covers. */
    public function isPastDue(): bool
    {
        return $this === self::PastDue || $this === self::Unpaid;
    }
}
