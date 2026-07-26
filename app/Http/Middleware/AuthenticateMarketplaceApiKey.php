<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuthenticateMarketplaceApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $publicKey = trim((string) $request->header(
            'X-Tukaatu-Marketplace-Key'
        ));

        $timestampHeader = trim((string) $request->header(
            'X-Tukaatu-Timestamp'
        ));

        $requestId = trim((string) $request->header(
            'X-Tukaatu-Request-Id'
        ));

        $providedSignature = strtolower(trim((string) $request->header(
            'X-Tukaatu-Signature'
        )));

        if (str_starts_with($providedSignature, 'sha256=')) {
            $providedSignature = substr($providedSignature, 7);
        }

        if (
            $publicKey === '' ||
            $timestampHeader === '' ||
            $requestId === '' ||
            $providedSignature === ''
        ) {
            return $this->error(
                'Marketplace authentication headers are required.',
                401
            );
        }

        if (!ctype_digit($timestampHeader)) {
            return $this->error(
                'X-Tukaatu-Timestamp must be a Unix timestamp.',
                401
            );
        }

        $requestTimestamp = (int) $timestampHeader;

        /*
         * Be developer-friendly when a client accidentally sends
         * milliseconds. The original header value is still signed.
         */
        $comparisonTimestamp = $requestTimestamp > 9_999_999_999
            ? (int) floor($requestTimestamp / 1000)
            : $requestTimestamp;

        $serverTimestamp = now()->timestamp;
        $differenceSeconds = abs(
            $serverTimestamp - $comparisonTimestamp
        );

        $allowedDifference = max(
            30,
            (int) config(
                'marketplace.signature_tolerance_seconds',
                300
            )
        );

        if ($differenceSeconds > $allowedDifference) {
            return response()->json([
                'success' => false,
                'message' => 'The marketplace request has expired.',
                'debug' => app()->isLocal()
                    ? [
                        'received_timestamp' => $comparisonTimestamp,
                        'server_timestamp' => $serverTimestamp,
                        'difference_seconds' => $differenceSeconds,
                        'allowed_difference_seconds' => $allowedDifference,
                    ]
                    : null,
            ], 401);
        }

        $apiKey = DB::table('marketplace_api_keys')
            ->where('key_hash', hash('sha256', $publicKey))
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$apiKey) {
            return $this->error(
                'The marketplace API key is invalid or inactive.',
                401
            );
        }

        $marketplace = DB::table('marketplaces')
            ->where('id', $apiKey->marketplace_id)
            ->where('is_active', true)
            ->first();

        if (!$marketplace) {
            return $this->error(
                'The marketplace account is inactive.',
                403
            );
        }

        $scopes = $this->decodeScopes($apiKey->scopes ?? null);

        if (
            !empty($scopes) &&
            !in_array('*', $scopes, true) &&
            !in_array('pricing', $scopes, true)
        ) {
            return $this->error(
                'This marketplace key cannot access pricing APIs.',
                403
            );
        }

        try {
            $secret = Crypt::decryptString(
                (string) $apiKey->secret_encrypted
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                'The marketplace credentials could not be verified.',
                401
            );
        }

        $rawBody = $request->getContent();
        $bodyHash = hash('sha256', $rawBody);
        $method = strtoupper($request->method());
        $path = $request->getPathInfo();

        $canonicalRequest = implode("\n", [
            $timestampHeader,
            $requestId,
            $method,
            $path,
            $bodyHash,
        ]);

        $expectedSignature = hash_hmac(
            'sha256',
            $canonicalRequest,
            $secret
        );

        if (!hash_equals($expectedSignature, $providedSignature)) {
            return response()->json([
                'success' => false,
                'message' => 'The marketplace request signature is invalid.',
                'debug' => app()->isLocal()
                    ? [
                        'method' => $method,
                        'path' => $path,
                        'body_sha256' => $bodyHash,
                        'canonical_request' => $canonicalRequest,
                    ]
                    : null,
            ], 401);
        }

        $replayKey = sprintf(
            'marketplace-request:%d:%s',
            (int) $apiKey->id,
            hash('sha256', $requestId)
        );

        $replayTtl = max(
            $allowedDifference + 60,
            (int) config('marketplace.replay_ttl_seconds', 420)
        );

        if (!Cache::add($replayKey, true, $replayTtl)) {
            return $this->error(
                'This marketplace request has already been processed.',
                409
            );
        }

        /*
         * Avoid a database write for every pricing request.
         */
        $lastUsedCacheKey = 'marketplace-key-last-used:' . $apiKey->id;

        if (Cache::add($lastUsedCacheKey, true, 300)) {
            DB::table('marketplace_api_keys')
                ->where('id', $apiKey->id)
                ->update([
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $request->attributes->set(
            'marketplace_id',
            (int) $marketplace->id
        );

        $request->attributes->set(
            'marketplace_api_key_id',
            (int) $apiKey->id
        );

        return $next($request);
    }

    private function decodeScopes(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values($decoded)
            : [];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
