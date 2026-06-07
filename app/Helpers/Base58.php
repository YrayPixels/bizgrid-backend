<?php

namespace App\Helpers;

/**
 * Base58 encoding (Bitcoin/Solana alphabet) for Solana public keys.
 */
final class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $binary): string
    {
        $size = strlen($binary);
        if ($size === 0) {
            return '';
        }
        $digits = [0];
        for ($i = 0; $i < $size; $i++) {
            $carry = ord($binary[$i]);
            for ($j = 0; $j < count($digits); $j++) {
                $carry += $digits[$j] << 8;
                $digits[$j] = $carry % 58;
                $carry = (int) ($carry / 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = (int) ($carry / 58);
            }
        }
        $result = '';
        for ($i = 0; $i < $size && $binary[$i] === "\0"; $i++) {
            $result .= self::ALPHABET[0];
        }
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $result .= self::ALPHABET[$digits[$i]];
        }
        return $result;
    }

    public static function decode(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            throw new \InvalidArgumentException('Empty base58 string');
        }

        $bytes = array_fill(0, strlen($encoded), 0);
        for ($i = 0; $i < strlen($encoded); $i++) {
            $char = $encoded[$i];
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Invalid base58 character');
            }
            $carry = $pos;
            for ($j = 0; $j < count($bytes); $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                array_unshift($bytes, $carry & 0xff);
                $carry >>= 8;
            }
        }

        $leadingZeros = 0;
        for ($i = 0; $i < strlen($encoded) && $encoded[$i] === self::ALPHABET[0]; $i++) {
            $leadingZeros++;
        }

        return str_repeat("\0", $leadingZeros).pack('C*', ...$bytes);
    }
}
