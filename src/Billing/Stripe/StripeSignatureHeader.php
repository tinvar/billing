<?php

declare(strict_types=1);

namespace Tinvar\Billing\Stripe;

/**
 * Stripe's webhook signature scheme, as its docs give it:
 *
 *     Stripe-Signature: t=1792836000,v1=<hex HMAC-SHA256>,v1=<another>,v0=<test-mode legacy>
 *
 * The MAC is HMAC-SHA256 over `{t}.{raw body}`, keyed with the endpoint's
 * whole signing secret, `whsec_` and all. Any v1 may match -- while a
 * secret is being rolled Stripe signs with both -- and each is compared in
 * constant time; v0 and any other scheme are ignored.
 */
final class StripeSignatureHeader
{
    public const string HEADER = 'stripe-signature';

    /** Seconds a delivery's timestamp may differ from now: Stripe's own libraries' default. */
    public const int TOLERANCE = 300;

    /**
     * The header's timestamp and its v1 MACs, or null when it is not one.
     *
     * @return array{int, list<string>}|null
     */
    public static function parse(?string $header): ?array
    {
        if ($header === null || \strlen($header) > 2048) {
            return null;
        }
        $timestamp = null;
        $macs = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && preg_match('/^[1-9]\d{0,11}\z/', $value) === 1 && $timestamp === null) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && preg_match('/^[0-9a-f]{64}\z/', $value) === 1) {
                $macs[] = $value;
            }
        }

        return $timestamp === null || $macs === [] ? null : [$timestamp, $macs];
    }

    /** The v1 MAC of a delivery. */
    public static function mac(#[\SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /** The header a delivery signed with $secret at $timestamp carries: what Stripe sends, and what a test writes. */
    public static function header(#[\SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return "t={$timestamp},v1=" . self::mac($secret, $timestamp, $body);
    }

    /**
     * Whether one of $macs is the delivery's, compared in constant time
     * against each; every one is compared, so how many there are is all
     * the timing says.
     *
     * @param list<string> $macs
     */
    public static function verifies(#[\SensitiveParameter] string $secret, int $timestamp, string $body, array $macs): bool
    {
        $expected = self::mac($secret, $timestamp, $body);
        $found = false;
        foreach ($macs as $mac) {
            $found = hash_equals($expected, $mac) || $found;
        }

        return $found;
    }
}
