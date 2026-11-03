<?php

declare(strict_types=1);

/**
 * The subscription store, the same in memory, on SQLite and on Postgres:
 * every read names its payer, a customer is matched to a payer only by the
 * row the app wrote, an answer asked of Stripe earlier never replaces one
 * asked later, and instants come back as they went in (ADR 0150).
 */

namespace Tinvar\Tests\Billing\SubscriptionStore;

use Carbon\CarbonImmutable;
use Tinvar\Billing\ArraySubscriptions;
use Tinvar\Billing\Billable;
use Tinvar\Billing\BillableKind;
use Tinvar\Billing\DatabaseSubscriptions;
use Tinvar\Billing\Plans;
use Tinvar\Billing\Subscription;
use Tinvar\Billing\SubscriptionStatus;
use Tinvar\Billing\SubscriptionStore;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Tests\Billing\Fixtures\Plan;
use Tinvar\Tests\Orm\Fixtures\Databases;

require_once __DIR__ . '/../Fixtures/app.php';
require_once \Composer\InstalledVersions::getInstallPath('tinvar/database') . '/tests/Orm/Fixtures/databases.php';

function subscription(string $id, Billable $billable, SubscriptionStatus $status = SubscriptionStatus::Active, int $syncedAt = 1_800_000_000, ?Plan $plan = Plan::Pro, int $quantity = 1, ?string $periodEnd = '2027-01-15T10:20:30.123456Z'): Subscription
{
    $at = static fn (int $seconds): CarbonImmutable => CarbonImmutable::createFromTimestamp($seconds, 'UTC');

    return new Subscription(
        id: $id,
        billable: $billable,
        customer: 'cus_' . $billable->id,
        status: $status,
        plan: $plan,
        price: 'pro_monthly',
        item: 'si_000001',
        quantity: $quantity,
        trialEndsAt: null,
        currentPeriodStart: $at(1_800_000_000),
        currentPeriodEnd: $periodEnd === null ? null : CarbonImmutable::parse($periodEnd),
        cancelAtPeriodEnd: true,
        canceledAt: null,
        endedAt: null,
        pastDueSince: $status->isPastDue() ? $at($syncedAt) : null,
        syncedAt: $at($syncedAt),
    );
}

/** @param \Closure(SubscriptionStore): void $test */
function eachStore(string $store, \Closure $test): void
{
    if ($store === 'array') {
        $test(new ArraySubscriptions());

        return;
    }
    if ($store === 'sqlite') {
        $connection = PdoConnection::sqlite(':memory:', 'billing-store');
        DatabaseSubscriptions::define(new Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection)));
        $test(new DatabaseSubscriptions($connection));

        return;
    }
    Databases::inScratchSchema('pgsql', static function (\PDO $pdo) use ($test): void {
        $connection = new PdoConnection($pdo, 'billing-store-pg');
        DatabaseSubscriptions::define(new Schema($connection, \Tinvar\Orm\Schema\Dialect::for($connection)));
        $test(new DatabaseSubscriptions($connection));
    });
}

$stores = ['in memory' => 'array', 'SQLite' => 'sqlite', 'Postgres' => 'pgsql'];

beforeEach(function () {
    if (str_contains((string) $this->dataName(), 'Postgres') && ($missing = Databases::postgresMissing()) !== null) {
        $this->markTestSkipped($missing);
    }
});

it('keeps a payer\'s customer once, and finds the payer by it alone', function (string $store) {
    eachStore($store, function (SubscriptionStore $s): void {
        $acme = new Billable(BillableKind::Tenant, 'acme');
        expect($s->keepCustomer($acme, 'cus_acme'))->toBe('cus_acme')
            ->and($s->keepCustomer($acme, 'cus_acme_again'))->toBe('cus_acme')
            ->and($s->customerOf($acme))->toBe('cus_acme')
            ->and($s->customerOf(new Billable(BillableKind::Tenant, 'globex')))->toBeNull()
            ->and($s->billableOf('cus_acme', BillableKind::Tenant)?->id)->toBe('acme')
            // Another kind's payer is never this one, whatever its id.
            ->and($s->billableOf('cus_acme', BillableKind::User))->toBeNull()
            ->and($s->billableOf('cus_unknown', BillableKind::Tenant))->toBeNull()
            ->and(fn () => $s->keepCustomer(new Billable(BillableKind::Tenant, 'globex'), 'cus_acme'))->toThrow(\LogicException::class);
    });
})->with($stores);

it('reads a payer\'s own subscriptions alone, as they were kept', function (string $store) {
    eachStore($store, function (SubscriptionStore $s): void {
        $plans = new Plans(Plan::class);
        $acme = new Billable(BillableKind::Tenant, 'acme');
        $globex = new Billable(BillableKind::Tenant, 'globex');
        $s->keep(subscription('sub_acme01', $acme, quantity: 5));
        $s->keep(subscription('sub_globex1', $globex, SubscriptionStatus::PastDue));

        $kept = $s->subscriptionsOf($acme, $plans);
        expect($kept)->toHaveCount(1)
            ->and($kept[0]->id)->toBe('sub_acme01')
            ->and($kept[0]->plan)->toBe(Plan::Pro)
            ->and($kept[0]->quantity)->toBe(5)
            ->and($kept[0]->cancelAtPeriodEnd)->toBeTrue()
            ->and($kept[0]->currentPeriodEnd?->format('Y-m-d\TH:i:s.u\Z'))->toBe('2027-01-15T10:20:30.123456Z')
            ->and($kept[0]->pastDueSince)->toBeNull()
            ->and($s->subscriptionsOf(new Billable(BillableKind::User, 'acme'), $plans))->toBe([])
            ->and($s->find('sub_globex1', $plans)?->pastDueSince?->getTimestamp())->toBe(1_800_000_000);
    });
})->with($stores);

it('never lets an older answer replace a newer one, and lets a newer one replace an older', function (string $store) {
    eachStore($store, function (SubscriptionStore $s): void {
        $plans = new Plans(Plan::class);
        $acme = new Billable(BillableKind::Tenant, 'acme');
        expect($s->keep(subscription('sub_acme01', $acme, SubscriptionStatus::Active, 1_800_000_100)))->toBeTrue()
            ->and($s->keep(subscription('sub_acme01', $acme, SubscriptionStatus::PastDue, 1_800_000_050)))->toBeFalse()
            ->and($s->find('sub_acme01', $plans)?->status)->toBe(SubscriptionStatus::Active)
            ->and($s->keep(subscription('sub_acme01', $acme, SubscriptionStatus::Canceled, 1_800_000_200)))->toBeTrue()
            ->and($s->find('sub_acme01', $plans)?->status)->toBe(SubscriptionStatus::Canceled);
    });
})->with($stores);

it('reads back a plan the enum no longer has as no plan, which entitles nothing', function (string $store) {
    eachStore($store, function (SubscriptionStore $s): void {
        $acme = new Billable(BillableKind::Tenant, 'acme');
        $s->keep(subscription('sub_acme01', $acme, plan: null));

        $kept = $s->find('sub_acme01', new Plans(Plan::class));
        expect($kept?->plan)->toBeNull()
            ->and($kept?->entitles(CarbonImmutable::createFromTimestamp(1_800_000_000, 'UTC'), new \DateInterval('P3D')))->toBeFalse();
    });
})->with($stores);

// Billing::subscription() falls back to a payer's first subscription, "its
// latest". Postgres sorts a NULL first in a descending order, where SQLite,
// MySQL and the array store sort it last: one with no period yet was the
// latest on Postgres alone. Each store now lists the latest period first,
// one with none last, and ties by id.
it('lists a payer\'s subscriptions the latest period first, one with no period last, the same in every store', function (string $store) {
    eachStore($store, function (SubscriptionStore $s): void {
        $acme = new Billable(BillableKind::Tenant, 'acme');
        $s->keep(subscription('sub_acme03', $acme, periodEnd: null));
        $s->keep(subscription('sub_acme02', $acme, periodEnd: '2027-01-01T00:00:00Z'));
        $s->keep(subscription('sub_acme05', $acme));
        $s->keep(subscription('sub_acme04', $acme));

        expect(array_map(static fn (Subscription $kept): string => $kept->id, $s->subscriptionsOf($acme, new Plans(Plan::class))))
            ->toBe(['sub_acme04', 'sub_acme05', 'sub_acme02', 'sub_acme03']);
    });
})->with($stores);
