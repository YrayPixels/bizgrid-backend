<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MerchantEmailVerificationCodeEmail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MerchantEmailVerificationService
{
    /**
     * Generate, store, and email a verification code.
     *
     * @return bool True when the mailer accepted the message.
     */
    public function sendCode(User $user): bool
    {
        $code = (string) random_int(100000, 999999);
        $user->verification_code = Hash::make($code);
        $user->verification_code_expires_at = now()->addMinutes(15);
        $user->save();

        $mailer = (string) config('mail.default', 'log');
        if ($mailer === 'log' && ! app()->environment('local', 'testing')) {
            Log::error('Merchant verification email skipped: MAIL_MAILER is log in a non-local environment', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return false;
        }

        try {
            Mail::to($user->email)->send(new MerchantEmailVerificationCodeEmail($user, $code));
        } catch (\Throwable $e) {
            Log::warning('Failed to send merchant email verification code', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (app()->environment('local')) {
            Log::info('Merchant email verification code', ['email' => $user->email, 'code' => $code]);
        }

        return true;
    }
}
