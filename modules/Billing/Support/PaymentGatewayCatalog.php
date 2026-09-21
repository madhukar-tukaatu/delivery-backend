<?php

namespace Modules\Billing\Support;

/**
 * Shared gateway catalog + credential field schemas for Payment Gateways settings.
 * Frontend should render forms from this catalog.
 */
class PaymentGatewayCatalog
{
    public static function gateways(): array
    {
        return [
            [
                'code' => 'hamropay',
                'label' => 'HamroPay',
                'status' => 'live',
                'description' => 'Company and branch HamroPay merchant credentials used for payouts and checkouts.',
                'fields' => [
                    self::field('api_base_url', 'API base URL', 'url', false, 'https://…'),
                    self::field('gateway_url', 'Gateway / checkout URL', 'url', false, 'https://…'),
                    self::field('merchant_id', 'Merchant ID', 'text', false),
                    self::field('client_id', 'Client ID', 'text', false),
                    self::field('client_api_key', 'Client API key', 'password', true, 'Leave blank to keep existing'),
                    self::field('secret', 'Secret', 'password', true, 'Leave blank to keep existing'),
                    self::field('webhook_secret', 'Webhook secret', 'password', true, 'Leave blank to keep existing'),
                    self::field('verify_ssl', 'Verify SSL', 'boolean', false, null, true),
                ],
            ],
            [
                'code' => 'esewa',
                'label' => 'eSewa',
                'status' => 'ready',
                'description' => 'eSewa merchant credentials. Payout / checkout drivers can plug in later.',
                'fields' => [
                    self::field('merchant_code', 'Merchant code', 'text', false),
                    self::field('product_code', 'Product code', 'text', false),
                    self::field('secret_key', 'Secret key', 'password', true, 'Leave blank to keep existing'),
                    self::field('api_base_url', 'API base URL', 'url', false, 'https://…'),
                    self::field('success_url', 'Success URL', 'url', false),
                    self::field('failure_url', 'Failure URL', 'url', false),
                ],
            ],
            [
                'code' => 'khalti',
                'label' => 'Khalti',
                'status' => 'ready',
                'description' => 'Khalti public/secret keys for the company or branch wallet.',
                'fields' => [
                    self::field('public_key', 'Public key', 'text', false),
                    self::field('secret_key', 'Secret key', 'password', true, 'Leave blank to keep existing'),
                    self::field('api_base_url', 'API base URL', 'url', false, 'https://…'),
                    self::field('webhook_secret', 'Webhook secret', 'password', true, 'Leave blank to keep existing'),
                    self::field('return_url', 'Return URL', 'url', false),
                    self::field('website_url', 'Website URL', 'url', false),
                ],
            ],
            [
                'code' => 'connectips',
                'label' => 'ConnectIPS',
                'status' => 'ready',
                'description' => 'Nepsé ConnectIPS merchant app credentials.',
                'fields' => [
                    self::field('merchant_id', 'Merchant ID', 'text', false),
                    self::field('app_id', 'App ID', 'text', false),
                    self::field('app_name', 'App name', 'text', false),
                    self::field('basic_auth_username', 'Basic auth username', 'text', false),
                    self::field('basic_auth_password', 'Basic auth password', 'password', true, 'Leave blank to keep existing'),
                    self::field('api_base_url', 'API base URL', 'url', false, 'https://…'),
                    self::field('success_url', 'Success URL', 'url', false),
                    self::field('failure_url', 'Failure URL', 'url', false),
                ],
            ],
        ];
    }

    public static function owners(): array
    {
        return [
            ['type' => 'company', 'label' => 'Tukaatu Express (super admin)', 'roles' => ['super_admin', 'main_admin']],
            ['type' => 'branch', 'label' => 'Branch', 'roles' => ['super_admin', 'main_admin', 'admin', 'branch_manager', 'sub_branch_manager']],
        ];
    }

    public static function codes(): array
    {
        return array_column(self::gateways(), 'code');
    }

    public static function fieldKeys(string $gateway): array
    {
        foreach (self::gateways() as $g) {
            if ($g['code'] === strtolower($gateway)) {
                return array_column($g['fields'], 'key');
            }
        }

        return [];
    }

    private static function field(
        string $key,
        string $label,
        string $type,
        bool $secret,
        ?string $placeholder = null,
        mixed $default = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'secret' => $secret,
            'placeholder' => $placeholder,
            'default' => $default,
        ];
    }
}
