<?php

declare(strict_types=1);

namespace Tinvar\Billing\Attributes;

use Tinvar\Billing\Http\EnforceBilling;
use Tinvar\Http\MiddlewareAttribute;

/**
 * Lets in only a payer whose plan now includes one of the features named --
 * cases of the application's own feature enum, which Plan::features()
 * lists:
 *
 *     #[Action(name: 'reports.export'), Authorize('export', 'report'), RequiresFeature(Feature::Exports)]
 *
 * As #[RequiresPlan] in every other way: 402 (`plan_required`) for a payer
 * without it, 401 or 403 with nobody to bill, and refused when Billing is
 * not registered (ADR 0150). A plan feature is what was paid for; it is not
 * a feature flag (Tinvar\Features), which rolls code out.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresFeature implements MiddlewareAttribute
{
    /** @var non-empty-list<\BackedEnum> */
    public array $features;

    public function __construct(\BackedEnum ...$features)
    {
        if ($features === []) {
            throw new \InvalidArgumentException('#[RequiresFeature] names at least one feature.');
        }
        $this->features = array_values($features);
    }

    public function middleware(): string
    {
        return EnforceBilling::class;
    }
}
