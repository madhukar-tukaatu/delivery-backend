# Email Change Verification System

## Overview

This system adds secure email change handling for branch managers with three key safeguards:

1. **Email Verification** - Changes require confirmation via a verification link
2. **Audit Logging** - All email changes are tracked in `email_change_audits` table
3. **Old Email Preservation** - Original email is recorded before any change

## How It Works

### User Flow

1. **Admin changes email** on `/admin/branch-offices/[id]` page
2. **Backend stores pending change**:
   - Email change audit record created with `pending_verification` status
   - User's `pending_email` set to new email
   - Verification token generated and stored
3. **Manager receives verification email** at new address with verification link
4. **Manager clicks link** → Email is activated
5. **Old email can no longer login**

### Data Model

#### users table (new columns)
- `pending_email` - Temporary email awaiting verification
- `email_change_token` - Unique verification token (64 chars)
- `email_change_token_expires_at` - Token expiry (24 hours)
- `last_email_changed_at` - Timestamp of last successful email change

#### email_change_audits table (new)
| Column | Purpose |
|--------|---------|
| user_id | Manager user ID |
| branch_id | Associated branch |
| old_email | Email before change |
| new_email | Email being changed to |
| status | pending_verification / verified / rejected |
| changed_by_user_id | Admin who initiated change |
| verification_token | Unique token for email verification |
| verification_expires_at | When token expires |
| verified_at | When email was verified |
| applied_at | When email became active |
| ip_address | IP of change requester |
| user_agent | Browser/client info |

## API Endpoints

### 1. Update Branch Email (Initiates Verification)

```
PUT /api/v1/admin/branches/{id}
Content-Type: application/json

{
  "email": "newemail@example.com"
}
```

**Response:**
```json
{
  "message": "Branch updated successfully.",
  "data": { ... },
  "email_verification": {
    "status": "pending_verification",
    "message": "A verification link has been sent to the new email address.",
    "new_email": "newemail@example.com",
    "old_email": "oldemail@example.com",
    "expires_at": "2026-09-17T10:30:00Z",
    "resend_after_minutes": 1
  }
}
```

### 2. Verify Email Change

```
POST /api/v1/admin/branches/verify-email-change
Content-Type: application/json

{
  "token": "verification_token_from_email_link"
}
```

**Response (Success):**
```json
{
  "message": "Email change verified successfully.",
  "data": {
    "user_id": 42,
    "old_email": "oldemail@example.com",
    "new_email": "newemail@example.com"
  }
}
```

**Response (Error):**
```json
{
  "message": "Verification token has expired.",
  "reason": "token_expired"
}
```

### 3. Resend Verification Email

```
POST /api/v1/admin/branches/resend-email-verification
Content-Type: application/json

{
  "user_id": 42
}
```

## Deployment Steps

### 1. Run Migrations

```bash
php artisan migrate
```

This creates:
- `email_change_audits` table
- Adds columns to `users` table

### 2. Deploy Code

Push these files:
- `delivery-backend/modules/Branch/Services/BranchEmailVerificationService.php`
- `delivery-backend/modules/Branch/Http/Controllers/BranchController.php` (updated)
- `delivery-backend/modules/Branch/routes/api.php` (updated)

### 3. Verify Frontend Build

```bash
cd delivery-frontend
npm run build
```

## Testing End-to-End

### Step 1: Change Email

```bash
# Login as admin, navigate to /admin/branch-offices/21
# Edit "Business and manager details" section
# Change email from "manager@example.com" to "newemail@example.com"
# Click Save
```

**Expected Result:**
- Alert: "A verification link has been sent to newemail@example.com"
- Old email: "manager@example.com" shown
- Expiry time displayed

### Step 2: Check Audit Log

```bash
# Database query
SELECT * FROM email_change_audits 
WHERE user_id = 42 
ORDER BY created_at DESC LIMIT 1;
```

**Expected Result:**
```
old_email: manager@example.com
new_email: newemail@example.com
status: pending_verification
verification_token: <64-char token>
changed_by_user_id: <admin id>
verified_at: NULL
applied_at: NULL
```

### Step 3: Verify Email Change

```bash
# In real app, manager clicks verification link
# For testing, manually call verify endpoint:

curl -X POST http://localhost:8000/api/v1/admin/branches/verify-email-change \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token>" \
  -d '{"token": "<verification_token_from_db>"}'
```

**Expected Result:**
```json
{
  "message": "Email change verified successfully.",
  "data": {
    "user_id": 42,
    "old_email": "manager@example.com",
    "new_email": "newemail@example.com"
  }
}
```

### Step 4: Verify Database Updates

```bash
# Check users table
SELECT id, email, pending_email, email_change_token 
FROM users WHERE id = 42;

# Expected:
# email: newemail@example.com
# pending_email: NULL
# email_change_token: NULL
```

```bash
# Check audit table
SELECT * FROM email_change_audits 
WHERE user_id = 42 
ORDER BY created_at DESC LIMIT 1;

# Expected:
# status: verified
# verified_at: <current timestamp>
# applied_at: <current timestamp>
```

### Step 5: Verify Old Email Cannot Login

```bash
# Try logging in as manager@example.com
# Expected: "Invalid credentials"

# Try logging in as newemail@example.com
# Expected: Login succeeds
```

## Audit Query Examples

### View email change history for a user

```sql
SELECT 
  user_id,
  old_email,
  new_email,
  status,
  created_at,
  verified_at,
  applied_at
FROM email_change_audits
WHERE user_id = 42
ORDER BY created_at DESC;
```

### View all pending verifications

```sql
SELECT 
  user_id,
  branch_id,
  new_email,
  verification_expires_at,
  created_at
FROM email_change_audits
WHERE status = 'pending_verification'
  AND verification_expires_at > NOW()
ORDER BY created_at DESC;
```

### View rejected email changes

```sql
SELECT 
  user_id,
  old_email,
  new_email,
  rejection_reason,
  created_at
FROM email_change_audits
WHERE status = 'rejected'
ORDER BY created_at DESC;
```

## Troubleshooting

### Token Expired Error

If a verification token expires (24 hours), user must request a new email change.

**Manual reset (if needed):**
```sql
UPDATE users 
SET pending_email = NULL, 
    email_change_token = NULL,
    email_change_token_expires_at = NULL
WHERE id = 42;

UPDATE email_change_audits 
SET status = 'rejected',
    rejection_reason = 'Manually cancelled by admin'
WHERE user_id = 42 
  AND status = 'pending_verification';
```

### Verification Link Issues

If email link doesn't work, verify:

1. **Token exists in database:**
   ```sql
   SELECT * FROM users WHERE email_change_token = '<token>';
   ```

2. **Token hasn't expired:**
   ```sql
   SELECT DATEDIFF(HOUR, NOW(), email_change_token_expires_at) 
   FROM users WHERE email_change_token = '<token>';
   ```

3. **Resend verification:**
   ```bash
   POST /api/v1/admin/branches/resend-email-verification
   {
     "user_id": 42
   }
   ```

## Security Notes

1. **Token is unique** - 64 random characters, indexed for fast lookup
2. **Tokens expire** - 24 hours by default (configurable in service)
3. **IP tracking** - Change initiator IP and user agent logged
4. **Audit trail** - All changes permanently recorded with timestamps
5. **Old email preserved** - Never deleted, allows manual recovery
6. **One token per user** - Only one pending change at a time

## Email Integration (Future)

Currently, verification links are returned in API responses. To integrate with email:

1. Update `BranchEmailVerificationService::buildVerificationLink()` 
2. Send email via Laravel Notifications:
   ```php
   Mail::to($user->pending_email)->send(
       new EmailChangeVerification($verificationLink)
   );
   ```

## Rollback

If you need to revert this feature:

```bash
php artisan migrate:rollback --step=2
```

This will:
- Drop `email_change_audits` table
- Remove new columns from `users` table
- Restore system to previous state
