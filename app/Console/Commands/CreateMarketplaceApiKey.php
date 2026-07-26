<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class CreateMarketplaceApiKey extends Command
{
    protected $signature = 'marketplace:key:create
        {name : Marketplace name}
        {--code= : Unique marketplace code}
        {--environment=test : test or live}';

    protected $description =
        'Create a marketplace account and API credentials';

    public function handle(): int
    {
        $name = trim(
            (string) $this->argument('name')
        );

        $code = trim(
            (string) $this->option('code')
        );

        $environment = strtolower(
            trim(
                (string) $this->option(
                    'environment'
                )
            )
        );

        if ($name === '') {
            $this->error(
                'Marketplace name is required.'
            );

            return self::FAILURE;
        }

        if (!in_array(
            $environment,
            ['test', 'live'],
            true
        )) {
            $this->error(
                'Environment must be test or live.'
            );

            return self::FAILURE;
        }

        if ($code === '') {
            $code = Str::slug($name);
        }

        $publicPrefix = $environment === 'live'
            ? 'mkp_live_'
            : 'mkp_test_';

        $secretPrefix = $environment === 'live'
            ? 'mks_live_'
            : 'mks_test_';

        /*
         * Generate a unique public key.
         */
        do {
            $publicKey =
                $publicPrefix .
                Str::lower(Str::random(48));

            $keyHash = hash(
                'sha256',
                $publicKey
            );

            $keyExists = DB::table(
                'marketplace_api_keys'
            )
                ->where('key_hash', $keyHash)
                ->exists();
        } while ($keyExists);

        /*
         * This is safe to store and display.
         * The full public key is never stored in plaintext.
         */
        $keyPrefix = substr(
            $publicKey,
            0,
            20
        );

        $secret =
            $secretPrefix .
            Str::random(64);

        try {
            $result = DB::transaction(
                function () use (
                    $name,
                    $code,
                    $environment,
                    $publicKey,
                    $keyHash,
                    $keyPrefix,
                    $secret
                ): array {
                    $marketplace = DB::table(
                        'marketplaces'
                    )
                        ->where('code', $code)
                        ->lockForUpdate()
                        ->first();

                    if ($marketplace) {
                        $marketplaceId =
                            (int) $marketplace->id;

                        DB::table('marketplaces')
                            ->where(
                                'id',
                                $marketplaceId
                            )
                            ->update([
                                'name' =>
                                    $name,

                                'is_active' =>
                                    true,

                                'updated_at' =>
                                    now(),
                            ]);
                    } else {
                        $marketplaceData = [
                            'name' =>
                                $name,

                            'code' =>
                                $code,

                            'is_active' =>
                                true,

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ];

                        /*
                         * Support schemas containing an
                         * environment column.
                         */
                        if (
                            Schema::hasColumn(
                                'marketplaces',
                                'environment'
                            )
                        ) {
                            $marketplaceData['environment'] =
                                $environment;
                        }

                        $marketplaceId = DB::table(
                            'marketplaces'
                        )->insertGetId(
                            $marketplaceData
                        );
                    }

                    $apiKeyData = [
                        'marketplace_id' =>
                            $marketplaceId,

                        /*
                         * Required by your current schema.
                         */
                        'key_prefix' =>
                            $keyPrefix,

                        /*
                         * Full public key is stored only
                         * as a one-way hash.
                         */
                        'key_hash' =>
                            $keyHash,

                        /*
                         * Secret must be reversible because
                         * it is used to validate HMAC.
                         */
                        'secret_encrypted' =>
                            Crypt::encryptString(
                                $secret
                            ),

                        'is_active' =>
                            true,

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ];

                    /*
                     * Only add optional fields that exist
                     * in the current database schema.
                     */
                    if (
                        Schema::hasColumn(
                            'marketplace_api_keys',
                            'environment'
                        )
                    ) {
                        $apiKeyData['environment'] =
                            $environment;
                    }

                    if (
                        Schema::hasColumn(
                            'marketplace_api_keys',
                            'name'
                        )
                    ) {
                        $apiKeyData['name'] =
                            "{$name} {$environment} key";
                    }

                    if (
                        Schema::hasColumn(
                            'marketplace_api_keys',
                            'abilities'
                        )
                    ) {
                        $apiKeyData['abilities'] =
                            json_encode([
                                'pricing.check',
                                'pricing.quotes.create',
                                'pricing.quotes.view',
                            ], JSON_THROW_ON_ERROR);
                    }

                    if (
                        Schema::hasColumn(
                            'marketplace_api_keys',
                            'revoked_at'
                        )
                    ) {
                        $apiKeyData['revoked_at'] =
                            null;
                    }

                    if (
                        Schema::hasColumn(
                            'marketplace_api_keys',
                            'last_used_at'
                        )
                    ) {
                        $apiKeyData['last_used_at'] =
                            null;
                    }

                    if (
                        Schema::hasColumn(
                            'marketplace_api_keys',
                            'expires_at'
                        )
                    ) {
                        $apiKeyData['expires_at'] =
                            null;
                    }

                    $apiKeyId = DB::table(
                        'marketplace_api_keys'
                    )->insertGetId(
                        $apiKeyData
                    );

                    return [
                        'marketplace_id' =>
                            $marketplaceId,

                        'api_key_id' =>
                            $apiKeyId,

                        'public_key' =>
                            $publicKey,

                        'secret' =>
                            $secret,

                        'key_prefix' =>
                            $keyPrefix,
                    ];
                },
                3
            );
        } catch (Throwable $exception) {
            report($exception);

            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->newLine();

        $this->info(
            'Marketplace credentials created successfully.'
        );

        $this->table(
            ['Field', 'Value'],
            [
                [
                    'Marketplace ID',
                    $result['marketplace_id'],
                ],
                [
                    'API Key ID',
                    $result['api_key_id'],
                ],
                [
                    'Environment',
                    $environment,
                ],
                [
                    'Key prefix',
                    $result['key_prefix'],
                ],
                [
                    'Public key',
                    $result['public_key'],
                ],
                [
                    'Secret',
                    $result['secret'],
                ],
            ]
        );

        $this->warn(
            'Copy the public key and secret now. The secret will not be displayed again.'
        );

        return self::SUCCESS;
    }
}