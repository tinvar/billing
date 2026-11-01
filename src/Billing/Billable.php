<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/**
 * One payer: a tenant or a user, by its id. It is never read from a
 * request: the Billables in force name it from the tenant the request runs
 * as or the user signed in, and a webhook names it from the Stripe customer
 * this application made for it (ADR 0150).
 */
final readonly class Billable
{
    public function __construct(public BillableKind $kind, public string $id)
    {
        if ($id === '' || \strlen($id) > 255 || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new \InvalidArgumentException('A billable\'s id is 1 to 255 bytes of printable text.');
        }
    }

    /** `tenant:acme`, `user:42`: what Stripe's metadata and client_reference_id carry, for a person reading its dashboard. */
    public function key(): string
    {
        return "{$this->kind->value}:{$this->id}";
    }

    public function is(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }
}
