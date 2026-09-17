<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

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

        // Create password reset token
        $token = Password::broker()->createToken($user);

        // Build frontend reset URL
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?email=' . urlencode($user->email) . '&token=' . urlencode($token);

        // Send password reset link via notification
        $user->notify(
            new \Modules\Auth\Notifications\SendPasswordResetLink($token)
        );

        return ApiResponse::success(
            null,
            'If the email address exists, a password reset link has been sent.'
        );
    }
}
