<?php

declare(strict_types=1);

namespace Tinvar\Billing;

use Carbon\CarbonImmutable;
use Tinvar\Orm\Connection;
use Tinvar\Orm\Schema\Dialect;
use Tinvar\Orm\Schema\Schema;
use Tinvar\Orm\Schema\Table;
use Tinvar\Orm\TimestampStorage;

/**
 * Billing's two tables in the application's database: `billing_customers`,
 * one row a payer, naming the Stripe customer made for it; and
 * `billing_subscriptions`, one row a subscription, as Stripe last answered.
 *
 * Neither is a #[TenantScoped] model's: a webhook writes them as no tenant,
 * and an application billing users has no tenancy at all. Every query names
 * the payer -- its kind and id -- in its WHERE, and the payer is only ever
 * Billables' or this table's own, so a tenant reads and writes its own rows
 * alone (ADR 0150). Make both in a migration with define().
 */
final readonly class DatabaseSubscriptions implements SubscriptionStore
{
    private const array COLUMNS = [
        'stripe_subscription', 'billable_kind', 'billable_id', 'stripe_customer', 'status', 'plan', 'price', 'item', 'quantity',
        'trial_ends_at', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'canceled_at', 'ended_at',
        'past_due_since', 'synced_at',
    ];

    private TimestampStorage $timestamps;

    public function __construct(
        private Connection $connection,
        private string $customers = 'billing_customers',
        private string $subscriptions = 'billing_subscriptions',
    ) {
        foreach ([$customers, $subscriptions] as $table) {
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}\z/', $table) !== 1) {
                throw new \InvalidArgumentException("'{$table}' is not a table name billing will write.");
            }
        }
        $this->timestamps = TimestampStorage::for($connection);
    }

    /** Billing's tables, for the application's migration to make. */
    public static function define(Schema $schema, string $customers = 'billing_customers', string $subscriptions = 'billing_subscriptions'): void
    {
        new self($schema->connection(), $customers, $subscriptions);

        $schema->create($customers, static function (Table $table): void {
            $table->enum('billable_kind', BillableKind::class);
            $table->string('billable_id');
            $table->string('stripe_customer')->unique();
            // The payer's first subscription, the one its trial is: written once.
            $table->string('first_subscription')->nullable();
            $table->timestamp('created_at');
            $table->primary(['billable_kind', 'billable_id']);
        });

        $schema->create($subscriptions, static function (Table $table) use ($subscriptions): void {
            $table->string('stripe_subscription')->primary();
            $table->enum('billable_kind', BillableKind::class);
            $table->string('billable_id');
            $table->string('stripe_customer');
            $table->enum('status', SubscriptionStatus::class);
            $table->string('plan')->nullable();
            $table->string('price')->nullable();
            $table->string('item')->nullable();
            $table->integer('quantity');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end');
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('past_due_since')->nullable();
            $table->timestamp('synced_at');
            $table->index(['billable_kind', 'billable_id'], "{$subscriptions}_billable");
        });
    }

    public function customerOf(Billable $billable): ?string
    {
        $rows = $this->connection->select(
            "SELECT stripe_customer FROM {$this->customers} WHERE billable_kind = ? AND billable_id = ?",
            [$billable->kind->value, $billable->id],
        );

        return \is_string($rows[0]['stripe_customer'] ?? null) ? $rows[0]['stripe_customer'] : null;
    }

    public function billableOf(string $customer, BillableKind $kind): ?Billable
    {
        $rows = $this->connection->select(
            "SELECT billable_id FROM {$this->customers} WHERE stripe_customer = ? AND billable_kind = ?",
            [$customer, $kind->value],
        );
        $id = $rows[0]['billable_id'] ?? null;

        return \is_scalar($id) ? new Billable($kind, (string) $id) : null;
    }

    public function keepCustomer(Billable $billable, string $customer): string
    {
        $this->connection->execute(
            Dialect::for($this->connection)->insertUnlessTaken($this->customers, ['billable_kind', 'billable_id', 'stripe_customer', 'created_at']),
            [$billable->kind->value, $billable->id, $customer, $this->timestamps->format(CarbonImmutable::now('UTC'))],
        );

        return $this->customerOf($billable) ?? throw new \LogicException("Stripe customer {$customer} could not be kept for {$billable->key()}: it is already another payer's.");
    }

    public function firstSubscription(Billable $billable, string $subscription): string
    {
        // One statement, which only a row with none recorded takes: of two
        // webhooks at once, one writes and the other reads what it wrote.
        $this->connection->execute(
            "UPDATE {$this->customers} SET first_subscription = ? WHERE billable_kind = ? AND billable_id = ? AND first_subscription IS NULL",
            [$subscription, $billable->kind->value, $billable->id],
        );
        $rows = $this->connection->select(
            "SELECT first_subscription FROM {$this->customers} WHERE billable_kind = ? AND billable_id = ?",
            [$billable->kind->value, $billable->id],
        );
        $first = $rows[0]['first_subscription'] ?? null;

        return \is_string($first) && $first !== ''
            ? $first
            : throw new \LogicException("{$billable->key()} has no Stripe customer kept, so no first subscription to record.");
    }

    public function subscriptionsOf(Billable $billable, Plans $plans): array
    {
        $rows = $this->connection->select(
            "SELECT * FROM {$this->subscriptions} WHERE billable_kind = ? AND billable_id = ? ORDER BY current_period_end IS NULL, current_period_end DESC, stripe_subscription",
            [$billable->kind->value, $billable->id],
        );

        return array_map(fn (array $row): Subscription => $this->subscription($row, $plans), $rows);
    }

    public function find(string $id, Plans $plans): ?Subscription
    {
        $rows = $this->connection->select("SELECT * FROM {$this->subscriptions} WHERE stripe_subscription = ?", [$id]);

        return $rows === [] ? null : $this->subscription($rows[0], $plans);
    }

    public function keep(Subscription $subscription): bool
    {
        $values = $this->values($subscription);

        return $this->connection->transaction(function () use ($subscription, $values): bool {
            $inserted = $this->connection->execute(
                Dialect::for($this->connection)->insertUnlessTaken($this->subscriptions, self::COLUMNS),
                array_values($values),
            );
            if ($inserted === 1) {
                return true;
            }

            // Held already: written over only by an answer asked of Stripe
            // no earlier than the one kept.
            $set = implode(', ', array_map(static fn (string $column): string => "{$column} = ?", array_keys(array_slice($values, 1, null, true))));

            return $this->connection->execute(
                "UPDATE {$this->subscriptions} SET {$set} WHERE stripe_subscription = ? AND synced_at <= ?",
                [...array_values(array_slice($values, 1)), $subscription->id, $values['synced_at']],
            ) === 1;
        });
    }

    /** @return array<string, string|int|null> by column, in COLUMNS' order */
    private function values(Subscription $s): array
    {
        $at = fn (?CarbonImmutable $at): ?string => $at === null ? null : $this->timestamps->format($at);

        return [
            'stripe_subscription' => $s->id,
            'billable_kind' => $s->billable->kind->value,
            'billable_id' => $s->billable->id,
            'stripe_customer' => $s->customer,
            'status' => $s->status->value,
            'plan' => $s->plan === null ? null : (string) $s->plan->value,
            'price' => $s->price,
            'item' => $s->item,
            'quantity' => $s->quantity,
            'trial_ends_at' => $at($s->trialEndsAt),
            'current_period_start' => $at($s->currentPeriodStart),
            'current_period_end' => $at($s->currentPeriodEnd),
            'cancel_at_period_end' => $s->cancelAtPeriodEnd ? 1 : 0,
            'canceled_at' => $at($s->canceledAt),
            'ended_at' => $at($s->endedAt),
            'past_due_since' => $at($s->pastDueSince),
            'synced_at' => $at($s->syncedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    private function subscription(array $row, Plans $plans): Subscription
    {
        $string = static fn (mixed $value): ?string => \is_scalar($value) ? (string) $value : null;
        $at = static fn (mixed $value): ?CarbonImmutable => \is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : null;

        return new Subscription(
            id: (string) $string($row['stripe_subscription'] ?? ''),
            billable: new Billable(BillableKind::from((string) $string($row['billable_kind'] ?? '')), (string) $string($row['billable_id'] ?? '')),
            customer: (string) $string($row['stripe_customer'] ?? ''),
            status: SubscriptionStatus::from((string) $string($row['status'] ?? '')),
            plan: $plans->named($string($row['plan'] ?? null)),
            price: $string($row['price'] ?? null),
            item: $string($row['item'] ?? null),
            quantity: is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 1,
            trialEndsAt: $at($row['trial_ends_at'] ?? null),
            currentPeriodStart: $at($row['current_period_start'] ?? null),
            currentPeriodEnd: $at($row['current_period_end'] ?? null),
            cancelAtPeriodEnd: \in_array($row['cancel_at_period_end'] ?? false, [true, 1, '1', 't', 'true'], true),
            canceledAt: $at($row['canceled_at'] ?? null),
            endedAt: $at($row['ended_at'] ?? null),
            pastDueSince: $at($row['past_due_since'] ?? null),
            syncedAt: $at($row['synced_at'] ?? null) ?? CarbonImmutable::createFromTimestamp(0, 'UTC'),
        );
    }
}
