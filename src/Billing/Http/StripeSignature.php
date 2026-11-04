<?php

declare(strict_types=1);

namespace Tinvar\Billing\Http;

use Tinvar\Billing\Billing;
use Tinvar\Billing\Stripe\StripeSignatureHeader;
use Tinvar\Http\HttpException;
use Tinvar\Http\Request;
use Tinvar\Webhooks\VerifiedDelivery;
use Tinvar\Webhooks\WebhookVerifier;

/**
 * Proves a Stripe webhook delivery for the framework's webhook endpoint
 * (ADR 0150), named on the application's #[Webhook]:
 *
 *     #[Webhook('/webhooks/stripe', verifier: StripeSignature::class)]
 *
 *   1. A signing secret is set (TINVAR_STRIPE_WEBHOOK_SECRET), or the
 *      webhook is not set up here: 404.
 *   2. `Stripe-Signature` carries a timestamp and a v1 MAC of
 *      `{t}.{body}` that verifies with it: else 401.
 *   3. The timestamp is within five minutes of now either way: else 401.
 *   4. The body is a Stripe event, its `id` an `evt_` id of at most the 128
 *      bytes a nonce store keeps: else 400. The id
 *      is the nonce, covered by the MAC, so a replay of the delivery -- or
 *      Stripe sending one event twice at once -- is claimed once in the
 *      application's NonceStore, and the second is a 409.
 */
final readonly class StripeSignature implements WebhookVerifier
{
    public function __construct(private Billing $billing) {}

    public function verify(string $webhook, string $tenant, Request $request, string $rawBody, int $now): VerifiedDelivery
    {
        $secret = $this->billing->stripe()->webhookSecret();
        if ($secret === null) {
            throw new HttpException(404, 'Stripe\'s webhook is not set up here: set TINVAR_STRIPE_WEBHOOK_SECRET to the endpoint\'s whsec_... signing secret.', 'not_found');
        }

        $signature = StripeSignatureHeader::parse($request->header(StripeSignatureHeader::HEADER));
        if ($signature === null) {
            throw new HttpException(401, 'The delivery carries no Stripe-Signature with a timestamp and a v1 signature.', 'webhook_signature');
        }
        [$timestamp, $macs] = $signature;
        if (!StripeSignatureHeader::verifies($secret->reveal(), $timestamp, $rawBody, $macs)) {
            throw new HttpException(401, 'The delivery\'s Stripe signature does not verify with this endpoint\'s signing secret.', 'webhook_signature');
        }
        if (abs($now - $timestamp) > StripeSignatureHeader::TOLERANCE) {
            throw new HttpException(401, 'The delivery\'s timestamp is outside the tolerance: sent too long ago, or by a clock far off.', 'webhook_signature');
        }

        try {
            $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $event = null;
        }
        $id = \is_array($event) ? ($event['id'] ?? null) : null;
        if (!\is_string($id) || preg_match('/^evt_[A-Za-z0-9]{8,124}\z/', $id) !== 1 || ($event['object'] ?? null) !== 'event') {
            throw new HttpException(400, 'The delivery is signed, and is not a Stripe event with an evt_ id.', 'bad_request');
        }

        return new VerifiedDelivery($timestamp, $id, $timestamp + StripeSignatureHeader::TOLERANCE, VerifiedDelivery::signerOf($secret->reveal()));
    }
}
