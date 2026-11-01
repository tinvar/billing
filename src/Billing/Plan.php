<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/**
 * What an application sells, declared in code as a backed enum:
 *
 *     enum Plan: string implements \Tinvar\Billing\Plan
 *     {
 *         case Free = 'free';
 *         case Pro = 'pro';
 *         case Team = 'team';
 *
 *         public function prices(): array
 *         {
 *             return match ($this) {
 *                 self::Free => [],
 *                 self::Pro => ['month' => 'pro_monthly', 'year' => 'pro_yearly'],
 *                 self::Team => ['month' => 'team_monthly'],
 *             };
 *         }
 *
 *         public function features(): array
 *         {
 *             return match ($this) {
 *                 self::Free => [],
 *                 self::Pro => [Feature::Exports],
 *                 self::Team => [Feature::Exports, Feature::Seats],
 *             };
 *         }
 *
 *         public function trialDays(): int
 *         {
 *             return $this === self::Pro ? 14 : 0;
 *         }
 *     }
 *
 * A price is named by its Stripe *lookup key*, never its id: the key is the
 * same in test mode and live mode, so one enum serves both, and a price can
 * be replaced in Stripe's dashboard by moving the key to it, with no
 * deploy. A subscription is on the plan whose prices hold its price's
 * lookup key; a price no plan names puts it on no plan, which entitles
 * nothing (ADR 0150).
 */
interface Plan extends \BackedEnum
{
    /**
     * The Stripe price lookup keys this plan is sold at, by billing
     * interval; empty for a plan nobody pays for.
     *
     * @return array<string, string> Interval's value -- month, year -- => lookup key
     */
    public function prices(): array;

    /**
     * What this plan entitles its subscribers to: cases of the
     * application's own feature enum, which #[RequiresFeature] names.
     *
     * @return list<\BackedEnum>
     */
    public function features(): array;

    /** Days of free trial a new subscription to this plan starts with; 0 for none. */
    public function trialDays(): int;
}
