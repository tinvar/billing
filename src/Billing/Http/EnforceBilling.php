<?php

declare(strict_types=1);

namespace Tinvar\Billing\Http;

use Tinvar\Billing\Attributes\RequiresFeature;
use Tinvar\Billing\Attributes\RequiresPlan;
use Tinvar\Billing\Billing;
use Tinvar\Http\AttributeMiddleware;
use Tinvar\Http\HttpException;
use Tinvar\Http\MiddlewareAttribute;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * What #[RequiresPlan] and #[RequiresFeature] run: the payer in force,
 * entitled now to a plan named or a feature named, or the request is
 * refused before the entry point runs (ADR 0150).
 *
 * It runs where the entry point's own middleware runs: after the tenant is
 * placed and the panel's stack -- its guard, its membership check -- has
 * run, so the payer is the request's tenant or its signed-in user.
 */
final readonly class EnforceBilling implements AttributeMiddleware
{
    public function __construct(private Billing $billing) {}

    public function handle(MiddlewareAttribute $attribute, Request $request, \Closure $next): Response
    {
        $payer = $this->billing->payer() ?? throw Billing::noPayer($this->billing->billables()->kind());

        [$entitled, $needs] = match (true) {
            $attribute instanceof RequiresPlan => [
                $this->billing->onPlan($attribute->plans, $payer),
                'the ' . self::either(array_map(static fn (\BackedEnum $plan): string => $plan->name, $attribute->plans)) . ' plan',
            ],
            $attribute instanceof RequiresFeature => [
                $this->billing->hasFeature($attribute->features, $payer),
                'a plan with ' . self::either(array_map(static fn (\BackedEnum $feature): string => $feature->name, $attribute->features)),
            ],
            default => throw new \LogicException(self::class . ' enforces #[RequiresPlan] and #[RequiresFeature], and was handed ' . $attribute::class . '.'),
        };

        if (!$entitled) {
            throw new HttpException(402, "This needs {$needs}. Choose a plan, or update your payment details, to go on.", 'plan_required');
        }

        return $next($request);
    }

    /** @param list<string> $names */
    private static function either(array $names): string
    {
        return \count($names) === 1 ? $names[0] : implode(', ', \array_slice($names, 0, -1)) . ' or ' . $names[\count($names) - 1];
    }
}
