<?php

declare(strict_types=1);

namespace Tinvar\Billing\Stripe;

use Tinvar\Config\Secret;
use Tinvar\Observability\Redactor;
use Tinvar\Outbound\Destinations;
use Tinvar\Outbound\OutboundFailed;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Outbound\OutboundRefused;
use Tinvar\Outbound\OutboundRequest;
use Tinvar\Outbound\OutboundResponse;

/**
 * The calls billing makes to Stripe's API, through the application's one
 * OutboundHttp (ADR 0038): held to api.stripe.com and nowhere else, within
 * a timeout, reading at most 256 KB back, following no redirect -- a 3xx
 * from an API is a failure, never somewhere else to send the key.
 *
 * The secret key is a Secret: a dump of this shows `[redacted]`, it is put
 * in the Authorization header of the one request that needs it and
 * nowhere else, and every exception's text is Stripe's reason with the key
 * cut out. Nothing here is Data, so the encoder refuses it, and the
 * renderer never sees it (ADR 0150).
 *
 * The API version is pinned (VERSION), so an account upgraded in Stripe's
 * dashboard does not change the shape of what this reads. A POST carries
 * an Idempotency-Key, so a call retried after a lost answer is not made
 * twice.
 */
final readonly class StripeClient
{
    public const string HOST = 'api.stripe.com';

    /** The Stripe API version every call names. */
    public const string VERSION = '2025-03-31.basil';

    /** The origins Stripe's hosted pages are on: an action's location() allowlist (ExternalOrigins). */
    public const array REDIRECT_ORIGINS = ['https://checkout.stripe.com', 'https://billing.stripe.com'];

    public const float TIMEOUT = 20.0;

    public const int MAX_RESPONSE_BYTES = 262_144;

    private Destinations $destinations;

    private Redactor $redactor;

    public function __construct(
        /** The secret key; null in development before one is set, where every call says to set it. */
        #[\SensitiveParameter]
        private ?Secret $secretKey,
        private OutboundHttp $http,
        /** The webhook endpoint's signing secret (`whsec_...`), which StripeSignature checks deliveries with; null refuses every delivery. */
        #[\SensitiveParameter]
        private ?Secret $webhookSecret = null,
    ) {
        if ($secretKey !== null && preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{8,247}\z/', $secretKey->reveal()) !== 1) {
            throw new \InvalidArgumentException('A Stripe secret key is sk_test_..., sk_live_... or a restricted rk_... key, as the dashboard shows it under Developers, API keys.');
        }
        if ($webhookSecret !== null && preg_match('/^whsec_[A-Za-z0-9+\/=]{16,250}\z/', $webhookSecret->reveal()) !== 1) {
            throw new \InvalidArgumentException('A Stripe webhook signing secret is the whsec_... the dashboard shows for the endpoint, or `stripe listen` prints.');
        }
        $this->destinations = Destinations::hosts([self::HOST]);
        $this->redactor = new Redactor(traces: false);
    }

    /** Whether the key is a live-mode one, which charges real cards. */
    public function live(): bool
    {
        return $this->secretKey !== null && str_contains($this->secretKey->reveal(), '_live_');
    }

    /** The webhook signing secret, for StripeSignature alone; null when none is set. */
    public function webhookSecret(): ?Secret
    {
        return $this->webhookSecret;
    }

    /**
     * GET a resource.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed> the object Stripe answers with
     * @throws StripeException
     */
    public function get(string $path, array $query = []): array
    {
        $encoded = self::form($query);

        return $this->call('GET', $path . ($encoded === '' ? '' : "?{$encoded}"), '', null);
    }

    /**
     * POST a form to a resource.
     *
     * @param array<string, mixed> $form nested arrays as Stripe's `a[b][0]=c`
     * @param string $idempotencyKey the same for a retry of the same intent, so Stripe makes it once
     * @return array<string, mixed>
     * @throws StripeException
     */
    public function post(string $path, array $form, string $idempotencyKey): array
    {
        if (preg_match('/^[A-Za-z0-9_:.-]{8,255}\z/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('An idempotency key is 8 to 255 letters, digits and _ : . -');
        }

        return $this->call('POST', $path, self::form($form), $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     * @throws StripeException
     */
    private function call(string $method, string $path, string $body, ?string $idempotencyKey): array
    {
        if (preg_match('#^/v1/[A-Za-z0-9_/]+(\?.*)?\z#', $path) !== 1) {
            throw new \InvalidArgumentException("'{$path}' is not a Stripe API path this client calls.");
        }
        $what = $method . ' ' . strtok($path, '?');
        if ($this->secretKey === null) {
            throw new StripeException("Stripe was not called ({$what}): no secret key is set. Set TINVAR_STRIPE_SECRET to a test key (sk_test_...) from dashboard.stripe.com/test/apikeys.");
        }
        $key = $this->secretKey->reveal();
        $headers = [
            'Authorization' => "Bearer {$key}",
            'Stripe-Version' => self::VERSION,
            'Accept' => 'application/json',
        ];
        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $headers['Idempotency-Key'] = (string) $idempotencyKey;
        }

        try {
            $response = $this->http->send(new OutboundRequest(
                $method,
                'https://' . self::HOST . $path,
                $headers,
                $body,
                timeout: self::TIMEOUT,
                maxResponseBytes: self::MAX_RESPONSE_BYTES,
                maxRedirects: 0,
                destinations: $this->destinations,
            ));
        } catch (OutboundRefused $e) {
            throw new StripeException("Stripe was not called ({$what}): {$e->reason}. " . $this->clean($e->getMessage()), null, null, $e);
        } catch (OutboundFailed $e) {
            throw new StripeException("Stripe did not answer ({$what}): {$e->reason}. " . $this->clean($e->getMessage()), $e->status, null, $e);
        }

        $json = self::json($response);
        if (!$response->successful()) {
            $error = \is_array($json['error'] ?? null) ? $json['error'] : [];
            $reason = \is_string($error['message'] ?? null) ? $error['message'] : null;
            $code = \is_string($error['code'] ?? null) ? $error['code'] : null;

            throw new StripeException("Stripe answered {$response->status} to {$what}" . ($reason === null ? '.' : ': ' . $this->clean($reason)), $response->status, $code);
        }
        if ($json === []) {
            throw new StripeException("Stripe answered {$what} with no JSON object.", $response->status);
        }

        return $json;
    }

    /** @return array<string, mixed> */
    private static function json(OutboundResponse $response): array
    {
        try {
            $decoded = $response->json();
        } catch (\JsonException) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name => $value) {
            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * Stripe's form encoding: `metadata[a]=b`, `line_items[0][price]=p`;
     * true and false as `true` and `false`, null left out.
     *
     * @param array<array-key, mixed> $fields
     */
    public static function form(array $fields, string $prefix = ''): string
    {
        $pairs = [];
        foreach ($fields as $name => $value) {
            $key = $prefix === '' ? (string) $name : "{$prefix}[{$name}]";
            if ($value === null) {
                continue;
            }
            if (\is_array($value)) {
                $nested = self::form($value, $key);
                if ($nested !== '') {
                    $pairs[] = $nested;
                }

                continue;
            }
            $scalar = match (true) {
                \is_bool($value) => $value ? 'true' : 'false',
                \is_scalar($value) => (string) $value,
                $value instanceof \BackedEnum => (string) $value->value,
                default => throw new \InvalidArgumentException("Stripe's form takes scalars, enums and arrays; {$key} is " . get_debug_type($value) . '.'),
            };
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($scalar);
        }

        return implode('&', $pairs);
    }

    /** $text on one line, cut to 300 bytes, with the keys and anything else that looks like a secret taken out. */
    private function clean(string $text): string
    {
        foreach ([$this->secretKey, $this->webhookSecret] as $secret) {
            if ($secret !== null) {
                $text = str_replace($secret->reveal(), Redactor::REDACTED, $text);
            }
        }
        $text = (string) preg_replace('/\b(sk|rk)_(test|live)_[A-Za-z0-9]+/', Redactor::REDACTED, $text);
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
        if (\strlen($text) > 300) {
            $text = mb_strcut($text, 0, 300, 'UTF-8') . '...';
        }

        return $this->redactor->text($text);
    }
}
