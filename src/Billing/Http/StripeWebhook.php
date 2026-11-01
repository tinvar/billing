<?php

declare(strict_types=1);

namespace Tinvar\Billing\Http;

use Tinvar\Billing\Billing;
use Tinvar\Http\Response;
use Tinvar\Webhooks\WebhookCall;

/**
 * Stripe's webhook, as the application declares it -- one class, found by
 * discovery, at the path it chooses:
 *
 *     #[Webhook('/webhooks/stripe', verifier: StripeSignature::class)]
 *     final class StripeEvents extends StripeWebhook {}
 *
 * The framework's webhook endpoint has verified the delivery before this
 * runs: its Stripe signature, its timestamp, and its event id as the nonce,
 * once (StripeSignature). Then Billing::handle() fetches the subscription
 * the event names from Stripe and keeps what Stripe answers, so the event's
 * own body is never taken for the state (ADR 0150).
 *
 * It runs as no tenant: Stripe sends every payer's events to one URL, and
 * the payer is the one this application made the event's customer for.
 * Declare no #[TenantFrom] on it.
 */
abstract class StripeWebhook
{
    public function __construct(protected readonly Billing $billing) {}

    public function __invoke(WebhookCall $call): Response
    {
        // Behind any other verifier, the delivery was never Stripe's.
        if ($call->verifier !== StripeSignature::class) {
            throw new \LogicException(static::class . ' is Stripe\'s webhook, and was reached past ' . ($call->verifier ?? 'Tinvar\'s own signature') . ': declare it #[Webhook(\'...\', verifier: ' . StripeSignature::class . '::class)].');
        }
        if ($call->tenant !== '') {
            throw new \LogicException(static::class . ' runs as no tenant: take its #[TenantFrom] away. Stripe sends every payer\'s events to one URL.');
        }

        $this->billing->handle($call->json());

        // An event about nothing kept here is still taken: a 4xx would only
        // have Stripe send it again.
        return Response::make(204);
    }
}
