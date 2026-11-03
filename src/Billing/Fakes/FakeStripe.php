<?php

declare(strict_types=1);

namespace Tinvar\Billing\Fakes;

use Tinvar\Billing\Stripe\StripeClient;
use Tinvar\Billing\Stripe\StripeSignatureHeader;
use Tinvar\Config\Secret;
use Tinvar\Http\Request;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;

/**
 * A Stripe of the test's own, answering the calls billing makes through a
 * FakeOutboundHttp, connecting to nothing: customers, prices by lookup key,
 * Checkout and Customer Portal sessions, and subscriptions, held in memory
 * and answered in the shapes Stripe's API version StripeClient::VERSION
 * answers with (ADR 0150).
 *
 *     $stripe = new FakeStripe(prices: ['pro_monthly' => 'price_pro_m']);
 *     $billing = new Billing(Plan::class, $billables, new ArraySubscriptions(), $stripe->client());
 *     $url = $billing->checkout(Plan::Pro, Interval::Month, 'https://app.test/ok', 'https://app.test/back');
 *     $subscription = $stripe->completeCheckout($stripe->lastSession());     // the customer paid
 *     [$request, $body] = $stripe->delivery($stripe->event('customer.subscription.created', $subscription));
 *
 * A call without the key it was made with, or naming another API version,
 * is refused as Stripe refuses it; a POST repeating an Idempotency-Key is
 * answered as the first was. What was sent is in `$http->sent`.
 */
final class FakeStripe
{
    public const string SECRET_KEY = 'sk_test_FakeStripeKey0123456789';

    public const string WEBHOOK_SECRET = 'whsec_FakeStripeWebhookSecret0123456789';

    public readonly FakeOutboundHttp $http;

    /** @var array<string, array<string, mixed>> */
    public array $customers = [];

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    /** @var array<string, OutboundResponse> idempotency key => the answer it had */
    private array $answered = [];

    private int $sequence = 0;

    /**
     * @param array<string, string> $prices lookup key => price id, every price active
     * @param int $now what the fake's clock says, seconds since the epoch
     */
    public function __construct(public array $prices = [], public int $now = 1_800_000_000)
    {
        $this->http = new FakeOutboundHttp(fn (OutboundRequest $request): OutboundResponse => $this->answer($request));
    }

    /** A StripeClient for this fake, with its key and webhook secret. */
    public function client(): StripeClient
    {
        return new StripeClient(new Secret(self::SECRET_KEY), $this->http, new Secret(self::WEBHOOK_SECRET));
    }

    /** @return array<string, mixed> the latest Checkout session made */
    public function lastSession(): array
    {
        return $this->sessions[array_key_last($this->sessions) ?? ''] ?? throw new \LogicException('No Checkout session has been made.');
    }

    /**
     * The customer pays at Checkout: the session's subscription is made, as
     * Stripe makes it, trialing for the session's trial days or active.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed> the subscription
     */
    public function completeCheckout(array $session): array
    {
        $form = $session['_form'];
        \assert(\is_array($form));
        $line = $form['line_items'][0] ?? [];
        $trialDays = (int) ($form['subscription_data']['trial_period_days'] ?? 0);
        $subscription = $this->subscribe((string) $session['customer'], (string) array_search($line['price'] ?? '', $this->prices, true), (int) ($line['quantity'] ?? 1), $trialDays);
        $this->sessions[(string) $session['id']]['subscription'] = $subscription['id'];
        $this->sessions[(string) $session['id']]['status'] = 'complete';

        return $subscription;
    }

    /**
     * A subscription for $customer to the price with $lookupKey, as if made
     * in Stripe's dashboard.
     *
     * @return array<string, mixed>
     */
    public function subscribe(string $customer, string $lookupKey, int $quantity = 1, int $trialDays = 0, int $periodDays = 30): array
    {
        $id = $this->id('sub');
        $start = $this->now;
        $trialEnd = $trialDays > 0 ? $start + $trialDays * 86_400 : null;
        $end = $trialEnd ?? $start + $periodDays * 86_400;
        $this->subscriptions[$id] = [
            'id' => $id,
            'object' => 'subscription',
            'customer' => $customer,
            'status' => $trialEnd === null ? 'active' : 'trialing',
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'ended_at' => null,
            'trial_end' => $trialEnd,
            'livemode' => false,
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => $this->id('si'),
                'object' => 'subscription_item',
                'quantity' => $quantity,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'price' => ['id' => $this->prices[$lookupKey] ?? 'price_unknown', 'object' => 'price', 'lookup_key' => $lookupKey === '' ? null : $lookupKey],
            ]]],
        ];

        return $this->subscriptions[$id];
    }

    /**
     * Changes a subscription as Stripe would: `['status' => 'past_due']`,
     * `['cancel_at_period_end' => true]`; an item field -- quantity, the
     * period -- under `item`.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function change(string $id, array $changes): array
    {
        $subscription = $this->subscriptions[$id] ?? throw new \LogicException("The fake has no subscription {$id}.");
        $item = \is_array($changes['item'] ?? null) ? $changes['item'] : [];
        unset($changes['item']);
        $subscription = [...$subscription, ...$changes];
        $subscription['items']['data'][0] = [...$subscription['items']['data'][0], ...$item];

        return $this->subscriptions[$id] = $subscription;
    }

    /**
     * An event about $object, as Stripe sends it.
     *
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    public function event(string $type, array $object, ?int $created = null): array
    {
        return [
            'id' => $this->id('evt'),
            'object' => 'event',
            'api_version' => StripeClient::VERSION,
            'created' => $created ?? $this->now,
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $object],
        ];
    }

    /**
     * A delivery of $event to $path, signed with the webhook secret at the
     * fake's clock -- or $secret, $timestamp -- as Stripe signs it.
     *
     * @param array<string, mixed> $event
     * @return array{Request, string} the request and its raw body
     */
    public function delivery(array $event, string $path = '/webhooks/stripe', ?string $secret = null, ?int $timestamp = null): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [new Request('POST', $path, [
            'content-type' => 'application/json; charset=utf-8',
            StripeSignatureHeader::HEADER => StripeSignatureHeader::header($secret ?? self::WEBHOOK_SECRET, $timestamp ?? $this->now, $body),
        ]), $body];
    }

    private function answer(OutboundRequest $request): OutboundResponse
    {
        if (($request->headers['authorization'] ?? null) !== 'Bearer ' . self::SECRET_KEY) {
            return self::error(401, 'Invalid API Key provided.', 'api_key_invalid');
        }
        if (($request->headers['stripe-version'] ?? null) !== StripeClient::VERSION) {
            return self::error(400, 'Invalid Stripe API version.', 'api_version_invalid');
        }
        $idempotency = $request->headers['idempotency-key'] ?? null;
        if ($request->method === 'POST' && $idempotency !== null && isset($this->answered[$idempotency])) {
            return $this->answered[$idempotency];
        }

        $url = parse_url($request->url);
        $path = (string) ($url['path'] ?? '');
        parse_str((string) ($url['query'] ?? ''), $query);
        parse_str($request->body, $form);
        $response = $this->route($request->method, $path, $query, $form);
        if ($request->method === 'POST' && $idempotency !== null) {
            $this->answered[$idempotency] = $response;
        }

        return $response;
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $form
     */
    private function route(string $method, string $path, array $query, array $form): OutboundResponse
    {
        if ($method === 'POST' && $path === '/v1/customers') {
            $id = $this->id('cus');
            $this->customers[$id] = ['id' => $id, 'object' => 'customer', 'metadata' => $form['metadata'] ?? []];

            return self::json($this->customers[$id]);
        }
        if ($method === 'GET' && $path === '/v1/prices') {
            $keys = \is_array($query['lookup_keys'] ?? null) ? $query['lookup_keys'] : [];
            $data = [];
            foreach ($keys as $key) {
                if (isset($this->prices[$key])) {
                    $data[] = ['id' => $this->prices[$key], 'object' => 'price', 'lookup_key' => $key, 'active' => true];
                }
            }

            return self::json(['object' => 'list', 'data' => $data, 'has_more' => false]);
        }
        if ($method === 'POST' && $path === '/v1/checkout/sessions') {
            if (!isset($this->customers[$form['customer'] ?? ''])) {
                return self::error(400, 'No such customer.', 'resource_missing');
            }
            $id = $this->id('cs_test');
            $this->sessions[$id] = [
                'id' => $id,
                'object' => 'checkout.session',
                'mode' => 'subscription',
                'customer' => $form['customer'],
                'client_reference_id' => $form['client_reference_id'] ?? null,
                'status' => 'open',
                'subscription' => null,
                'url' => "https://checkout.stripe.com/c/pay/{$id}",
                '_form' => $form,
            ];

            return self::json(array_diff_key($this->sessions[$id], ['_form' => true]));
        }
        if ($method === 'POST' && $path === '/v1/billing_portal/sessions') {
            if (!isset($this->customers[$form['customer'] ?? ''])) {
                return self::error(400, 'No such customer.', 'resource_missing');
            }

            return self::json(['id' => $this->id('bps'), 'object' => 'billing_portal.session', 'customer' => $form['customer'], 'url' => 'https://billing.stripe.com/p/session/' . $this->id('test'), 'return_url' => $form['return_url'] ?? null]);
        }
        if (preg_match('#^/v1/subscriptions/(sub_[A-Za-z0-9]+)\z#', $path, $m) === 1) {
            if (!isset($this->subscriptions[$m[1]])) {
                return self::error(404, "No such subscription: '{$m[1]}'", 'resource_missing');
            }
            if ($method === 'POST') {
                $quantity = $form['items'][0]['quantity'] ?? null;
                if ($quantity !== null) {
                    $this->change($m[1], ['item' => ['quantity' => (int) $quantity]]);
                }
                // Ends the trial now, as Stripe does: the subscription is
                // active, its first paid period starting now.
                if (($form['trial_end'] ?? null) === 'now' && ($this->subscriptions[$m[1]]['status'] ?? null) === 'trialing') {
                    $this->change($m[1], [
                        'status' => 'active',
                        'trial_end' => $this->now,
                        'item' => ['current_period_start' => $this->now, 'current_period_end' => $this->now + 30 * 86_400],
                    ]);
                }
            }

            return self::json($this->subscriptions[$m[1]]);
        }

        return self::error(404, "Unrecognized request URL ({$method}: {$path}).", 'resource_missing');
    }

    private function id(string $prefix): string
    {
        return $prefix . '_' . str_pad((string) ++$this->sequence, 14, '0', STR_PAD_LEFT) . 'Fk';
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): OutboundResponse
    {
        return new OutboundResponse($status, ['content-type' => ['application/json']], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function error(int $status, string $message, string $code): OutboundResponse
    {
        return self::json(['error' => ['type' => 'invalid_request_error', 'code' => $code, 'message' => $message]], $status);
    }
}
