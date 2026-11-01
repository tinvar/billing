<?php

declare(strict_types=1);

/**
 * What billing refuses when it is set up, so a mistake stops a boot rather
 * than reaching a customer: its settings (none needed in development, both
 * secrets in production), its plan enum, and its webhook declared another
 * way than Stripe's (ADR 0150).
 */

namespace Tinvar\Tests\Billing\Setup;

use Carbon\CarbonImmutable;
use Tinvar\Billing\Attributes\RequiresPlan;
use Tinvar\Billing\Billable;
use Tinvar\Billing\BillableKind;
use Tinvar\Billing\Http\EnforceBilling;
use Tinvar\Billing\Plan as BillingPlan;
use Tinvar\Billing\Plans;
use Tinvar\Billing\StripeConfig;
use Tinvar\Billing\Subscription;
use Tinvar\Billing\SubscriptionStatus;
use Tinvar\Billing\TenantBillables;
use Tinvar\Config\ConfigException;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Outbound\FakeOutboundHttp;
use Tinvar\Tests\Billing\Fixtures\App;
use Tinvar\Tests\Billing\Fixtures\Plan;
use Tinvar\Tests\Billing\Fixtures\StripeEvents;
use Tinvar\Webhooks\WebhookCall;

require_once __DIR__ . '/../Fixtures/app.php';

enum TwoPlansOnePrice: string implements BillingPlan
{
    case A = 'a';
    case B = 'b';

    public function prices(): array
    {
        return ['month' => 'shared_key'];
    }

    public function features(): array
    {
        return [];
    }

    public function trialDays(): int
    {
        return 0;
    }
}

enum WeeklyPlan: string implements BillingPlan
{
    case A = 'a';

    public function prices(): array
    {
        return ['week' => 'weekly'];
    }

    public function features(): array
    {
        return [];
    }

    public function trialDays(): int
    {
        return 0;
    }
}

const KEY = 'sk_test_SetupTestKey000000000';
const WHSEC = 'whsec_SetupTestWebhookSecret0000';

describe('the settings', function () {
    it('boots in development with nothing set: gates read what is kept, and a call says what to set', function () {
        $config = StripeConfig::load([]);

        expect($config->secret)->toBeNull()
            ->and($config->graceDays)->toBe(3)
            ->and($config->client(new FakeOutboundHttp())->live())->toBeFalse();
    });

    it('refuses to boot in production or staging without both secrets, naming the variables and never a value', function (array $marker) {
        expect(fn () => StripeConfig::load($marker))->toThrow(function (ConfigException $e) {
            expect($e->problems)->toHaveCount(2)
                ->and($e->getMessage())->toContain('TINVAR_STRIPE_SECRET (or TINVAR_STRIPE_SECRET_FILE) is required')
                ->and($e->getMessage())->toContain('TINVAR_STRIPE_WEBHOOK_SECRET');
        });
        expect(fn () => StripeConfig::load([...$marker, 'TINVAR_STRIPE_SECRET' => KEY]))->toThrow(ConfigException::class, 'TINVAR_STRIPE_WEBHOOK_SECRET');
        expect(StripeConfig::load([...$marker, 'TINVAR_STRIPE_SECRET' => KEY, 'TINVAR_STRIPE_WEBHOOK_SECRET' => WHSEC])->secret?->reveal())->toBe(KEY);
    })->with([
        'TINVAR_ENV=production' => [['TINVAR_ENV' => 'production']],
        'APP_ENV=staging' => [['APP_ENV' => 'staging']],
        'TINVAR_PROFILE=production' => [['TINVAR_PROFILE' => 'production']],
    ]);

    it('refuses a key that is not a secret key, without repeating it', function () {
        expect(fn () => StripeConfig::load(['TINVAR_STRIPE_SECRET' => 'pk_live_ThisIsPublishable0000']))
            ->toThrow(function (ConfigException $e) {
                expect($e->getMessage())->toContain('TINVAR_STRIPE_SECRET is the secret key')
                    ->and($e->getMessage())->not->toContain('pk_live_ThisIsPublishable0000');
            });
    });

    it('shows no secret in a dump of the settings', function () {
        $config = StripeConfig::load(['TINVAR_STRIPE_SECRET' => KEY, 'TINVAR_STRIPE_WEBHOOK_SECRET' => WHSEC]);

        expect(print_r($config, true))->not->toContain(KEY)->and(print_r($config, true))->not->toContain(WHSEC);
    });
});

describe('the plans', function () {
    it('refuses one price named by two plans', function () {
        expect(fn () => new Plans(TwoPlansOnePrice::class))->toThrow(\InvalidArgumentException::class, "both name the price 'shared_key'");
    });

    it('refuses an interval Stripe does not bill', function () {
        expect(fn () => new Plans(WeeklyPlan::class))->toThrow(\InvalidArgumentException::class, "prices 'week', which is no interval");
    });

    it('refuses a class that is not a plan enum', function () {
        expect(fn () => new Plans(\stdClass::class))->toThrow(\InvalidArgumentException::class, 'is not a backed enum implementing');
    });

    it('finds the plan a lookup key is sold under', function () {
        $plans = new Plans(Plan::class);

        expect($plans->forLookupKey('pro_yearly'))->toBe(Plan::Pro)
            ->and($plans->forLookupKey('old_plan'))->toBeNull()
            ->and($plans->named('team'))->toBe(Plan::Team)
            ->and($plans->named('gone'))->toBeNull();
    });
});

describe('what a subscription entitles', function () {
    $now = CarbonImmutable::createFromTimestamp(1_800_000_000, 'UTC');
    $grace = new \DateInterval('P3D');

    it('entitles as its status and its dates say', function (SubscriptionStatus $status, ?int $periodEnd, ?int $pastDueSince, bool $entitled) use ($now, $grace) {
        $at = static fn (?int $s): ?CarbonImmutable => $s === null ? null : CarbonImmutable::createFromTimestamp($s, 'UTC');
        $subscription = new Subscription('sub_x00001', new Billable(BillableKind::Tenant, 'acme'), 'cus_x', $status, Plan::Pro, 'pro_monthly', 'si_x', 1, null, null, $at($periodEnd), false, null, null, $at($pastDueSince), $now);

        expect($subscription->entitles($now, $grace))->toBe($entitled);
    })->with([
        'active, in its period' => [SubscriptionStatus::Active, 1_800_086_400, null, true],
        'active, past its period within the grace' => [SubscriptionStatus::Active, 1_800_000_000 - 2 * 86_400, null, true],
        'active, past its period and the grace' => [SubscriptionStatus::Active, 1_800_000_000 - 4 * 86_400, null, false],
        'active, with no period' => [SubscriptionStatus::Active, null, null, false],
        'past due a day' => [SubscriptionStatus::PastDue, 1_800_086_400, 1_800_000_000 - 86_400, true],
        'past due four days' => [SubscriptionStatus::PastDue, 1_800_086_400, 1_800_000_000 - 4 * 86_400, false],
        'unpaid a day' => [SubscriptionStatus::Unpaid, 1_800_086_400, 1_800_000_000 - 86_400, true],
        'canceled' => [SubscriptionStatus::Canceled, 1_800_086_400, null, false],
        'incomplete' => [SubscriptionStatus::Incomplete, 1_800_086_400, null, false],
        'paused' => [SubscriptionStatus::Paused, 1_800_086_400, null, false],
    ]);
});

describe('the gates and the webhook', function () {
    it('refuses #[RequiresPlan] naming no plan', function () {
        expect(fn () => new RequiresPlan())->toThrow(\InvalidArgumentException::class, 'at least one plan');
    });

    it('refuses a gated request that runs as no tenant, with a 403 that says so', function () {
        $app = new App();
        $enforce = new EnforceBilling($app->billing);

        expect(fn () => $enforce->handle(new RequiresPlan(Plan::Pro), new Request('GET', '/'), static fn (): Response => Response::make(200)))
            ->toThrow(fn (HttpException $e) => expect([$e->status, $e->code_])->toBe([403, 'no_tenant']));
    });

    it('refuses to bill by tenant in an application with no tenancy', function () {
        expect(fn () => new TenantBillables(new Orm(PdoConnection::sqlite(':memory:', 't'), new IdentityMap('t'))))
            ->toThrow(\InvalidArgumentException::class, 'Billing by tenant needs tenancy');
    });

    it('refuses to run Stripe\'s webhook behind any verifier but Stripe\'s, or as a tenant', function () {
        $app = new App();
        $hook = new StripeEvents($app->billing);
        $request = new Request('POST', '/webhooks/stripe');

        expect(fn () => $hook(new WebhookCall($request, '{}', '', 'evt_000000001', 1)))->toThrow(\LogicException::class, 'Tinvar\'s own signature')
            ->and(fn () => $hook(new WebhookCall($request, '{}', 'acme', 'evt_000000001', 1, \Tinvar\Billing\Http\StripeSignature::class)))->toThrow(\LogicException::class, 'runs as no tenant');
    });
});
