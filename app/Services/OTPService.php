<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OTPService
{
    /**
     * Generate and store a numeric OTP
     */
    public function generate($key, $expiresInSeconds = 300)
    {
        // Generate a 6-digit code
        $otp = sprintf("%06d", mt_rand(0, 999999));
        
        Cache::put("otp_{$key}", $otp, $expiresInSeconds);
        
        return $otp;
    }

    /**
     * Verify the provided OTP against the stored one
     */
    public function verify($key, $otp)
    {
        $storedOtp = Cache::get("otp_{$key}");

        if ($storedOtp && (string)$storedOtp === (string)$otp) {
            // Once verified, we remove it to prevent reuse
            Cache::forget("otp_{$key}");
            return true;
        }

        return false;
    }

    /**
     * Delete an existing OTP
     */
    public function clear($key)
    {
        Cache::forget("otp_{$key}");
    }
}
