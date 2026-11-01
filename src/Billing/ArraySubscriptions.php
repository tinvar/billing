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

    public function subscriptionsOf(Billable $billable, Plans $plans): array
    {
        $found = array_values(array_filter($this->subscriptions, static fn (Subscription $s): bool => $s->billable->is($billable)));
        usort($found, static fn (Subscription $a, Subscription $b): int => ($b->currentPeriodEnd?->getTimestamp() ?? 0) <=> ($a->currentPeriodEnd?->getTimestamp() ?? 0));

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
