<?php

declare(strict_types=1);

/**
 * Starting Checkout and the Customer Portal, and changing seats, as the
 * calls billing makes to Stripe -- through OutboundHttp, held to
 * api.stripe.com, the key a Secret carried in one header -- answered by a
 * fake Stripe; nothing calls the real one (ADR 0150).
 */

namespace Tinvar\Tests\Billing\Checkout;

use Tinvar\Billing\Billable;
use Tinvar\Billing\BillableKind;
use Tinvar\Billing\Billables;
use Tinvar\Billing\Billing;
use Tinvar\Billing\ArraySubscriptions;
use Tinvar\Billing\Fakes\FakeStripe;
use Tinvar\Billing\Interval;
use Tinvar\Billing\Stripe\StripeClient;
use Tinvar\Billing\Stripe\StripeException;
use Tinvar\Config\Secret;
use Tinvar\Http\HttpException;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;
use Tinvar\Tests\Billing\Fixtures\App;
use Tinvar\Tests\Billing\Fixtures\Clock;
use Tinvar\Tests\Billing\Fixtures\Plan;

require_once __DIR__ . '/../Fixtures/app.php';

beforeEach(function () {
    Clock::$now = 1_800_000_000;
});

/** @return array<array-key, mixed> */
function form(OutboundRequest $request): array
{
    parse_str($request->body, $form);

    return $form;
}

it('starts Checkout for the tenant in force and sends the browser to Stripe from an action', function () {
    $app = new App();

    $response = $app->act('billing.checkout', ['plan' => 'pro']);

    $session = $app->stripe->lastSession();
    expect($response->status)->toBe(303)
        ->and($response->header('location'))->toBe($session['url'])
        ->and($session['customer'])->toBe($app->store->customerOf(new Billable(BillableKind::Tenant, 'acme')))
        ->and($session['client_reference_id'])->toBe('tenant_acme')
        ->and($session['_form']['line_items'][0])->toBe(['price' => 'price_ProMonthly01', 'quantity' => '1'])
        ->and($session['_form']['subscription_data'])->toBe(['metadata' => ['tinvar_billable' => 'tenant:acme'], 'trial_period_days' => '14'])
        ->and($session['_form']['mode'])->toBe('subscription');
});

// A trial is a payer's first subscription's. Offered on every Checkout, a
// payer could subscribe, cancel before the trial ended, and subscribe
// again: the plan, for ever, never charged.
it('offers the trial to a payer who never subscribed, and to no one again', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Pro);
    $app->stripe->change($id, ['status' => 'canceled', 'ended_at' => Clock::$now]);
    $app->deliver($app->stripe->event('customer.subscription.deleted', $app->stripe->subscriptions[$id]));

    $app->act('billing.checkout', ['plan' => 'pro']);
    $again = $app->stripe->lastSession();
    $app->act('billing.checkout', ['plan' => 'pro'], host: 'globex.app.test');
    $first = $app->stripe->lastSession();

    expect($again['_form']['subscription_data'])->toBe(['metadata' => ['tinvar_billable' => 'tenant:acme']])
        ->and($first['_form']['subscription_data']['trial_period_days'] ?? null)->toBe('14');
});

it('makes one Stripe customer per payer, and another for another tenant', function () {
    $app = new App();
    $app->act('billing.checkout');
    $app->act('billing.checkout');
    $app->act('billing.checkout', host: 'globex.app.test');

    expect($app->stripe->customers)->toHaveCount(2)
        ->and(array_column(array_column($app->stripe->customers, 'metadata'), 'tinvar_billable'))->toBe(['tenant:acme', 'tenant:globex']);
});

// Two environments -- staging and production, or two developers' -- on one
// Stripe account each bill a tenant called acme. Had the customer's
// Idempotency-Key been the payer's alone, the second would have been handed
// the first's customer, and its subscriptions heard by the first.
it('makes each environment on one Stripe account a customer of its own, never another\'s', function () {
    $stripe = new FakeStripe(\Tinvar\Tests\Billing\Fixtures\PRICES);
    $acme = new class implements Billables {
        public function kind(): BillableKind
        {
            return BillableKind::Tenant;
        }

        public function current(): ?Billable
        {
            return new Billable(BillableKind::Tenant, 'acme');
        }
    };
    $environment = static function (string $key, string $webhookSecret) use ($stripe, $acme): Billing {
        return new Billing(Plan::class, $acme, new ArraySubscriptions(), new StripeClient(new Secret($key), $stripe->http, new Secret($webhookSecret)));
    };
    $checkout = static fn (Billing $billing): string => $billing->checkout(Plan::Pro, Interval::Month, 'https://acme.app.test/ok', 'https://acme.app.test/back');

    $checkout($environment(FakeStripe::SECRET_KEY, FakeStripe::WEBHOOK_SECRET));
    $checkout($environment(FakeStripe::SECRET_KEY, 'whsec_StagingEndpointSecret0123456789'));
    $checkout($environment(FakeStripe::SECRET_KEY, 'whsec_PreviewEndpointSecret01234567890'));
    $checkout($environment(FakeStripe::SECRET_KEY, FakeStripe::WEBHOOK_SECRET));

    $keys = array_values(array_map(
        static fn (OutboundRequest $r): string => $r->headers['idempotency-key'] ?? '',
        array_filter($stripe->http->sent, static fn (OutboundRequest $r): bool => str_ends_with($r->url, '/v1/customers')),
    ));
    expect($stripe->customers)->toHaveCount(3)
        ->and(array_unique(\array_slice($keys, 0, 3)))->toHaveCount(3)
        // The same environment again asks with the key it asked with.
        ->and($keys[3])->toBe($keys[0])
        ->and($keys[0])->toStartWith('tinvar-customer-test-')
        ->and($keys[0])->not->toContain('acme');
});

it('sends every call to api.stripe.com alone, with the key in the Authorization header, the API version pinned, and a POST idempotent', function () {
    $app = new App();
    $app->act('billing.checkout');

    foreach ($app->stripe->http->sent as $request) {
        expect(parse_url($request->url, PHP_URL_HOST))->toBe('api.stripe.com')
            ->and(parse_url($request->url, PHP_URL_SCHEME))->toBe('https')
            ->and($request->headers['authorization'])->toBe('Bearer ' . FakeStripe::SECRET_KEY)
            ->and($request->headers['stripe-version'])->toBe(StripeClient::VERSION)
            ->and($request->maxRedirects)->toBe(0)
            ->and($request->body)->not->toContain(FakeStripe::SECRET_KEY);
        if ($request->method === 'POST') {
            expect($request->headers['idempotency-key'] ?? '')->toStartWith('tinvar-');
        }
    }
    expect(array_map(static fn (OutboundRequest $r): string => $r->method . ' ' . parse_url($r->url, PHP_URL_PATH), $app->stripe->http->sent))
        ->toBe(['POST /v1/customers', 'GET /v1/prices', 'POST /v1/checkout/sessions']);
});

it('asks Stripe for a lookup key\'s price once a worker', function () {
    $app = new App();
    $app->act('billing.checkout');
    $app->act('billing.checkout', host: 'globex.app.test');

    $prices = array_filter($app->stripe->http->sent, static fn (OutboundRequest $r): bool => str_contains($r->url, '/v1/prices'));
    expect($prices)->toHaveCount(1);
});

it('refuses a plan not sold at that interval, saying which intervals are', function () {
    $app = new App();

    expect(fn () => $app->orm->actAs('acme', fn () => $app->billing->checkout(Plan::Team, Interval::Year, 'https://x.test/ok', 'https://x.test/back')))
        ->toThrow(fn (HttpException $e) => expect([$e->status, $e->code_])->toBe([422, 'plan_not_sold']));
});

it('refuses a lookup key Stripe has no active price for, saying where to set one', function () {
    $app = new App(stripe: new FakeStripe([]));

    expect(fn () => $app->orm->actAs('acme', fn () => $app->billing->checkout(Plan::Pro, Interval::Month, 'https://x.test/ok', 'https://x.test/back')))
        ->toThrow(StripeException::class, "no active price with the lookup key 'pro_monthly'");
});

it('refuses a return URL that is not absolute', function () {
    $app = new App();

    expect(fn () => $app->orm->actAs('acme', fn () => $app->billing->checkout(Plan::Pro, Interval::Month, '/ok', 'https://x.test/back')))
        ->toThrow(\InvalidArgumentException::class, 'not an absolute http or https URL');
});

it('refuses Checkout with no tenant in force', function () {
    $app = new App();

    expect(fn () => $app->billing->checkout(Plan::Pro, Interval::Month, 'https://x.test/ok', 'https://x.test/back'))
        ->toThrow(fn (HttpException $e) => expect([$e->status, $e->code_])->toBe([403, 'no_tenant']));
});

it('opens the Customer Portal for a payer who checked out, and refuses one who never did', function () {
    $app = new App();
    $app->subscribe('acme');

    $url = $app->orm->actAs('acme', fn (): string => $app->billing->portal('https://acme.app.test/billing'));
    $portal = array_values(array_filter($app->stripe->http->sent, static fn (OutboundRequest $r): bool => str_contains($r->url, 'billing_portal')));

    expect($url)->toStartWith('https://billing.stripe.com/')
        ->and(form($portal[0]))->toBe(['customer' => $app->store->customerOf(new Billable(BillableKind::Tenant, 'acme')), 'return_url' => 'https://acme.app.test/billing'])
        ->and(fn () => $app->orm->actAs('globex', fn () => $app->billing->portal('https://globex.app.test/billing')))
        ->toThrow(fn (HttpException $e) => expect([$e->status, $e->code_])->toBe([409, 'no_billing_account']));
});

it('changes the seats of the payer\'s own subscription, and keeps what Stripe answers', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);

    $changed = $app->orm->actAs('acme', fn () => $app->billing->updateSeats(7));
    $call = array_values(array_filter($app->stripe->http->sent, static fn (OutboundRequest $r): bool => $r->method === 'POST' && str_contains($r->url, "/v1/subscriptions/{$id}")));

    expect($changed->quantity)->toBe(7)
        ->and($app->stripe->subscriptions[$id]['items']['data'][0]['quantity'])->toBe(7)
        ->and(form($call[0]))->toBe(['items' => [['id' => $changed->item, 'quantity' => '7']], 'proration_behavior' => 'create_prorations'])
        ->and(fn () => $app->orm->actAs('globex', fn () => $app->billing->updateSeats(3)))
        ->toThrow(fn (HttpException $e) => expect([$e->status, $e->code_])->toBe([409, 'no_subscription']))
        ->and(fn () => $app->orm->actAs('acme', fn () => $app->billing->updateSeats(0)))
        ->toThrow(fn (HttpException $e) => expect($e->status)->toBe(422));
});

it('refuses a hosted page Stripe answers on another origin, rather than sending a browser there', function () {
    $http = new FakeOutboundHttp(static fn (OutboundRequest $r): OutboundResponse => new OutboundResponse(200, [], json_encode(match (true) {
        str_contains($r->url, '/v1/customers') => ['id' => 'cus_00000000000001', 'object' => 'customer'],
        str_contains($r->url, '/v1/prices') => ['data' => [['id' => 'price_1', 'lookup_key' => 'pro_monthly']]],
        default => ['id' => 'cs_1', 'url' => 'https://checkout.stripe.com.evil.test/pay'],
    }, JSON_THROW_ON_ERROR)));
    $billing = new \Tinvar\Billing\Billing(Plan::class, new \Tinvar\Billing\TenantBillables($orm = new App()->orm), new \Tinvar\Billing\ArraySubscriptions(), new StripeClient(new Secret(FakeStripe::SECRET_KEY), $http));

    expect(fn () => $orm->actAs('acme', fn () => $billing->checkout(Plan::Pro, Interval::Month, 'https://x.test/ok', 'https://x.test/back')))
        ->toThrow(StripeException::class, 'no URL on https://checkout.stripe.com');
});

describe('the client', function () {
    it('says which variable to set when no key is set, and calls nothing', function () {
        $http = new FakeOutboundHttp();
        $client = new StripeClient(null, $http);

        expect(fn () => $client->get('/v1/subscriptions/sub_000001'))->toThrow(StripeException::class, 'Set TINVAR_STRIPE_SECRET')
            ->and($http->sent)->toBe([]);
    });

    it('cuts the key out of what Stripe answers, and never puts the request in an exception', function () {
        $http = new FakeOutboundHttp(static fn (): OutboundResponse => new OutboundResponse(401, [], json_encode(['error' => ['message' => 'Invalid API Key provided: ' . FakeStripe::SECRET_KEY, 'code' => 'api_key_invalid']], JSON_THROW_ON_ERROR)));
        $client = new StripeClient(new Secret(FakeStripe::SECRET_KEY), $http);

        try {
            $client->get('/v1/subscriptions/sub_000001');
            $this->fail('Stripe refused the key');
        } catch (StripeException $e) {
            expect($e->getMessage())->not->toContain(FakeStripe::SECRET_KEY)
                ->and($e->getMessage())->toContain('Stripe answered 401')
                ->and($e->stripeCode)->toBe('api_key_invalid')
                ->and($e->retryable())->toBeFalse();
        }
    });

    it('refuses a path that is not one of Stripe\'s, and holds every request to api.stripe.com', function () {
        $client = new StripeClient(new Secret(FakeStripe::SECRET_KEY), new FakeOutboundHttp());

        expect(fn () => $client->get('//evil.test/v1/x'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $client->get('/v1/../../x'))->toThrow(\InvalidArgumentException::class);
    });

    it('shows no key in a dump, and the encoder refuses it as it refuses anything not Data', function () {
        $client = new StripeClient(new Secret(FakeStripe::SECRET_KEY), new FakeOutboundHttp(), new Secret(FakeStripe::WEBHOOK_SECRET));
        $app = new App();

        expect(print_r($client, true))->not->toContain(FakeStripe::SECRET_KEY)
            ->and(print_r($client, true))->not->toContain(FakeStripe::WEBHOOK_SECRET)
            ->and(fn () => new \Tinvar\Data\Encoder()->encode($client))->toThrow(\Tinvar\Data\DataBoundaryException::class)
            ->and(fn () => new \Tinvar\Data\Encoder()->encode($app->billing))->toThrow(\Tinvar\Data\DataBoundaryException::class);
    });

    it('refuses a key that is not a Stripe secret key, and a webhook secret that is not a whsec_', function () {
        expect(fn () => new StripeClient(new Secret('pk_test_publishable0000'), new FakeOutboundHttp()))->toThrow(\InvalidArgumentException::class, 'secret key')
            ->and(fn () => new StripeClient(new Secret(FakeStripe::SECRET_KEY), new FakeOutboundHttp(), new Secret('not-a-whsec')))->toThrow(\InvalidArgumentException::class, 'whsec_');
    });

    it('encodes forms as Stripe reads them', function () {
        expect(StripeClient::form(['a' => ['b' => [['c' => 'd e']]], 'on' => true, 'off' => false, 'skip' => null]))
            ->toBe('a%5Bb%5D%5B0%5D%5Bc%5D=d%20e&on=true&off=false');
    });
});
