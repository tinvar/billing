<?php

declare(strict_types=1);

namespace Tinvar\Billing;

use Tinvar\Attributes\Config;
use Tinvar\Attributes\Env;
use Tinvar\Billing\Stripe\StripeClient;
use Tinvar\Config\ConfigException;
use Tinvar\Config\Configs;
use Tinvar\Config\Secret;
use Tinvar\Outbound\OutboundHttp;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * Billing's settings (ADR 0150), each also from its `_FILE`:
 *
 *   - TINVAR_STRIPE_SECRET: the secret key, `sk_test_...` or `sk_live_...`
 *     (or a restricted `rk_...`), which every call to Stripe's API carries;
 *   - TINVAR_STRIPE_WEBHOOK_SECRET: the webhook endpoint's `whsec_...`
 *     signing secret, which StripeSignature checks deliveries with;
 *   - TINVAR_STRIPE_GRACE_DAYS: how long a subscription keeps entitling
 *     after a failed payment, or past a period whose renewal was not heard
 *     of; 3 unless set.
 *
 * In development both secrets may be left unset: the application boots,
 * gates read what is kept -- nothing, so the free plan or none -- and a
 * call to Stripe says which variable to set. Where the environment says
 * production or staging, client() refuses to boot without both.
 *
 * A config class is never Data: the encoder refuses it, and the renderer
 * never sees a key.
 */
#[Config(prefix: 'TINVAR_STRIPE_')]
final readonly class StripeConfig
{
    public function __construct(
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $secret = null,
        #[\SensitiveParameter, Env(file: true)]
        public ?Secret $webhookSecret = null,
        public int $graceDays = 3,
    ) {
        if ($secret !== null && preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{8,247}\z/', $secret->reveal()) !== 1) {
            throw ConfigException::invalid('TINVAR_STRIPE_SECRET', 'is the secret key the Stripe dashboard shows under Developers, API keys: sk_test_..., sk_live_... or a restricted rk_...');
        }
        if ($webhookSecret !== null && preg_match('/^whsec_[A-Za-z0-9+\/=]{16,250}\z/', $webhookSecret->reveal()) !== 1) {
            throw ConfigException::invalid('TINVAR_STRIPE_WEBHOOK_SECRET', 'is the whsec_... signing secret the Stripe dashboard shows for the webhook endpoint, or `stripe listen` prints');
        }
        if ($graceDays < 0 || $graceDays > 60) {
            throw ConfigException::invalid('TINVAR_STRIPE_GRACE_DAYS', 'is 0 to 60 days');
        }
    }

    /**
     * The settings from the environment -- or $env, a test's -- checked for
     * where they run: production and staging need both secrets.
     *
     * @param array<string, string>|null $env
     * @throws ConfigException
     */
    public static function load(?array $env = null): self
    {
        $config = Configs::load(self::class, $env);
        $marker = self::productionMarker($env ?? array_filter(getenv(), \is_string(...)));
        if ($marker !== null) {
            $missing = [];
            if ($config->secret === null) {
                $missing[] = "TINVAR_STRIPE_SECRET (or TINVAR_STRIPE_SECRET_FILE) is required where {$marker}: the Stripe secret key billing calls Stripe with.";
            }
            if ($config->webhookSecret === null) {
                $missing[] = "TINVAR_STRIPE_WEBHOOK_SECRET (or TINVAR_STRIPE_WEBHOOK_SECRET_FILE) is required where {$marker}: the whsec_... secret Stripe signs the webhook with, without which no subscription is ever kept.";
            }
            if ($missing !== []) {
                throw new ConfigException($missing);
            }
        }

        return $config;
    }

    /** The Stripe client these settings make, over the application's OutboundHttp. */
    public function client(OutboundHttp $http): StripeClient
    {
        return new StripeClient($this->secret, $http, $this->webhookSecret);
    }

    /** The grace period, as Billing takes it. */
    public function grace(): \DateInterval
    {
        return new \DateInterval("P{$this->graceDays}D");
    }

    /**
     * What says this is production or staging, or null: the markers that
     * rule dev mode out (DevMode), and TINVAR_ENV, APP_ENV or TINVAR_PROFILE
     * saying production or staging -- as mail reads them (ADR 0147).
     *
     * @param array<string, string> $env
     */
    private static function productionMarker(array $env): ?string
    {
        $marker = DevMode::productionMarker($env);
        if ($marker !== null) {
            return "{$marker}=production";
        }
        foreach (['TINVAR_ENV', 'APP_ENV', BuildProfile::ENV] as $variable) {
            $value = strtolower(trim($env[$variable] ?? ''));
            if (\in_array($value, [BuildProfile::PRODUCTION, BuildProfile::STAGING], true)) {
                return "{$variable}={$value}";
            }
        }

        return null;
    }
}
