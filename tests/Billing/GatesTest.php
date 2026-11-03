<?php

declare(strict_types=1);

/**
 * #[RequiresPlan] and #[RequiresFeature] on pages, layouts and actions,
 * through the real HTTP Kernel: the payer in force -- the tenant the host
 * places, or the signed-in user -- entitled now, or 402 before anything
 * runs; nobody to bill, 401 or 403; and Billing not registered, refused
 * outright (ADR 0150).
 */

namespace Tinvar\Tests\Billing\Gates;

use Tinvar\Billing\Billable;
use Tinvar\Billing\BillableKind;
use Tinvar\Tests\Billing\Fixtures\App;
use Tinvar\Tests\Billing\Fixtures\Clock;
use Tinvar\Tests\Billing\Fixtures\Plan;

require_once __DIR__ . '/../Fixtures/app.php';

const DAY = 86_400;

beforeEach(function () {
    Clock::$now = 1_800_000_000;
});

it('refuses a gated page to a tenant with no plan, with a 402 that says what to do', function () {
    $app = new App();
    $response = $app->visit('/reports');

    expect($response->status)->toBe(402)
        ->and($response->body())->toContain('plan_required');
});

it('lets in a tenant once Stripe has told the app it subscribed, and keeps every other tenant out', function () {
    $app = new App();
    $app->subscribe('acme', Plan::Team);

    expect($app->visit('/reports')->status)->toBe(200)
        ->and($app->visit('/reports', 'globex.app.test')->status)->toBe(402)
        ->and($app->visit('/team/members')->status)->toBe(200)
        ->and($app->visit('/team/members', 'globex.app.test')->status)->toBe(402);
});

it('gates by feature: a plan without it is refused, on a layout and every page under it', function () {
    $app = new App();
    $app->subscribe('acme', Plan::Pro);

    expect($app->visit('/reports')->status)->toBe(200)
        ->and($app->visit('/team/members')->status)->toBe(402)
        ->and($app->act('reports.export')->status)->toBe(200)
        ->and($app->act('reports.export', host: 'globex.app.test')->status)->toBe(402);
});

it('answers the pricing page with the plan in force, the free plan for a tenant with none', function () {
    $app = new App();
    $app->subscribe('acme', Plan::Pro);

    expect($app->visit('/pricing')->body())->toContain('"plan":"pro"')
        ->and($app->visit('/pricing', 'globex.app.test')->body())->toContain('"plan":"free"');
});

it('holds a trialing subscription until its trial ends, and the grace after', function () {
    $app = new App();
    $app->subscribe('acme', Plan::Pro);
    $subscription = $app->billing->subscription(new Billable(BillableKind::Tenant, 'acme'));
    expect($subscription?->status->value)->toBe('trialing');

    Clock::$now += 14 * DAY + 2 * DAY;
    expect($app->visit('/reports')->status)->toBe(200);

    Clock::$now += 2 * DAY;
    expect($app->visit('/reports')->status)->toBe(402);
});

it('keeps a past-due subscription entitled for the grace period, then refuses', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $app->deliver($app->stripe->event('invoice.payment_failed', ['object' => 'invoice', 'id' => 'in_00000001', 'subscription' => $app->stripe->change($id, ['status' => 'past_due'])['id']]));

    Clock::$now += 2 * DAY;
    expect($app->visit('/reports')->status)->toBe(200);

    Clock::$now += 2 * DAY;
    expect($app->visit('/reports')->status)->toBe(402);
});

it('keeps a subscription set to cancel until its period ends, and refuses once Stripe says it is canceled', function () {
    $app = new App();
    $id = $app->subscribe('acme', Plan::Team);
    $app->stripe->change($id, ['cancel_at_period_end' => true]);
    $app->deliver($app->stripe->event('customer.subscription.updated', $app->stripe->subscriptions[$id]));
    expect($app->visit('/reports')->status)->toBe(200);

    $app->stripe->change($id, ['status' => 'canceled', 'ended_at' => Clock::$now]);
    $app->deliver($app->stripe->event('customer.subscription.deleted', $app->stripe->subscriptions[$id]));
    expect($app->visit('/reports')->status)->toBe(402);
});

it('stops entitling an active subscription whose renewal it never heard of, once the grace after its period has passed', function () {
    $app = new App();
    $app->subscribe('acme', Plan::Team);

    Clock::$now += 30 * DAY + 4 * DAY;
    expect($app->visit('/reports')->status)->toBe(402);
});

it('refuses a gated page to a request with no tenant in force', function () {
    $app = new App();

    // No host places it: the tenant source refuses before billing is asked.
    expect($app->visit('/reports', 'elsewhere.test')->status)->toBe(404);
});

describe('billed by user', function () {
    it('asks a visitor nobody signed in to sign in, and a signed-in user without the plan to choose one', function () {
        $app = new App(byUser: true);

        expect($app->visit('/me/reports', user: null)->status)->toBe(401)
            ->and($app->visit('/me/reports', user: 'u-1')->status)->toBe(402);
    });

    it('lets in the user who subscribed, and no other', function () {
        $app = new App(byUser: true);
        $app->auth->actingAs(new \Tinvar\Tests\Billing\Fixtures\Member('u-1'), function () use ($app): void {
            $app->billing->checkout(Plan::Pro, \Tinvar\Billing\Interval::Month, 'https://acme.app.test/ok', 'https://acme.app.test/back');
        });
        $app->stripe->completeCheckout($app->stripe->lastSession());
        $app->deliver($app->stripe->event('checkout.session.completed', $app->stripe->sessions[array_key_last($app->stripe->sessions)]));

        expect($app->visit('/me/reports', user: 'u-1')->status)->toBe(200)
            ->and($app->visit('/me/reports', user: 'u-2')->status)->toBe(402);
    });
});

it('fails closed when the application never registered Billing: every gated request is refused, never let past', function () {
    $app = new App(registerBilling: false);

    $response = $app->visit('/reports');

    expect($response->status)->toBe(500)
        ->and($response->body())->not->toContain('reports')
        ->and($app->reported[0]->getMessage())->toContain('could not be built, so the request is refused');
});
