<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/**
 * The application's plan enum, read once: which plan a Stripe price's lookup
 * key is, and which plan a stored value is.
 *
 * Checked when it is built, so a mistake is a boot failure, never a
 * subscriber put on the wrong plan: the class is a backed enum implementing
 * Plan, every lookup key is one Stripe would take, and no key is named by
 * two plans -- a subscription to it would be on whichever came first.
 */
final readonly class Plans
{
    /** @var array<string, Plan> lookup key => plan */
    private array $byLookupKey;

    /** @param class-string<Plan> $enum */
    public function __construct(public string $enum)
    {
        if (!enum_exists($enum) || !is_subclass_of($enum, Plan::class)) {
            throw new \InvalidArgumentException("{$enum} is not a backed enum implementing " . Plan::class . ': declare your plans as one, as the billing docs show.');
        }

        $byLookupKey = [];
        foreach ($enum::cases() as $plan) {
            foreach ($plan->prices() as $interval => $lookupKey) {
                if (Interval::tryFrom((string) $interval) === null) {
                    throw new \InvalidArgumentException("{$enum}::{$plan->name} prices '{$interval}', which is no interval: use month or year (Tinvar\\Billing\\Interval).");
                }
                if (preg_match('/^[A-Za-z0-9_.:-]{1,200}\z/', $lookupKey) !== 1) {
                    throw new \InvalidArgumentException("{$enum}::{$plan->name} names the price lookup key '{$lookupKey}', which is not one: letters, digits, _ . : and -.");
                }
                if (isset($byLookupKey[$lookupKey])) {
                    throw new \InvalidArgumentException("{$enum}::{$plan->name} and {$enum}::{$byLookupKey[$lookupKey]->name} both name the price '{$lookupKey}': give each plan prices of its own.");
                }
                $byLookupKey[$lookupKey] = $plan;
            }
            if ($plan->trialDays() < 0 || $plan->trialDays() > 730) {
                throw new \InvalidArgumentException("{$enum}::{$plan->name} gives {$plan->trialDays()} days of trial; Stripe takes 0 to 730.");
            }
        }
        $this->byLookupKey = $byLookupKey;
    }

    /** The plan a price's lookup key is sold under, or null for none. */
    public function forLookupKey(?string $lookupKey): ?Plan
    {
        return $lookupKey === null ? null : ($this->byLookupKey[$lookupKey] ?? null);
    }

    /** The plan a stored value names, or null for one the enum no longer has. */
    public function named(?string $value): ?Plan
    {
        if ($value === null) {
            return null;
        }

        return ($this->enum)::tryFrom($value);
    }

    /** @return list<Plan> every plan, in the enum's order */
    public function all(): array
    {
        return ($this->enum)::cases();
    }
}
