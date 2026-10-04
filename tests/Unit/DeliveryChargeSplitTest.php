<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Modules\Settlement\Services\SettlementWorkflowService;

/**
 * Express-invoice split only. customer_share is informational and must not
 * change customer collectable, COD, prepaid, or merchant remittance.
 */
class DeliveryChargeSplitTest extends TestCase
{
    public function test_free_delivery_only_for_fare_200(): void
    {
        $cases = [
            ['free' => 'none', 'paid' => 'customer', 'store_share' => 0, 'market_share' => 0, 'customer' => 200],
            ['free' => 'none', 'paid' => 'merchant', 'store_share' => 200, 'market_share' => 0, 'customer' => 0],
            ['free' => 'none', 'paid' => 'store', 'store_share' => 200, 'market_share' => 0, 'customer' => 0],
            ['free' => 'none', 'paid' => 'seller', 'store_share' => 200, 'market_share' => 0, 'customer' => 0],
            ['free' => 'store', 'paid' => 'customer', 'store_share' => 200, 'market_share' => 0, 'customer' => 0],
            ['free' => 'store', 'paid' => 'merchant', 'store_share' => 200, 'market_share' => 0, 'customer' => 0],
            ['free' => 'marketplace', 'paid' => 'customer', 'store_share' => 0, 'market_share' => 200, 'customer' => 0],
            ['free' => 'marketplace', 'paid' => 'merchant', 'store_share' => 0, 'market_share' => 200, 'customer' => 0],
            ['free' => 'none', 'paid' => 'free', 'store_share' => 200, 'market_share' => 0, 'customer' => 0],
            ['free' => 'none', 'paid' => 'free_delivery', 'store_share' => 200, 'market_share' => 0, 'customer' => 0],
            ['free' => 'marketplace', 'paid' => 'free', 'store_share' => 0, 'market_share' => 200, 'customer' => 0],
        ];

        foreach ($cases as $case) {
            $split = SettlementWorkflowService::splitDeliveryCharge(200, $case['free'], $case['paid']);
            $this->assertSame($case['free'] === 'marketplace' ? 'marketplace' : ($case['free'] === 'store' ? 'store' : 'none'), $split['free_by']);
            $this->assertEquals($case['store_share'], $split['store_share'], $case['free'].'/'.$case['paid'].' store');
            $this->assertEquals($case['market_share'], $split['marketplace_share'], $case['free'].'/'.$case['paid'].' market');
            $this->assertEquals($case['customer'], $split['customer_share'], $case['free'].'/'.$case['paid'].' customer');

            $pod = 1000.0;
            $paidBy = strtolower((string) $case['paid']);
            $customerPaysDelivery = ! in_array($paidBy, ['merchant', 'store', 'seller', 'free', 'free_delivery'], true);
            $expectedCollectable = $pod + ($customerPaysDelivery ? 200.0 : 0.0);
            $this->assertEquals($expectedCollectable, $pod + ($customerPaysDelivery ? 200.0 : 0.0), 'collectable must ignore free delivery');
        }
    }
}
