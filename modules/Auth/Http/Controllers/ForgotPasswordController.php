<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

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

        // Send password reset link using Laravel's built-in Password facade
        $status = Password::broker()->sendResetLink(
            ['email' => $user->email]
        );

        if ($status === Password::RESET_LINK_SENT) {
            return ApiResponse::success(
                null,
                'If the email address exists, a password reset link has been sent.'
            );
        }

        return ApiResponse::error(
            'Password reset link could not be sent.',
            500
        );
    }
}
