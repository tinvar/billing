<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/** SubscriptionStore in memory, for a test. */
final class ArraySubscriptions implements SubscriptionStore
{
    /** @var array<string, string> billable key => customer */
    private array $customers = [];

    /** @var array<string, Billable> customer => billable */
    private array $billables = [];

    /** @var array<string, string> billable key => its first subscription */
    private array $first = [];

    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    public function customerOf(Billable $billable): ?string
    {
        return $this->customers[$billable->key()] ?? null;
    }

    public function billableOf(string $customer, BillableKind $kind): ?Billable
    {
        $billable = $this->billables[$customer] ?? null;

        return $billable?->kind === $kind ? $billable : null;
    }

    public function keepCustomer(Billable $billable, string $customer): string
    {
        if (isset($this->customers[$billable->key()])) {
            return $this->customers[$billable->key()];
        }
        if (isset($this->billables[$customer])) {
            throw new \LogicException("Stripe customer {$customer} is already another payer's.");
        }
        $this->billables[$customer] = $billable;

        return $this->customers[$billable->key()] = $customer;
    }

    public function firstSubscription(Billable $billable, string $subscription): string
    {
        if (!isset($this->customers[$billable->key()])) {
            throw new \LogicException("{$billable->key()} has no Stripe customer kept, so no first subscription to record.");
        }

        return $this->first[$billable->key()] ??= $subscription;
    }

    public function subscriptionsOf(Billable $billable, Plans $plans): array
    {
        $found = array_values(array_filter($this->subscriptions, static fn (Subscription $s): bool => $s->billable->is($billable)));
        // As DatabaseSubscriptions orders them: the latest period first, one with
        // none last, then by id.
        usort($found, static fn (Subscription $a, Subscription $b): int => ($a->currentPeriodEnd === null) <=> ($b->currentPeriodEnd === null)
            ?: $b->currentPeriodEnd <=> $a->currentPeriodEnd
            ?: $a->id <=> $b->id);

        return $found;
    }

    public function find(string $id, Plans $plans): ?Subscription
    {
        return $this->subscriptions[$id] ?? null;
    }

    public function keep(Subscription $subscription): bool
    {
        $kept = $this->subscriptions[$subscription->id] ?? null;
        if ($kept !== null && $kept->syncedAt > $subscription->syncedAt) {
            return false;
        }
        $this->subscriptions[$subscription->id] = $subscription;

        return true;
    }
}
