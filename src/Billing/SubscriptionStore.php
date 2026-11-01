<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/**
 * Where billing keeps who each Stripe customer is and what Stripe last said
 * of each subscription: DatabaseSubscriptions, or ArraySubscriptions in a
 * test.
 *
 * Every read names its payer, and a customer is matched to a payer only by
 * the row this application wrote when it made the customer -- never by
 * anything a webhook or Stripe's metadata says -- so one tenant's
 * subscription cannot be read or written as another's (ADR 0150).
 */
interface SubscriptionStore
{
    /** The Stripe customer made for $billable, or null for none yet. */
    public function customerOf(Billable $billable): ?string;

    /** The payer of kind $kind that $customer was made for, or null for a customer this application did not make. */
    public function billableOf(string $customer, BillableKind $kind): ?Billable;

    /**
     * Records that $customer was made for $billable, and answers the
     * customer kept: $customer, or the one another request kept first.
     */
    public function keepCustomer(Billable $billable, string $customer): string;

    /** @return list<Subscription> every subscription $billable has, the latest period first */
    public function subscriptionsOf(Billable $billable, Plans $plans): array;

    public function find(string $id, Plans $plans): ?Subscription;

    /**
     * Keeps $subscription, unless what is kept of it was asked of Stripe
     * later than it was: an answer fetched first and written last is the
     * stale one. Answers whether it was written.
     */
    public function keep(Subscription $subscription): bool;
}
