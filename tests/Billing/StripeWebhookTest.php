<?php

declare(strict_types=1);

/**
 * Stripe's webhook through the framework's webhook endpoint and the real
 * HTTP Kernel (ADR 0150): Stripe's signature (t=, v1=, five minutes either
 * way) checked before anything runs; the event id the nonce, so a replay is
 * a 409; and the state kept always what Stripe answers when asked, never
 * what an event's body says, so events late, repeated or out of order leave
 * what Stripe holds. A customer is a payer's only by the row the app wrote.
 */

namespace Tinvar\Tests\Billing\StripeWebhook;

use Tinvar\Billing\Billable;
use Tinvar\Billing\BillableKind;
use Tinvar\Billing\Fakes\FakeStripe;
use Tinvar\Billing\Stripe\StripeSignatureHeader;
use Tinvar\Billing\SubscriptionStatus;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Tests\Billing\Fixtures\App;
use Tinvar\Tests\Billing\Fixtures\Clock;
use Tinvar\Tests\Billing\Fixtures\Plan;

require_once __DIR__ . '/../Fixtures/app.php';

function acme(): Billable
{
    return new Billable(BillableKind::Tenant, 'acme');
}

beforeEach(function () {
    Clock::$now = 1_800_000_000;
});

it('keeps the subscription Stripe holds once a signed event names it', function () {
    $app = new App();
    $app->orm->actAs('acme', fn (): string => $app->billing->checkout(Plan::Team, \Tinvar\Billing\Interval::Month, 'https://x.test/ok', 'https://x.test/back'));
    $subscription = $app->stripe->completeCheckout($app->stripe->lastSession());

    $response = $app->deliver($app->stripe->event('customer.subscription.created', $subscription));

    $kept = $app->billing->subscription(acme());
    expect($response->status)->toBe(204)
        ->and($kept?->id)->toBe($subscription['id'])
        ->and($kept?->plan)->toBe(Plan::Team)
        ->and($kept?->status)->toBe(SubscriptionStatus::Active)
        ->and($kept?->quantity)->toBe(1)
        ->and($kept?->currentPeriodEnd?->getTimestamp())->toBe(Clock::$now + 30 * 86_400);
});

it('refuses a delivery whose signature does not verify, and keeps nothing', function (\Closure $tamper) {
    $app = new App();
    $app->orm->actAs('acme', fn (): string => $app->billing->checkout(Plan::Team, \Tinvar\Billing\Interval::Month, 'https://x.test/ok', 'https://x.test/back'));
    $subscription = $app->stripe->completeCheckout($app->stripe->lastSession());
    [$request, $body] = $app->stripe->delivery($app->stripe->event('customer.subscription.created', $subscription));
    [$request, $body] = $tamper($request, $body);

    $response = $app->kernel->handle($request, static fn (): Response => Response::make(500), $body);

    expect($response->status)->toBe(401)
        ->and($app->billing->subscription(acme()))->toBeNull();
})->with([
    'signed with another secret' => [fn (Request $r, string $b): array => [new Request('POST', $r->path, [...$r->headers(), StripeSignatureHeader::HEADER => StripeSignatureHeader::header('whsec_SomeoneElsesSecret000000', Clock::$now, $b)]), $b]],
    'a body changed after signing' => [fn (Request $r, string $b): array => [$r, str_replace('"active"', '"trialing"', $b)]],
    'no signature' => [fn (Request $r, string $b): array => [new Request('POST', $r->path, ['content-type' => 'application/json']), $b]],
    'a v0 signature alone' => [fn (Request $r, string $b): array => [new Request('POST', $r->path, [StripeSignatureHeader::HEADER => 't=' . Clock::$now . ',v0=' . hash_hmac('sha256', Clock::$now . ".{$b}", FakeStripe::WEBHOOK_SECRET)]), $b]],
    'Tinvar\'s own header in place of Stripe\'s' => [fn (Request $r, string $b): array => [new Request('POST', $r->path, ['tinvar-signature' => 't=1,n=0123456789abcdef,v1=' . str_repeat('a', 64)]), $b]],
]);

it('takes any v1 that verifies, as while Stripe rolls a secret', function () {
    $app = new App();
    $event = $app->stripe->event('customer.created', ['object' => 'customer', 'id' => 'cus_00000000000001']);
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $header = 't=' . Clock::$now . ',v1=' . str_repeat('0', 64) . ',v1=' . StripeSignatureHeader::mac(FakeStripe::WEBHOOK_SECRET, Clock::$now, $body);

    $response = $app->kernel->handle(new Request('POST', '/webhooks/stripe', [StripeSignatureHeader::HEADER => $header]), static fn (): Response => Response::make(500), $body);

    expect($response->status)->toBe(204);
});

it('refuses a delivery signed more than five minutes from now, either way', function (int $offset) {
    $app = new App();
    $event = $app->stripe->event('customer.created', ['object' => 'customer', 'id' => 'cus_00000000000001']);

    expect($app->deliver($event, timestamp: Clock::$now + $offset)->status)->toBe(401)
        ->and($app->deliver($event, timestamp: Clock::$now + ($offset > 0 ? 300 : -300))->status)->toBe(204);
})->with(['too old' => -301, 'from the future' => 301]);

it('refuses a replay of a delivery: the event id is the nonce, claimed once in the nonce store', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $event = $app->stripe->event('customer.subscription.updated', $app->stripe->subscriptions[$id]);

    expect($app->deliver($event)->status)->toBe(204)
        ->and($app->deliver($event)->status)->toBe(409)
        // Re-signed later, as an attacker holding the body cannot do -- but
        // as Stripe does when it sends the same event again inside the window.
        ->and($app->deliver($event, timestamp: Clock::$now + 60)->status)->toBe(409);
});

it('stores what Stripe answers, not what the event says: a forged-looking body changes nothing', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Pro);
    $app->stripe->change($id, ['status' => 'canceled', 'ended_at' => Clock::$now]);

    // The event's body says active on Team; Stripe, asked, says canceled.
    $lie = [...$app->stripe->subscriptions[$id], 'status' => 'active'];
    $lie['items']['data'][0]['price']['lookup_key'] = 'team_monthly';
    $app->deliver($app->stripe->event('customer.subscription.updated', $lie));

    $kept = $app->billing->subscription(acme());
    expect($kept?->status)->toBe(SubscriptionStatus::Canceled)
        ->and($kept?->plan)->toBe(Plan::Pro)
        ->and($app->visit('/reports')->status)->toBe(402);
});

it('leaves the state Stripe holds whatever order the events arrive in', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $older = $app->stripe->event('customer.subscription.updated', $app->stripe->change($id, ['status' => 'past_due']), Clock::$now);
    Clock::$now += 60;
    $app->stripe->change($id, ['status' => 'active', 'item' => ['quantity' => 4]]);
    $newer = $app->stripe->event('customer.subscription.updated', $app->stripe->subscriptions[$id], Clock::$now);

    $app->deliver($newer);
    Clock::$now += 5;
    $app->deliver($older);

    $kept = $app->billing->subscription(acme());
    expect($kept?->status)->toBe(SubscriptionStatus::Active)
        ->and($kept?->quantity)->toBe(4)
        ->and($kept?->pastDueSince)->toBeNull();
});

it('never lets an answer asked of Stripe earlier replace one asked later', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $stale = $app->store->find($id, $app->billing->plans);
    Clock::$now += 60;
    $app->stripe->change($id, ['status' => 'canceled']);
    $app->billing->sync($id);

    expect($app->store->keep($stale))->toBeFalse()
        ->and($app->billing->subscription(acme())?->status)->toBe(SubscriptionStatus::Canceled);
});

it('takes an event about a customer it did not make, and keeps nothing for anyone', function () {
    $app = new App();
    $stranger = $app->stripe->subscribe('cus_NotOurs000000001', 'team_monthly');

    $response = $app->deliver($app->stripe->event('customer.subscription.created', $stranger));

    expect($response->status)->toBe(204)
        ->and($app->store->find((string) $stranger['id'], $app->billing->plans))->toBeNull();
});

it('writes a subscription for the tenant whose customer it is, never another, whatever its metadata claims', function () {
    $app = new App();
    $acme = $app->subscribe('acme', Plan::Team);
    $app->stripe->change($acme, ['metadata' => ['tinvar_billable' => 'tenant:globex']]);

    $app->deliver($app->stripe->event('customer.subscription.updated', $app->stripe->subscriptions[$acme]));

    expect($app->billing->subscription(new Billable(BillableKind::Tenant, 'globex')))->toBeNull()
        ->and($app->billing->subscription(acme())?->id)->toBe($acme)
        ->and($app->visit('/reports', 'globex.app.test')->status)->toBe(402);
});

it('names the subscription from an invoice, in the shape the pinned API version sends', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $app->stripe->change($id, ['status' => 'past_due']);

    $app->deliver($app->stripe->event('invoice.payment_failed', ['object' => 'invoice', 'id' => 'in_00000000000002', 'parent' => ['subscription_details' => ['subscription' => $id]]]));

    $kept = $app->billing->subscription(acme());
    expect($kept?->status)->toBe(SubscriptionStatus::PastDue)
        ->and($kept?->pastDueSince?->getTimestamp())->toBe(Clock::$now);
});

it('ignores an event of the other mode: a live event to a test-mode key', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $app->stripe->change($id, ['status' => 'canceled']);

    $app->deliver([...$app->stripe->event('customer.subscription.deleted', $app->stripe->subscriptions[$id]), 'livemode' => true]);

    expect($app->billing->subscription(acme())?->status)->toBe(SubscriptionStatus::Active);
});

it('answers 500 and releases the nonce when Stripe cannot be asked, so Stripe\'s retry is taken', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $event = $app->stripe->event('customer.subscription.updated', $app->stripe->subscriptions[$id]);
    $gone = $app->stripe->subscriptions[$id];
    unset($app->stripe->subscriptions[$id]);

    expect($app->deliver($event)->status)->toBe(500);

    $app->stripe->subscriptions[$id] = $gone;
    expect($app->deliver($event, timestamp: Clock::$now + 30)->status)->toBe(204);
});

it('is not set up without a webhook secret: every delivery is a 404', function () {
    $app = new App(stripe: new FakeStripe(\Tinvar\Tests\Billing\Fixtures\PRICES));
    $billing = new \Tinvar\Billing\Billing(Plan::class, new \Tinvar\Billing\TenantBillables($app->orm), $app->store, new \Tinvar\Billing\Stripe\StripeClient(new \Tinvar\Config\Secret(FakeStripe::SECRET_KEY), $app->stripe->http));
    $verifier = new \Tinvar\Billing\Http\StripeSignature($billing);
    [$request, $body] = $app->stripe->delivery($app->stripe->event('customer.created', ['object' => 'customer']));

    expect(fn () => $verifier->verify(\Tinvar\Tests\Billing\Fixtures\StripeEvents::class, '', $request, $body, Clock::$now))
        ->toThrow(fn (\Tinvar\Http\HttpException $e) => expect($e->status)->toBe(404));
});
