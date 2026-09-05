<?php

declare(strict_types=1);

namespace PYH\Domain\Quote;

final class ReadinessValidator
{
    /** @param array<string, mixed> $quote
     *  @param list<array<string, mixed>> $travellers
     *  @param list<array<string, mixed>> $components
     *  @return array<string, list<string>>
     */
    public function validate(array $quote, array $travellers, array $components, bool $pricingReconciles, string $complianceResult): array
    {
        $errors = [];
        if ((int) ($quote['customer_id'] ?? 0) < 1) { $errors['overview'][] = 'An accessible customer is required.'; }
        if (empty($quote['departure_date']) || empty($quote['return_date']) || (string) $quote['return_date'] < (string) $quote['departure_date']) { $errors['overview'][] = 'Valid travel dates are required.'; }
        if (empty($quote['expires_at_utc'])) { $errors['overview'][] = 'A quote expiry is required.'; }
        if ($travellers === []) { $errors['travellers'][] = 'At least one traveller is required.'; }
        if (!array_filter($travellers, static fn (array $t): bool => (bool) ($t['is_lead'] ?? false))) { $errors['travellers'][] = 'A lead traveller is required.'; }
        if (!array_filter($components, static fn (array $c): bool => ($c['inclusion_state'] ?? '') === 'Included')) { $errors['arrangements'][] = 'At least one included arrangement is required.'; }
        if (!$pricingReconciles) { $errors['pricing'][] = 'Persisted pricing does not reconcile.'; }
        if ($complianceResult === 'Block' || $complianceResult === '') { $errors['compliance'][] = 'Compliance review must pass without blockers.'; }
        return $errors;
    }
}
