<?php

declare(strict_types=1);

namespace Tinvar\Billing\Stripe;

/**
 * Stripe refused a call, or could not be reached. The message names the
 * call, Stripe's status and its own reason, with every key cut out; it
 * never carries the request (StripeClient).
 */
final class StripeException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?string $stripeCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Whether trying again later may succeed: Stripe was not reached, or answered 409, 429 or 5xx. */
    public function retryable(): bool
    {
        return $this->status === null || $this->status === 409 || $this->status === 429 || $this->status >= 500;
    }
}
