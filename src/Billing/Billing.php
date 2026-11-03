<?php

declare(strict_types=1);

namespace Tinvar\Billing;

use Carbon\CarbonImmutable;
use Tinvar\Billing\Stripe\StripeClient;
use Tinvar\Billing\Stripe\StripeException;
use Tinvar\Http\HttpException;

/**
 * Billing, as an application registers it with its container (ADR 0150):
 * its plans, who pays (Billables), where subscriptions are kept, and the
 * Stripe client.
 *
 *     $container->instance(Billing::class, new Billing(
 *         Plan::class,
 *         new TenantBillables($orm),              // or new UserBillables($auth)
 *         new DatabaseSubscriptions($connection),
 *         BillingSetup::stripe($outbound),        // the keys, from TINVAR_STRIPE_*
 *     ));
 *
 * What a payer's plan is comes from the subscriptions kept, which only
 * sync() writes: from what Stripe answers when it is asked about a
 * subscription, never from a webhook's body, so an event that arrives late,
 * twice or out of order leaves the state Stripe holds now. An older answer
 * never replaces a newer one (SubscriptionStore::keep()).
 *
 * The payer is always Billables' -- the tenant in force or the user signed
 * in -- or, for a webhook, the payer this application made the Stripe
 * customer for. Nothing a request or an event says names one.
 */
final class Billing
{
    public readonly Plans $plans;

    public readonly \DateInterval $grace;

    /** @var array<string, string> lookup key => price id, asked of Stripe once a worker */
    private array $priceIds = [];

    /**
     * @param class-string<Plan>|Plans $plans the application's plan enum
     * @param \DateInterval|null $grace how long a subscription keeps entitling past what was paid
     *        for -- after a failed payment, or a period's end whose renewal was not heard of; 3 days unless given
     * @param Plan|null $freePlan the plan of a payer with no subscription that entitles; none unless given
     * @param (\Closure(): CarbonImmutable)|null $clock now, in UTC
     */
    public function __construct(
        string|Plans $plans,
        private readonly Billables $billables,
        private readonly SubscriptionStore $store,
        private readonly StripeClient $stripe,
        ?\DateInterval $grace = null,
        public readonly ?Plan $freePlan = null,
        private readonly ?\Closure $clock = null,
    ) {
        $this->plans = $plans instanceof Plans ? $plans : new Plans($plans);
        $this->grace = $grace ?? new \DateInterval('P3D');
        if ($freePlan !== null && !$freePlan instanceof $this->plans->enum) {
            throw new \InvalidArgumentException('The free plan is one of ' . $this->plans->enum . '\'s cases.');
        }
    }

    public function stripe(): StripeClient
    {
        return $this->stripe;
    }

    public function billables(): Billables
    {
        return $this->billables;
    }

    /** Who pays for the request in force, or null for nobody. */
    public function payer(): ?Billable
    {
        return $this->billables->current();
    }

    /**
     * The subscription that entitles $billable now, or else its latest;
     * null for none. The payer in force unless given.
     */
    public function subscription(?Billable $billable = null): ?Subscription
    {
        $billable ??= $this->payer();
        if ($billable === null) {
            return null;
        }
        $subscriptions = $this->store->subscriptionsOf($billable, $this->plans);
        foreach ($subscriptions as $subscription) {
            if ($subscription->entitles($this->now(), $this->grace)) {
                return $subscription;
            }
        }

        return $subscriptions[0] ?? null;
    }

    /** The plan $billable is entitled to now -- its subscription's, or the free plan -- or null for none. */
    public function plan(?Billable $billable = null): ?Plan
    {
        $subscription = $this->subscription($billable);

        return $subscription !== null && $subscription->entitles($this->now(), $this->grace) ? $subscription->plan : $this->freePlan;
    }

    /**
     * Whether the payer in force -- or $billable -- is entitled to one of $plans now.
     *
     * @param list<Plan> $plans
     */
    public function onPlan(array $plans, ?Billable $billable = null): bool
    {
        $plan = $this->plan($billable);

        return $plan !== null && \in_array($plan, $plans, true);
    }

    /**
     * Whether the payer in force -- or $billable -- has one of $features now.
     *
     * @param list<\BackedEnum> $features
     */
    public function hasFeature(array $features, ?Billable $billable = null): bool
    {
        $plan = $this->plan($billable);
        if ($plan === null) {
            return false;
        }
        foreach ($features as $feature) {
            if (\in_array($feature, $plan->features(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Starts Stripe Checkout for the payer in force to subscribe to $plan,
     * and answers the URL to send them to -- with an action's
     * `ActionResult::location()`, whose allowlist holds
     * StripeClient::REDIRECT_ORIGINS.
     *
     * The plan's trial is offered only to a payer with no subscription kept,
     * of any status: a payer's first. The Stripe customer is made once per
     * payer and kept; the session names the payer in its
     * client_reference_id and the subscription's metadata, for a person
     * reading Stripe's dashboard. What the subscription is, is
     * learnt from Stripe when its webhook arrives.
     *
     * @param string $successUrl where Stripe sends them once they have paid: absolute, this application's
     * @param string $cancelUrl where Stripe sends them if they go back
     * @param int $seats the subscription's quantity
     * @throws HttpException 401 or 403 with no payer in force
     * @throws StripeException
     */
    public function checkout(Plan $plan, Interval $interval, string $successUrl, string $cancelUrl, int $seats = 1): string
    {
        $billable = $this->requirePayer();
        if (!$plan instanceof $this->plans->enum) {
            throw new \InvalidArgumentException($plan::class . "::{$plan->name} is not one of " . $this->plans->enum . '\'s plans.');
        }
        $lookupKey = $plan->prices()[$interval->value] ?? throw new HttpException(
            422,
            "The {$plan->name} plan is not sold {$interval->value}ly: choose an interval its prices name.",
            'plan_not_sold',
        );
        self::assertSeats($seats);

        $session = $this->stripe->post('/v1/checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $this->customerFor($billable),
            'client_reference_id' => self::reference($billable),
            'line_items' => [['price' => $this->priceId($lookupKey), 'quantity' => $seats]],
            'success_url' => self::ownUrl($successUrl, 'success'),
            'cancel_url' => self::ownUrl($cancelUrl, 'cancel'),
            'subscription_data' => [
                'metadata' => ['tinvar_billable' => $billable->key()],
                // A trial is the payer's first subscription's alone: offered
                // again, a payer could cancel each before it ended and
                // subscribe anew, on the plan for ever and never charged.
                'trial_period_days' => $plan->trialDays() > 0 && $this->store->subscriptionsOf($billable, $this->plans) === [] ? $plan->trialDays() : null,
            ],
        ], 'tinvar-checkout-' . bin2hex(random_bytes(16)));

        return self::hosted($session, 'https://checkout.stripe.com', 'Checkout');
    }

    /**
     * Opens Stripe's Customer Portal for the payer in force -- to change
     * their card, plan or seats, see invoices, or cancel -- and answers the
     * URL to send them to.
     *
     * @throws HttpException 401 or 403 with no payer in force; 409 for a payer who never checked out
     * @throws StripeException
     */
    public function portal(string $returnUrl): string
    {
        $billable = $this->requirePayer();
        $customer = $this->store->customerOf($billable) ?? throw new HttpException(
            409,
            'There is no billing account to manage yet: subscribe to a plan first.',
            'no_billing_account',
        );
        $session = $this->stripe->post('/v1/billing_portal/sessions', [
            'customer' => $customer,
            'return_url' => self::ownUrl($returnUrl, 'return'),
        ], 'tinvar-portal-' . bin2hex(random_bytes(16)));

        return self::hosted($session, 'https://billing.stripe.com', 'the Customer Portal');
    }

    /**
     * Sets the seats -- the quantity -- of the payer in force's
     * subscription, prorated as Stripe prorates, and keeps what Stripe
     * answers.
     *
     * @throws HttpException 401 or 403 with no payer in force; 409 with no subscription to change
     * @throws StripeException
     */
    public function updateSeats(int $seats): Subscription
    {
        $billable = $this->requirePayer();
        self::assertSeats($seats);
        $subscription = $this->subscription($billable);
        if ($subscription === null || $subscription->item === null || \in_array($subscription->status, [SubscriptionStatus::Canceled, SubscriptionStatus::IncompleteExpired], true)) {
            throw new HttpException(409, 'There is no subscription to change the seats of: subscribe to a plan first.', 'no_subscription');
        }

        $syncedAt = $this->now();
        $object = $this->stripe->post("/v1/subscriptions/{$subscription->id}", [
            'items' => [['id' => $subscription->item, 'quantity' => $seats]],
            'proration_behavior' => 'create_prorations',
        ], 'tinvar-seats-' . bin2hex(random_bytes(16)));

        return $this->keep($object, $syncedAt, $subscription->id) ?? throw new \LogicException("Stripe answered the seat change to {$subscription->id} with a subscription this application does not hold.");
    }

    /**
     * Asks Stripe for subscription $id and keeps what it answers, for the
     * payer whose customer it is; null for a subscription of a customer this
     * application did not make, which is left alone.
     *
     * @throws StripeException
     */
    public function sync(string $id): ?Subscription
    {
        self::assertId($id, 'sub_');
        $syncedAt = $this->now();

        return $this->keep($this->stripe->get("/v1/subscriptions/{$id}"), $syncedAt, $id);
    }

    /**
     * What a verified Stripe event changes: the subscription it names,
     * fetched from Stripe and kept (sync()). Only the id is read from the
     * event; its state is Stripe's answer now, so an event late, repeated
     * or out of order changes nothing it should not. Null for an event that
     * names no subscription, one of the other mode's, or one of a customer
     * this application did not make.
     *
     * @param array<array-key, mixed> $event
     * @throws StripeException
     */
    public function handle(array $event): ?Subscription
    {
        if (($event['livemode'] ?? null) !== $this->stripe->live()) {
            return null;
        }
        $type = \is_string($event['type'] ?? null) ? $event['type'] : '';
        $object = \is_array($event['data'] ?? null) && \is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        $id = match (true) {
            str_starts_with($type, 'customer.subscription.') && ($object['object'] ?? null) === 'subscription' => $object['id'] ?? null,
            str_starts_with($type, 'checkout.session.') && ($object['object'] ?? null) === 'checkout.session' => $object['subscription'] ?? null,
            str_starts_with($type, 'invoice.') && ($object['object'] ?? null) === 'invoice' => $object['subscription']
                ?? (\is_array($object['parent'] ?? null) && \is_array($object['parent']['subscription_details'] ?? null) ? ($object['parent']['subscription_details']['subscription'] ?? null) : null),
            default => null,
        };
        if (\is_array($id)) {
            $id = $id['id'] ?? null;
        }

        return \is_string($id) && preg_match('/^sub_[A-Za-z0-9]{6,250}\z/', $id) === 1 ? $this->sync($id) : null;
    }

    /**
     * Keeps a subscription object Stripe answered with, asked at $syncedAt.
     *
     * @param array<string, mixed> $object
     */
    private function keep(array $object, CarbonImmutable $syncedAt, string $asked): ?Subscription
    {
        if (($object['object'] ?? null) !== 'subscription' || ($object['id'] ?? null) !== $asked) {
            throw new StripeException("Stripe answered for subscription {$asked} with something else.");
        }
        $customer = \is_array($object['customer'] ?? null) ? ($object['customer']['id'] ?? null) : ($object['customer'] ?? null);
        if (!\is_string($customer)) {
            return null;
        }
        $billable = $this->store->billableOf($customer, $this->billables->kind());
        if ($billable === null) {
            return null;
        }

        $items = \is_array($object['items'] ?? null) && \is_array($object['items']['data'] ?? null) ? $object['items']['data'] : [];
        $item = \is_array($items[0] ?? null) ? $items[0] : [];
        $price = \is_array($item['price'] ?? null) ? $item['price'] : [];
        $lookupKey = \is_string($price['lookup_key'] ?? null) ? $price['lookup_key'] : null;
        $status = SubscriptionStatus::tryFrom(\is_string($object['status'] ?? null) ? $object['status'] : '')
            ?? throw new StripeException("Stripe answered subscription {$asked} with a status billing does not know.");

        $previous = $this->store->find($asked, $this->plans);
        $pastDueSince = !$status->isPastDue() ? null
            : ($previous !== null && $previous->status->isPastDue() && $previous->pastDueSince !== null ? $previous->pastDueSince : $syncedAt);

        $subscription = new Subscription(
            id: $asked,
            billable: $billable,
            customer: $customer,
            status: $status,
            plan: $this->plans->forLookupKey($lookupKey),
            price: $lookupKey,
            item: \is_string($item['id'] ?? null) ? $item['id'] : null,
            quantity: \is_int($item['quantity'] ?? null) ? $item['quantity'] : 1,
            trialEndsAt: self::at($object['trial_end'] ?? null),
            currentPeriodStart: self::at($item['current_period_start'] ?? $object['current_period_start'] ?? null),
            currentPeriodEnd: self::at($item['current_period_end'] ?? $object['current_period_end'] ?? null),
            cancelAtPeriodEnd: ($object['cancel_at_period_end'] ?? false) === true,
            canceledAt: self::at($object['canceled_at'] ?? null),
            endedAt: self::at($object['ended_at'] ?? null),
            pastDueSince: $pastDueSince,
            syncedAt: $syncedAt,
        );
        $this->store->keep($subscription);

        return $this->store->find($asked, $this->plans);
    }

    /** The Stripe customer of $billable, made and kept the first time it is asked for. */
    private function customerFor(Billable $billable): string
    {
        $kept = $this->store->customerOf($billable);
        if ($kept !== null) {
            return $kept;
        }

        // The same key for every first try for this payer, so two checkouts
        // started at once make one customer -- in this environment alone.
        // Two on one Stripe account -- staging and production, or two
        // developers' -- each bill their own tenant acme, and a key that
        // was the payer's alone handed the second the first's customer,
        // whose subscriptions the first then heard of (StripeClient::scope()).
        $customer = $this->stripe->post('/v1/customers', [
            'metadata' => ['tinvar_billable' => $billable->key()],
        ], 'tinvar-customer-' . $this->stripe->scope() . '-' . hash('sha256', $billable->key()));
        $id = $customer['id'] ?? null;
        if (!\is_string($id) || preg_match('/^cus_[A-Za-z0-9]{6,250}\z/', $id) !== 1) {
            throw new StripeException('Stripe answered the new customer with no cus_ id.');
        }

        return $this->store->keepCustomer($billable, $id);
    }

    /** The id of the active price with $lookupKey, asked once a worker. */
    private function priceId(string $lookupKey): string
    {
        if (isset($this->priceIds[$lookupKey])) {
            return $this->priceIds[$lookupKey];
        }
        $found = $this->stripe->get('/v1/prices', ['lookup_keys' => [$lookupKey], 'active' => 'true', 'limit' => 1]);
        $price = \is_array($found['data'] ?? null) && \is_array($found['data'][0] ?? null) ? $found['data'][0] : [];
        $id = $price['id'] ?? null;
        if (!\is_string($id) || ($price['lookup_key'] ?? null) !== $lookupKey) {
            throw new StripeException("Stripe has no active price with the lookup key '{$lookupKey}': give one that key in the dashboard, under Product catalog.");
        }

        return $this->priceIds[$lookupKey] = $id;
    }

    private function requirePayer(): Billable
    {
        return $this->payer() ?? throw self::noPayer($this->billables->kind());
    }

    /** The refusal of a request with nobody to bill. */
    public static function noPayer(BillableKind $kind): HttpException
    {
        return $kind === BillableKind::User
            ? new HttpException(401, 'Sign in to choose or use a plan.', 'unauthenticated')
            : new HttpException(403, 'This request runs as no tenant, so there is no plan to check or change.', 'no_tenant');
    }

    /** What Stripe's client_reference_id carries: the payer's key, as Stripe allows it (200 characters of a-z, 0-9, _ and -). */
    private static function reference(Billable $billable): string
    {
        $plain = $billable->kind->value . '_' . preg_replace('/[^A-Za-z0-9_-]/', '-', $billable->id);

        return \strlen($plain) <= 200 ? $plain : $billable->kind->value . '_' . hash('sha256', $billable->id);
    }

    /**
     * A hosted page's URL from Stripe's answer, held to the origin it is
     * served from: an answer that names another is refused, never followed.
     *
     * @param array<string, mixed> $session
     */
    private static function hosted(array $session, string $origin, string $what): string
    {
        $url = $session['url'] ?? null;
        if (!\is_string($url) || !str_starts_with($url, $origin . '/')) {
            throw new StripeException("Stripe answered {$what} with no URL on {$origin}.");
        }

        return $url;
    }

    /** An absolute http(s) URL with a host, as Stripe needs a return URL. */
    private static function ownUrl(string $url, string $what): string
    {
        $parts = parse_url($url);
        if ($parts === false || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException("The {$what} URL '{$url}' is not an absolute http or https URL: build it from your own origin, as Urls does.");
        }

        return $url;
    }

    private static function assertSeats(int $seats): void
    {
        if ($seats < 1 || $seats > 10_000) {
            throw new HttpException(422, 'A subscription has 1 to 10,000 seats.', 'seats_out_of_range');
        }
    }

    private static function assertId(string $id, string $prefix): void
    {
        if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9]{6,250}\z/', $id) !== 1) {
            throw new \InvalidArgumentException("'{$id}' is not a Stripe {$prefix}... id.");
        }
    }

    private static function at(mixed $timestamp): ?CarbonImmutable
    {
        return \is_int($timestamp) && $timestamp > 0 ? CarbonImmutable::createFromTimestamp($timestamp, 'UTC') : null;
    }

    private function now(): CarbonImmutable
    {
        return $this->clock === null ? CarbonImmutable::now('UTC') : ($this->clock)();
    }
}
