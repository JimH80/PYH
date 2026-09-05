<?php

declare(strict_types=1);

namespace PYH\Domain\Customer;

final class DuplicateDetector
{
    /** @param array{first_name?: string, last_name?: string, email?: string, phone?: string} $candidate
     *  @param list<array{id: int, first_name?: string, last_name?: string, email?: string, phone?: string}> $existing
     *  @return list<array{id: int, reasons: list<string>}>
     */
    public function findWarnings(array $candidate, array $existing): array
    {
        $warnings = [];
        foreach ($existing as $record) {
            $reasons = [];
            if ($this->email($candidate['email'] ?? '') !== '' && $this->email($candidate['email'] ?? '') === $this->email($record['email'] ?? '')) {
                $reasons[] = 'email';
            }
            if ($this->phone($candidate['phone'] ?? '') !== '' && $this->phone($candidate['phone'] ?? '') === $this->phone($record['phone'] ?? '')) {
                $reasons[] = 'phone';
            }
            $candidateName = $this->name($candidate['first_name'] ?? '', $candidate['last_name'] ?? '');
            if ($candidateName !== '' && $candidateName === $this->name($record['first_name'] ?? '', $record['last_name'] ?? '')) {
                $reasons[] = 'name';
            }
            if ($reasons !== []) {
                $warnings[] = ['id' => $record['id'], 'reasons' => $reasons];
            }
        }
        return $warnings;
    }

    private function email(string $value): string { return strtolower(trim($value)); }
    private function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return str_starts_with($digits, '44') ? '0' . substr($digits, 2) : $digits;
    }
    private function name(string $first, string $last): string { return strtolower(trim($first . ' ' . $last)); }
}
