<?php

namespace App\Support;

final class BrazilianDocuments
{
    public static function digits(string $value): string
    {
        return strtoupper(preg_replace('/[^0-9A-Z]/i', '', $value) ?? '');
    }

    public static function cpf(string $value): bool
    {
        $cpf = self::digits($value);
        if (preg_match('/^[0-9]{11}$/D', $cpf) !== 1 || preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }

        for ($length = 9; $length <= 10; $length++) {
            $sum = 0;
            for ($index = 0; $index < $length; $index++) {
                $sum += (int) $cpf[$index] * ($length + 1 - $index);
            }
            $digit = ($sum * 10) % 11;
            $digit = $digit === 10 ? 0 : $digit;
            if ($digit !== (int) $cpf[$length]) {
                return false;
            }
        }

        return true;
    }

    public static function cnpj(string $value): bool
    {
        $cnpj = self::digits($value);
        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $cnpj) || preg_match('/^(.)\1+$/', $cnpj)) {
            return false;
        }

        foreach ([12 => 5, 13 => 6] as $length => $factor) {
            $sum = 0;
            for ($index = 0; $index < $length; $index++) {
                $sum += (ord($cnpj[$index]) - 48) * $factor;
                $factor = $factor === 2 ? 9 : $factor - 1;
            }
            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($digit !== (int) $cnpj[$length]) {
                return false;
            }
        }

        return true;
    }
}
