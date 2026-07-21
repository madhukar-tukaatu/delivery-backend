<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class SetInitialPasswordController extends Controller
{
    public function store(
        Request $request
    ): JsonResponse {
        $data = $request->validate([
            'token' => [
                'required',
                'string',
            ],

            'email' => [
                'required',
                'email',
                'exists:users,email',
            ],

            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $data['email'],
                'token' => $data['token'],
                'password' => $data['password'],
                'password_confirmation' =>
                    $request->input(
                        'password_confirmation'
                    ),
            ],
            function (
                User $user,
                string $password
            ): void {
                $user->password = $password;

                $user->must_change_password = false;
                $user->is_active = true;
                $user->account_status =
                    User::ACCOUNT_ACTIVE;

                $user->email_verified_at =
                    $user->email_verified_at
                    ?? now();

                $user->setRememberToken(
                    Str::random(60)
                );

                $user->save();

                event(
                    new PasswordReset($user)
                );
            }
        );

        if (
            $status !==
            Password::PASSWORD_RESET
        ) {
            return response()->json([
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'message' =>
                'Password created successfully. You can now log in.',
        ]);
    }
}