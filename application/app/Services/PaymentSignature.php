<?php

namespace App\Services;

class PaymentSignature
{
    public function sign(array $data, string $secret): string
    {
        ksort($data);
        $parts = [];
        foreach ($data as $key => $value) {
            $parts[] = $key;
            $parts[] = (string) $value;
        }

        return hash_hmac('sha256', implode('|', $parts), $secret);
    }

    public function verify(array $data, string $signature, string $secret): bool
    {
        if ($secret === '' || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return false;
        }

        foreach ($data as $value) {
            if (! is_string($value) && ! is_int($value)) {
                return false;
            }
        }

        return hash_equals($this->sign($data, $secret), $signature);
    }
}
