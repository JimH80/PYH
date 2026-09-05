<?php

declare(strict_types=1);

namespace PYH\Domain\Quote;

use DomainException;

final class PricingCalculator
{
    /** @param list<array<string, mixed>> $components
     *  @param list<array<string, mixed>> $adjustments
     *  @return array{included_total: string, adjustments_total: string, customer_total: string, optional_total: string}
     */
    public function calculate(array $components, array $adjustments): array
    {
        $included = 0; $optional = 0; $adjustmentTotal = 0;
        foreach ($components as $component) {
            $amount = Money::minor((string) $component['selling_price']);
            if ($amount < 0) { throw new DomainException('Component selling price cannot be negative.'); }
            $selected = (bool) ($component['selected'] ?? false);
            if ($component['inclusion_state'] === 'Included' || ($component['inclusion_state'] === 'Optional' && $selected)) { $included += $amount; }
            elseif ($component['inclusion_state'] === 'Optional') { $optional += $amount; }
        }
        foreach ($adjustments as $adjustment) {
            $amount = abs(Money::minor((string) $adjustment['amount']));
            $adjustmentTotal += $adjustment['adjustment_type'] === 'Discount' ? -$amount : $amount;
        }
        $total = $included + $adjustmentTotal;
        if ($total < 0) { throw new DomainException('Customer total cannot be negative.'); }
        return ['included_total' => Money::decimal($included), 'adjustments_total' => Money::decimal($adjustmentTotal), 'customer_total' => Money::decimal($total), 'optional_total' => Money::decimal($optional)];
    }
}
