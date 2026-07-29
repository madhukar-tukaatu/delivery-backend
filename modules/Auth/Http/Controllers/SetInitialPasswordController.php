<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Branch\Services\BranchAccountInvitationService;

final class SetInitialPasswordController extends Controller
{
    public function __invoke(
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
            ],

            'password' => [
                'required',
                'string',
                'confirmed',

                PasswordRule::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],

            /*
             * This must be validated explicitly so it
             * is included in the validated $data array.
             */
            'password_confirmation' => [
                'required',
                'string',
            ],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => [
                    'The account setup link is invalid or has expired.',
                ],
            ]);
        }

        $branch = Branch::query()
            ->where(
                'manager_user_id',
                $user->id
            )
            ->whereIn(
                'status',
                [
                    Branch::STATUS_APPROVED,
                    Branch::STATUS_ACTIVE,
                ]
            )
            ->first();

        if (!$branch) {
            throw ValidationException::withMessages([
                'email' => [
                    'This account is not linked to an approved franchise branch.',
                ],
            ]);
        }

        /*
         * When setup was already completed, send the
         * manager directly to the login page.
         */
        if (
            $user->account_setup_completed_at !==
            null
        ) {
            return response()->json([
                'success' => true,

                'message' =>
                    'This account has already been configured. You can now sign in.',

                'redirect_url' =>
                    '/login?account_setup=success&email='
                    . rawurlencode($user->email),
            ]);
        }

        $status = Password::broker()->reset(
            [
                'email' =>
                    $data['email'],

                'password' =>
                    $data['password'],

                'password_confirmation' =>
                    $data['password_confirmation'],

                'token' =>
                    $data['token'],
            ],

            function (
                User $resetUser,
                string $password
            ) use ($branch): void {
                $resetUser->forceFill([
                    'password' =>
                        Hash::make($password),

                    'email_verified_at' =>
                        $resetUser->email_verified_at
                        ?: now(),

                    'account_setup_completed_at' =>
                        now(),
                ]);

                $resetUser->setRememberToken(
                    Str::random(60)
                );

                $resetUser->save();

                /*
                 * Remove any existing Sanctum tokens so the
                 * manager signs in again with the new password.
                 */
                if (
                    method_exists(
                        $resetUser,
                        'tokens'
                    )
                ) {
                    $resetUser
                        ->tokens()
                        ->delete();
                }

                $branch->forceFill([
                    'account_invitation_status' =>
                        BranchAccountInvitationService::
                            STATUS_ACCOUNT_CONFIGURED,

                    'account_invitation_email' =>
                        $resetUser->email,

                    'account_invitation_failed_at' =>
                        null,

                    'account_invitation_error' =>
                        null,
                ])->save();

                event(
                    new PasswordReset(
                        $resetUser
                    )
                );
            }
        );

        if (
            $status !==
            Password::PASSWORD_RESET
        ) {
            throw ValidationException::withMessages([
                'email' => [
                    __($status),
                ],
            ]);
        }

        return response()->json([
            'success' => true,

            'message' =>
                'Your password has been created successfully. You can now sign in.',

            'redirect_url' =>
                '/login?account_setup=success&email='
                . rawurlencode(
                    $data['email']
                ),
        ]);
    }
}