<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;

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

        $email = trim($request->email);
        $user = User::query()->where('email', $email)->first();

        // Check if user exists
        if (!$user) {
            return ApiResponse::error(
                'No account found with this email address.',
                404
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
        $resetUrl = rtrim($frontendUrl, '/') . '/reset-password?email=' . urlencode($email) . '&token=' . urlencode($token);

        // Send password reset link via Mailable directly
        Mail::to($email, $user->name)->send(
            new \Modules\Auth\Mail\PasswordResetLinkMail($user, $resetUrl)
        );

        return ApiResponse::success(
            null,
            'A password reset link has been sent to your email address.'
        );
    }
}
