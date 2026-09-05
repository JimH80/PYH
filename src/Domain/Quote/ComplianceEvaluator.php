<?php

declare(strict_types=1);

namespace PYH\Domain\Quote;

final class ComplianceEvaluator
{
    /**
     * @param array<string, mixed> $evidence
     * @return array{result: string, reasons: list<string>}
     */
    public function evaluate(array $evidence): array
    {
        $blocks = [];
        foreach (['total_price_clear', 'mandatory_charges_included', 'material_information_present', 'supplier_identity_present', 'deposit_balance_clear', 'significant_terms_present'] as $field) {
            if (($evidence[$field] ?? false) !== true) { $blocks[] = str_replace('_', ' ', $field); }
        }
        if ($blocks !== []) { return ['result' => 'Block', 'reasons' => $blocks]; }
        return ($evidence['availability_caveat_present'] ?? false) === true
            ? ['result' => 'Pass', 'reasons' => []]
            : ['result' => 'Warning', 'reasons' => ['availability caveat present']];
    }
}
