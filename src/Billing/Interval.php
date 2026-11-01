<?php

declare(strict_types=1);

namespace Tinvar\Billing;

/** How often a plan's price is charged: Stripe's recurring interval. */
enum Interval: string
{
    case Month = 'month';
    case Year = 'year';
}
