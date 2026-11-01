<?php

declare(strict_types=1);

namespace Tinvar\Billing;

use Carbon\CarbonImmutable;

/**
 * A subscription as this application keeps it: what Stripe said of it the
 * last time it was asked, and who pays for it.
 *
 * Kept from what Stripe answers when it is asked, never from a webhook's
 * body: an event names the subscription, and Billing fetches it (ADR 0150).
 * It is no Data, so the encoder refuses it and the renderer never sees it;
 * a page maps what it shows into Data of its own.
 */
final readonly class Subscription
{
    public function __construct(
        public string $id,
        public Billable $billable,
        public string $customer,
        public SubscriptionStatus $status,
        /** The plan its price is sold under, or null for a price no plan names. */
        public ?Plan $plan,
        /** Its price's lookup key, as Stripe has it. */
        public ?string $price,
        /** Its item, which a change of seats names. */
        public ?string $item,
        public int $quantity,
        public ?CarbonImmutable $trialEndsAt,
        public ?CarbonImmutable $currentPeriodStart,
        public ?CarbonImmutable $currentPeriodEnd,
        public bool $cancelAtPeriodEnd,
        public ?CarbonImmutable $canceledAt,
        public ?CarbonImmutable $endedAt,
        /** When this application first saw it past due, for the grace period; null when it is not. */
        public ?CarbonImmutable $pastDueSince,
        /** When the state kept was asked of Stripe: an older answer never replaces a newer one. */
        public CarbonImmutable $syncedAt,
    ) {}

    /**
     * Whether it entitles its payer to its plan at $now, with $grace past
     * what it was paid for.
     *
     *   - Trialing: until the trial ends, and the grace after.
     *   - Active: until the period paid for ends, and the grace after. Each
     *     renewal moves the period on; a subscription whose renewal this
     *     application never heard of stops entitling, rather than entitling
     *     forever. One set to cancel stays Active until its period ends.
     *   - PastDue, Unpaid: for the grace after it was first seen past due.
     *   - Anything else -- incomplete, canceled, paused -- nothing.
     *
     * And never on a plan the enum does not name.
     */
    public function entitles(CarbonImmutable $now, \DateInterval $grace): bool
    {
        if ($this->plan === null) {
            return false;
        }

        $until = match (true) {
            $this->status === SubscriptionStatus::Trialing => $this->trialEndsAt ?? $this->currentPeriodEnd,
            $this->status === SubscriptionStatus::Active => $this->currentPeriodEnd,
            $this->status->isPastDue() => $this->pastDueSince,
            default => null,
        };

        return $until !== null && $now < $until->add($grace);
    }

    /** Whether it is set to end, at the end of its period or already. */
    public function ending(): bool
    {
        return $this->cancelAtPeriodEnd || $this->status === SubscriptionStatus::Canceled;
    }
}
