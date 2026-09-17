<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\StaffAccountUpdatedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class ForgotPasswordController extends Controller
{
    public function __invoke(
        Request $request
    ): JsonResponse {
        $request->validate([
            'email' => [
                'required',
                'email',
            ],
        ]);

        $user = User::query()
            ->where('email', trim($request->email))
            ->first();

        if (!$user) {
            return ApiResponse::success(
                null,
                'If the email address exists, a password reset link has been sent.'
            );
        }

        // Check if user is active
        if (!$user->is_active) {
            return ApiResponse::error(
                'This account has been deactivated.',
                403
            );
        }

        // Generate a token for password reset
        $token = Str::random(60);
        
        // Store the token in password_reset_tokens table
        $passwordReset = \DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        // Send password reset notification
        $user->notify(
            new \Modules\Auth\Notifications\SendPasswordResetLink($token)
        );

        return ApiResponse::success(
            null,
            'If the email address exists, a password reset link has been sent.'
        );
    }
}
