<?php

namespace Modules\Branch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use App\Models\User;
use Carbon\Carbon;

final class BranchEmailVerificationService
{
    public const TOKEN_EXPIRY_HOURS = 24;
    public const STATUS_PENDING = 'pending_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    /**
     * Initiate an email change request for a branch manager.
     * Creates an audit record and stores pending email with verification token.
     */
    public function initiateEmailChange(
        Branch $branch,
        User $manager,
        string $newEmail,
        ?User $changedByUser = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        return DB::transaction(function () use (
            $branch,
            $manager,
            $newEmail,
            $changedByUser,
            $ipAddress,
            $userAgent,
        ) {
            // Generate verification token
            $token = Str::random(64);
            $expiresAt = now()->addHours(self::TOKEN_EXPIRY_HOURS);

            // Create audit record
            $audit = DB::table('email_change_audits')->insertGetId([
                'user_id' => $manager->id,
                'branch_id' => $branch->id,
                'old_email' => $manager->email,
                'new_email' => $newEmail,
                'status' => self::STATUS_PENDING,
                'changed_by_user_id' => $changedByUser?->id,
                'verification_token' => $token,
                'verification_expires_at' => $expiresAt,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Store pending email and token on user
            $manager->update([
                'pending_email' => $newEmail,
                'email_change_token' => $token,
                'email_change_token_expires_at' => $expiresAt,
            ]);

            return [
                'audit_id' => $audit,
                'token' => $token,
                'old_email' => $manager->email,
                'new_email' => $newEmail,
                'expires_at' => $expiresAt,
                'verification_link' => $this->buildVerificationLink($token),
            ];
        });
    }

    /**
     * Verify the email change using the token from the email link.
     */
    public function verifyEmailChange(
        string $token,
        ?string $ipAddress = null,
    ): array {
        return DB::transaction(function () use ($token, $ipAddress) {
            // Find the user with this token
            $user = User::where('email_change_token', $token)->first();

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired verification token.',
                    'reason' => 'token_not_found',
                ];
            }

            // Check if token has expired
            if ($user->email_change_token_expires_at && $user->email_change_token_expires_at->isPast()) {
                // Mark audit as rejected
                DB::table('email_change_audits')
                    ->where('verification_token', $token)
                    ->where('status', self::STATUS_PENDING)
                    ->update([
                        'status' => self::STATUS_REJECTED,
                        'rejection_reason' => 'Token expired',
                        'updated_at' => now(),
                    ]);

                return [
                    'success' => false,
                    'message' => 'Verification token has expired.',
                    'reason' => 'token_expired',
                ];
            }

            if (!$user->pending_email) {
                return [
                    'success' => false,
                    'message' => 'No pending email change found.',
                    'reason' => 'no_pending_email',
                ];
            }

            $oldEmail = $user->email;
            $newEmail = $user->pending_email;

            // Apply the email change
            $user->update([
                'email' => $newEmail,
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_token_expires_at' => null,
                'last_email_changed_at' => now(),
            ]);

            // Mark audit as verified and applied
            DB::table('email_change_audits')
                ->where('verification_token', $token)
                ->where('status', self::STATUS_PENDING)
                ->update([
                    'status' => self::STATUS_VERIFIED,
                    'verified_at' => now(),
                    'applied_at' => now(),
                    'updated_at' => now(),
                ]);

            // Update branch manager email
            $branch = $user->branch;
            if ($branch) {
                $branch->update([
                    'email' => $newEmail,
                    'account_invitation_email' => $newEmail,
                    'account_invitation_status' => BranchAccountInvitationService::STATUS_QUEUED,
                ]);
            }

            return [
                'success' => true,
                'message' => 'Email change verified successfully.',
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'user_id' => $user->id,
            ];
        });
    }

    /**
     * Cancel a pending email change request.
     */
    public function rejectEmailChange(
        string $token,
        string $reason = 'Cancelled by user',
    ): array {
        return DB::transaction(function () use ($token, $reason) {
            $user = User::where('email_change_token', $token)->first();

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Invalid token.',
                ];
            }

            // Clear pending email and token
            $user->update([
                'pending_email' => null,
                'email_change_token' => null,
                'email_change_token_expires_at' => null,
            ]);

            // Mark audit as rejected
            DB::table('email_change_audits')
                ->where('verification_token', $token)
                ->where('status', self::STATUS_PENDING)
                ->update([
                    'status' => self::STATUS_REJECTED,
                    'rejection_reason' => $reason,
                    'updated_at' => now(),
                ]);

            return [
                'success' => true,
                'message' => 'Email change cancelled.',
            ];
        });
    }

    /**
     * Build the email verification link.
     */
    public function buildVerificationLink(string $token): string
    {
        $frontendUrl = config('app.frontend_url') ?? 'http://localhost:3000';
        return "{$frontendUrl}/verify-email-change?token={$token}";
    }

    /**
     * Get pending email change info for a user.
     */
    public function getPendingEmailChange(User $user): ?array
    {
        if (!$user->pending_email || !$user->email_change_token) {
            return null;
        }

        $isExpired = $user->email_change_token_expires_at?->isPast() ?? false;

        return [
            'pending_email' => $user->pending_email,
            'current_email' => $user->email,
            'token' => $user->email_change_token,
            'expires_at' => $user->email_change_token_expires_at,
            'is_expired' => $isExpired,
            'verification_link' => $this->buildVerificationLink($user->email_change_token),
        ];
    }

    /**
     * Get audit history for a user's email changes.
     */
    public function getEmailChangeHistory(User $user, int $limit = 10): array
    {
        return DB::table('email_change_audits')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Resend verification email for pending email change.
     */
    public function resendVerificationEmail(User $user): bool
    {
        if (!$user->pending_email || !$user->email_change_token) {
            return false;
        }

        $isPending = DB::table('email_change_audits')
            ->where('user_id', $user->id)
            ->where('verification_token', $user->email_change_token)
            ->where('status', self::STATUS_PENDING)
            ->exists();

        if (!$isPending) {
            return false;
        }

        // Send verification email (you can integrate with your notification service)
        // For now, just return true
        return true;
    }
}
