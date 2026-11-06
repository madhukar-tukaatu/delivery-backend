<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SeedMillionShipmentsCommand extends Command
{
    protected $signature = 'transfers:seed-million-shipments
                            {--count=1000000 : Total shipments to generate}
                            {--chunk=1000 : Batch size for memory safety}
                            {--months=12 : Months of progressive historical data}
                            {--fresh : Clear previous demo/seed shipment data before running}';

    protected $description = 'Generate full production-grade delivery data across all tables with 350+ merchants, 65 branches, 120+ riders, full accounting (expenses, earnings, shares, invoices, receipts, rider deposits, inter-branch statements, settlements)';

    protected int $baseShipmentId = 0;
    protected int $baseManifestId = 0;
    protected int $baseTripId = 0;
    protected int $baseRunId = 0;
    protected int $baseDepositId = 0;
    protected int $baseSettlementId = 0;
    protected int $baseInvoiceId = 0;
    protected int $baseReceiptId = 0;
    protected int $basePickupId = 0;
    protected int $basePickupRunId = 0;
    protected int $baseAssignmentId = 0;

    protected int $manifestSeq = 0;
    protected int $tripSeq = 0;
    protected int $deliveryRunSeq = 0;
    protected int $depositSeq = 0;
    protected int $settlementSeq = 0;
    protected int $invoiceSeq = 0;
    protected int $receiptSeq = 0;
    protected int $pickupSeq = 0;
    protected int $pickupRunSeq = 0;
    protected int $assignmentSeq = 0;

    public function handle(): int
    {
        $totalTarget = (int) $this->option('count');
        $chunkSize = max(500, min(3000, (int) $this->option('chunk')));
        $months = max(1, min(24, (int) $this->option('months')));

        if ($this->option('fresh')) {
            $this->cleanPreviousSeedData();
        }

        $this->baseShipmentId = (int) DB::table('shipments')->max('id');
        $this->baseManifestId = (int) (Schema::hasTable('dispatch_manifests') ? DB::table('dispatch_manifests')->max('id') : 0);
        $this->baseTripId = (int) (Schema::hasTable('transport_trips') ? DB::table('transport_trips')->max('id') : 0);
        $this->baseRunId = (int) (Schema::hasTable('delivery_runs') ? DB::table('delivery_runs')->max('id') : 0);
        $this->baseDepositId = (int) (Schema::hasTable('rider_cash_deposits') ? DB::table('rider_cash_deposits')->max('id') : 0);
        $this->baseSettlementId = (int) (Schema::hasTable('merchant_settlements') ? DB::table('merchant_settlements')->max('id') : 0);
        $this->baseInvoiceId = (int) (Schema::hasTable('invoices') ? DB::table('invoices')->max('id') : 0);
        $this->baseReceiptId = (int) (Schema::hasTable('payment_receipts') ? DB::table('payment_receipts')->max('id') : 0);
        $this->basePickupId = (int) (Schema::hasTable('pickup_requests') ? DB::table('pickup_requests')->max('id') : 0);
        $this->basePickupRunId = (int) (Schema::hasTable('pickup_runs') ? DB::table('pickup_runs')->max('id') : 0);
        $this->baseAssignmentId = (int) (Schema::hasTable('delivery_assignments') ? DB::table('delivery_assignments')->max('id') : 0);

        $this->info("==========================================================================");
        $this->info("   TUKAATU END-TO-END ECOSYSTEM GENERATOR: {$totalTarget} SHIPMENTS");
        $this->info("   Progressive: {$months} Months | Full Lifecycles: Success, Failures, Returns, RTO");
        $this->info("   Full Financials: Expenses, Earnings, Branch Shares, Invoices, Receipts, Deposits");
        $this->info("==========================================================================");

        // 1. Audit & Ensure 65+ Nationwide Branches
        $branches = $this->ensureBranches();
        $branchIds = $branches->pluck('id')->all();
        $this->info(sprintf("✓ Active Network Footprint: %d branches across all 7 provinces of Nepal.", count($branchIds)));

        // 2. Audit & Ensure 350+ Realistic Tiered Merchants
        $merchantMap = $this->ensureMerchantsAndLocations($branches);
        $this->info(sprintf("✓ Commercial Footprint: %d active merchants across 3 volume tiers (Enterprise, Mid-Market, Social D2C).", count($merchantMap)));

        // Build weighted merchant selection table (Pareto distribution)
        $weightedMerchantIndices = [];
        foreach ($merchantMap as $idx => $m) {
            $w = $m['weight'] ?? 1;
            for ($k = 0; $k < $w; $k++) {
                $weightedMerchantIndices[] = $idx;
            }
        }
        $weightedMerchantCount = count($weightedMerchantIndices);

        // Build weighted destination branch selection table (Population / Demand density)
        $weightedDestBranches = $this->buildWeightedDestBranches($branches);
        $weightedDestCount = count($weightedDestBranches);

        // 3. Ensure Fleet Vehicles & Commercial Drivers
        $fleet = $this->ensureFleet($branchIds);
        $this->info(sprintf("✓ Line-Haul Highway Fleet: %d transport vehicles (22ft/14ft/Bolero/Vans) & %d commercial drivers.", count($fleet['vehicles']), count($fleet['drivers'])));

        // 4. Ensure Branch Riders & Delivery Staff
        $riderBranchMap = $this->ensureRiders($branches);
        $totalRiders = array_sum(array_map('count', $riderBranchMap));
        $this->info(sprintf("✓ Last-Mile Workforce: %d active delivery riders distributed across %d branches.", $totalRiders, count($riderBranchMap)));

        // 5. Ensure Transfer Highway Corridors & Routing Lanes
        $this->ensureTransferLanesAndRoutes($branches);

        // 6. Ensure 500+ Registered Retail Customers
        $this->ensureCustomers($merchantMap, $branches);

        // 7. Ensure Pricing Quotes
        $this->ensurePricingQuotes($branches, $merchantMap);

        // 5. Monthly progressive volume curve (12 months growth: 30k -> 140k/month)
        $weights = [2, 3, 4, 5, 6, 8, 9, 11, 12, 13, 13, 14];
        $weightSum = array_sum(array_slice($weights, 0, $months));

        $nepalNames = [
            'Aarav Sharma', 'Suman Shrestha', 'Pooja Thapa', 'Bibek Adhikari', 'Sunil Gurung',
            'Anjali KC', 'Ramesh Dahal', 'Prakash Tamang', 'Bishal Poudel', 'Deepa Khadka',
            'Siddhartha Joshi', 'Anita Maharjan', 'Prashant Rai', 'Kiran Giri', 'Sita Magar',
            'Roshan Gautam', 'Sarita Acharya', 'Manish Yadav', 'Kabita Bhattarai', 'Binod Neupane',
            'Pradeep Karki', 'Mina Shrestha', 'Dipesh Baral', 'Sushma Basnet', 'Gopal Regmi',
            'Bijay Shrestha', 'Alisha Shakya', 'Nabin KC', 'Smriti Subedi', 'Ashish Thapa',
            'Kopila Chaudhary', 'Santosh Pandit', 'Reena Maharjan', 'Suraj Bajracharya', 'Mandira Rijal',
            'Dhiraj Shah', 'Laxmi Paudel', 'Hemant Khatri', 'Pramila Karki', 'Bipin Adhikari',
        ];

        DB::disableQueryLog();
        $startTime = microtime(true);
        $generated = 0;

        $this->output->progressStart($totalTarget);

        for ($m = 0; $m < $months; $m++) {
            $monthOffset = $months - 1 - $m;
            $monthTarget = (int) round(($weights[$m] / $weightSum) * $totalTarget);
            $monthDate = now()->subMonths($monthOffset);

            $this->newLine();
            $this->line(sprintf("── Generating Month %d/%d [%s] : Target ~%d parcels ──", $m + 1, $months, $monthDate->format('M Y'), $monthTarget));

            $monthGenerated = 0;
            while ($monthGenerated < $monthTarget && $generated < $totalTarget) {
                $batchSize = min($chunkSize, $monthTarget - $monthGenerated, $totalTarget - $generated);

                $shipmentRows = [];
                $trackingEvents = [];
                $priceBreakdowns = [];
                $sharesRows = [];
                $podRows = [];
                $podCollectionRows = [];
                $commissionBills = [];
                $deliveryAttempts = [];
                $deliveryAssignments = [];
                $settlementItems = [];
                $crossBranchShipmentIds = [];
                $deliveredShipments = [];

                for ($i = 0; $i < $batchSize; $i++) {
                    $globalIndex = $generated + $i + 1;

                    // Weighted merchant selection (Enterprise vs Mid-Market vs Boutique)
                    $merchantIdx = $weightedMerchantIndices[rand(0, $weightedMerchantCount - 1)];
                    $merchant = $merchantMap[$merchantIdx];
                    $originBranchId = $merchant['branch_id'] ?: $branches->random()->id;

                    // Weighted destination selection
                    $destBranch = $weightedDestBranches[rand(0, $weightedDestCount - 1)];
                    $destBranchId = $destBranch->id;
                    $isCrossBranch = $originBranchId !== $destBranchId;

                    $dayOffset = rand(1, 28);
                    $createdAt = (clone $monthDate)->startOfMonth()->addDays($dayOffset)->addHours(rand(8, 20))->addMinutes(rand(0, 59));

                    // Service type
                    $rServ = rand(1, 100);
                    $service = $rServ <= 75 ? 'standard' : ($rServ <= 93 ? 'express' : 'same_day');

                    // Lifecycle status with Cancellations, Failures & Returns:
                    $cancelledAt = null;
                    $deliveredAt = null;
                    $returnCharge = 0.00;
                    $failedAttempts = 0;
                    $remarks = null;

                    if ($monthOffset > 1) {
                        // Older months: 82% delivered, 6% returned (RTO), 5% delivery reattempted & delivered, 4% cancelled, 3% return initiated
                        $r = rand(1, 100);
                        if ($r <= 82) {
                            $status = 'delivered';
                            $merchantStatus = 'delivered';
                            $podStatus = 'received_by_branch';
                            $settlementStatus = 'settled';
                            $deliveredAt = (clone $createdAt)->addDays(rand(1, 3));
                        } elseif ($r <= 88) {
                            // Delivered after 1 or 2 failed reattempts
                            $status = 'delivered';
                            $merchantStatus = 'delivered';
                            $podStatus = 'received_by_branch';
                            $settlementStatus = 'settled';
                            $failedAttempts = rand(1, 2);
                            $deliveredAt = (clone $createdAt)->addDays(rand(3, 5));
                            $remarks = 'Delivered on reattempt after initial reschedule';
                        } elseif ($r <= 94) {
                            // Returned to Origin (RTO complete)
                            $status = 'returned';
                            $merchantStatus = 'returned';
                            $podStatus = 'cancelled';
                            $settlementStatus = 'settled';
                            $failedAttempts = 3;
                            $returnCharge = 85.00;
                            $deliveredAt = (clone $createdAt)->addDays(rand(4, 7)); // Date returned to merchant
                            $remarks = 'Customer unreachable after 3 attempts. Returned to merchant warehouse.';
                        } elseif ($r <= 97) {
                            // Return Initiated (in reverse transit)
                            $status = 'return_initiated';
                            $merchantStatus = 'returning';
                            $podStatus = 'pending';
                            $settlementStatus = 'not_ready';
                            $failedAttempts = 3;
                            $returnCharge = 85.00;
                            $remarks = 'Customer rejected package. Return initiated to origin hub.';
                        } else {
                            // Cancelled by customer before dispatch
                            $status = 'cancelled';
                            $merchantStatus = 'cancelled';
                            $podStatus = 'cancelled';
                            $settlementStatus = 'cancelled';
                            $cancelledAt = (clone $createdAt)->addHours(rand(1, 4));
                            $remarks = 'Cancelled by customer before branch pickup';
                        }
                    } else {
                        // Current month: active operational pipeline
                        $r = rand(1, 100);
                        if ($r <= 48) {
                            $status = 'delivered';
                            $merchantStatus = 'delivered';
                            $podStatus = 'received_by_branch';
                            $settlementStatus = 'settled';
                            $deliveredAt = (clone $createdAt)->addDays(rand(1, 2));
                        } elseif ($r <= 65) {
                            $status = 'out_for_delivery';
                            $merchantStatus = 'out_for_delivery';
                            $podStatus = 'pending';
                            $settlementStatus = 'not_ready';
                        } elseif ($r <= 75) {
                            $status = 'delivery_failed';
                            $merchantStatus = 'failed_delivery';
                            $podStatus = 'pending';
                            $settlementStatus = 'not_ready';
                            $failedAttempts = 1;
                            $remarks = 'Customer requested delivery tomorrow (rescheduled)';
                        } elseif ($r <= 85) {
                            $status = 'in_transit';
                            $merchantStatus = 'in_transit';
                            $podStatus = 'pending';
                            $settlementStatus = 'not_ready';
                        } elseif ($r <= 92) {
                            $status = 'sorted_for_transfer';
                            $merchantStatus = 'in_transit';
                            $podStatus = 'pending';
                            $settlementStatus = 'not_ready';
                        } elseif ($r <= 95) {
                            $status = 'return_initiated';
                            $merchantStatus = 'returning';
                            $podStatus = 'pending';
                            $settlementStatus = 'not_ready';
                            $failedAttempts = 3;
                            $returnCharge = 85.00;
                            $remarks = 'Customer refused package at doorstep.';
                        } elseif ($r <= 98) {
                            $status = 'cancelled';
                            $merchantStatus = 'cancelled';
                            $podStatus = 'cancelled';
                            $settlementStatus = 'cancelled';
                            $cancelledAt = (clone $createdAt)->addHours(2);
                            $remarks = 'Merchant out of stock. Order cancelled.';
                        } else {
                            $status = 'received_at_origin_branch';
                            $merchantStatus = 'picked_up';
                            $podStatus = 'pending';
                            $settlementStatus = 'not_ready';
                        }
                    }

                    $receiverCity = $destBranch->city ?: 'Kathmandu';
                    $receiverName = $nepalNames[array_rand($nepalNames)];
                    $phone = '98' . rand(10000000, 99999999);
                    $tracking = sprintf('TEX-%s-%07d', $createdAt->format('ymd'), $this->baseShipmentId + $globalIndex);
                    $orderId = sprintf('ORD-%s-%06d-%s', $createdAt->format('ymd'), $globalIndex, strtoupper(Str::random(5)));
                    $podAmount = (float) rand(450, 6800);
                    $deliveryCharge = (float) rand(99, 290);
                    $weight = round(rand(3, 40) / 10, 2);

                    // Destination GPS coordinates with realistic doorstep delivery jitter (1.5 - 3.5km)
                    $destLat = (float) ($destBranch->latitude ?: 27.6951753);
                    $destLng = (float) ($destBranch->longitude ?: 85.3408307);
                    $deliveryLat = round($destLat + (rand(-250, 250) / 10000), 7);
                    $deliveryLng = round($destLng + (rand(-250, 250) / 10000), 7);

                    // Origin GPS coordinates
                    $origBranchObj = $branches->firstWhere('id', $originBranchId);
                    $origLat = (float) ($origBranchObj?->latitude ?: 27.6951753);
                    $origLng = (float) ($origBranchObj?->longitude ?: 85.3408307);
                    $pickupLat = round($origLat + (rand(-150, 150) / 10000), 7);
                    $pickupLng = round($origLng + (rand(-150, 150) / 10000), 7);

                    $ktmAreas = ['New Baneshwor', 'Koteshwor', 'Chabahil', 'Thamel', 'Tinkune', 'Sinamangal', 'Bouddha', 'Putalisadak', 'Balkot', 'Thimi', 'Lagankhel', 'Kupondole', 'Jawalakhel'];
                    $provAreas = ['Main Bazaar', 'Traffic Chowk', 'Hospital Chowk', 'Campus Road', 'Shahid Chowk', 'Adarshnagar', 'Lakeside', 'Prithvi Chowk', 'Milanchowk'];
                    $receiverArea = $receiverCity === 'Kathmandu' ? $ktmAreas[array_rand($ktmAreas)] : $provAreas[array_rand($provAreas)];
                    $receiverAddress = sprintf('%s, Ward %d, %s', $receiverArea, rand(1, 12), $receiverCity);

                    $shipmentRows[] = [
                        'tracking_number' => $tracking,
                        'merchant_id' => $merchant['id'],
                        'pickup_location_id' => $merchant['pickup_location_id'],
                        'merchant_order_id' => $orderId,
                        'order_source' => 'store_manager',
                        'service_type' => $service,
                        'self_drop' => 0,
                        'source' => 'manual',
                        'origin_branch_id' => $originBranchId,
                        'destination_branch_id' => $destBranchId,
                        'current_branch_id' => in_array($status, ['delivered', 'out_for_delivery', 'delivery_failed']) ? $destBranchId : $originBranchId,
                        'next_hop_branch_id' => $isCrossBranch ? $destBranchId : null,
                        'sender_name' => $merchant['name'],
                        'sender_phone' => $merchant['phone'],
                        'sender_address' => $merchant['address'],
                        'sender_city' => $merchant['city'],
                        'sender_area' => 'Commercial Hub',
                        'pickup_lat' => $pickupLat,
                        'pickup_lng' => $pickupLng,
                        'receiver_name' => $receiverName,
                        'receiver_phone' => $phone,
                        'receiver_city' => $receiverCity,
                        'receiver_area' => $receiverArea,
                        'delivery_address' => $receiverAddress,
                        'delivery_lat' => $deliveryLat,
                        'delivery_lng' => $deliveryLng,
                        'parcel_type' => 'parcel',
                        'quantity' => 1,
                        'weight' => $weight,
                        'chargeable_weight' => $weight,
                        'declared_value' => $podAmount,
                        'payment_type' => 'pod',
                        'pod_amount' => $podAmount,
                        'delivery_charge' => $deliveryCharge,
                        'return_charge' => $returnCharge,
                        'total_collectable_amount' => $podAmount + $deliveryCharge,
                        'total_collectable' => $podAmount + $deliveryCharge,
                        'delivery_charge_paid_by' => 'customer',
                        'status' => $status,
                        'merchant_status' => $merchantStatus,
                        'pod_status' => $podStatus,
                        'settlement_status' => $settlementStatus,
                        'failed_attempts' => $failedAttempts,
                        'transfer_status' => $isCrossBranch ? ($status === 'in_transit' ? 'in_transit' : ($status === 'delivered' ? 'received_at_destination_branch' : 'sorted_for_transfer')) : null,
                        'delivered_at' => $deliveredAt,
                        'cancelled_at' => $cancelledAt,
                        'remarks' => $remarks,
                        'created_at' => $createdAt,
                        'updated_at' => $deliveredAt ?: ($cancelledAt ?: $createdAt),
                    ];
                }

                // 1. Insert Shipments Batch in safe chunks (max 500 rows = 22,500 placeholders <= 65,535 limit)
                foreach (array_chunk($shipmentRows, 500) as $subRows) {
                    DB::table('shipments')->insert($subRows);
                }

                // Fetch inserted shipment IDs using exact batch tracking numbers
                $batchTracking = array_column($shipmentRows, 'tracking_number');
                $insertedShipments = DB::table('shipments')
                    ->whereIn('tracking_number', $batchTracking)
                    ->select('id', 'tracking_number', 'merchant_id', 'origin_branch_id', 'destination_branch_id', 'delivery_charge', 'return_charge', 'pod_amount', 'status', 'failed_attempts', 'created_at', 'delivered_at', 'cancelled_at', 'remarks')
                    ->get();

                // 2. Build Relational Ledger
                foreach ($insertedShipments as $s) {
                    $sCreatedAt = \Illuminate\Support\Carbon::parse($s->created_at);
                    $sDeliveredAt = $s->delivered_at ? \Illuminate\Support\Carbon::parse($s->delivered_at) : null;
                    $sCancelledAt = $s->cancelled_at ? \Illuminate\Support\Carbon::parse($s->cancelled_at) : null;

                    $fare = (float) $s->delivery_charge;
                    $retFare = (float) $s->return_charge;
                    $pod = (float) $s->pod_amount;
                    $isCross = $s->origin_branch_id !== $s->destination_branch_id;

                    if ($isCross && in_array($s->status, ['in_transit', 'delivered', 'sorted_for_transfer', 'return_initiated', 'returned'])) {
                        $crossBranchShipmentIds[] = $s->id;
                    }

                    // A. Tracking Events (Detailed milestone log)
                    $trackingEvents[] = [
                        'shipment_id' => $s->id,
                        'tracking_number' => $s->tracking_number,
                        'status' => 'booked',
                        'merchant_status' => 'pending',
                        'branch_id' => $s->origin_branch_id,
                        'location_text' => 'Order created by merchant',
                        'visibility' => 'all',
                        'created_at' => $s->created_at,
                        'updated_at' => $s->created_at,
                    ];

                    if ($s->status === 'cancelled') {
                        $trackingEvents[] = [
                            'shipment_id' => $s->id,
                            'tracking_number' => $s->tracking_number,
                            'status' => 'cancelled',
                            'merchant_status' => 'cancelled',
                            'branch_id' => $s->origin_branch_id,
                            'location_text' => $s->remarks ?: 'Order cancelled prior to branch pickup',
                            'visibility' => 'all',
                            'created_at' => $sCancelledAt ?: (clone $sCreatedAt)->addHours(2),
                            'updated_at' => $sCancelledAt ?: (clone $sCreatedAt)->addHours(2),
                        ];
                        continue; // No further steps for cancelled orders
                    }

                    $trackingEvents[] = [
                        'shipment_id' => $s->id,
                        'tracking_number' => $s->tracking_number,
                        'status' => 'received_at_origin_branch',
                        'merchant_status' => 'picked_up',
                        'branch_id' => $s->origin_branch_id,
                        'location_text' => 'Intake completed at origin branch cage',
                        'visibility' => 'all',
                        'created_at' => (clone $sCreatedAt)->addHours(2),
                        'updated_at' => (clone $sCreatedAt)->addHours(2),
                    ];

                    // Delivery Attempts & Exceptions
                    $destRiders = $riderBranchMap[$s->destination_branch_id] ?? reset($riderBranchMap);
                    $assignedRiderId = $destRiders[array_rand($destRiders)];

                    if ($s->failed_attempts > 0) {
                        for ($att = 1; $att <= $s->failed_attempts; $att++) {
                            $reason = match ($att) {
                                1 => 'customer_unreachable',
                                2 => 'rescheduled_by_customer',
                                default => 'customer_rejected',
                            };
                            $attRemarks = match ($att) {
                                1 => 'Attempt 1: Recipient phone unreachable at doorstep.',
                                2 => 'Attempt 2: Recipient requested reattempt tomorrow.',
                                default => 'Attempt 3: Recipient refused to accept package.',
                            };

                            if (Schema::hasTable('delivery_attempts')) {
                                $deliveryAttempts[] = [
                                    'shipment_id' => $s->id,
                                    'delivery_staff_id' => $assignedRiderId,
                                    'status' => 'failed',
                                    'failure_reason' => $reason,
                                    'remarks' => $attRemarks,
                                    'created_at' => (clone $sCreatedAt)->addDays($att),
                                    'updated_at' => (clone $sCreatedAt)->addDays($att),
                                ];
                            }

                            $trackingEvents[] = [
                                'shipment_id' => $s->id,
                                'tracking_number' => $s->tracking_number,
                                'status' => 'delivery_failed',
                                'merchant_status' => 'failed_delivery',
                                'branch_id' => $s->destination_branch_id,
                                'location_text' => $attRemarks,
                                'visibility' => 'all',
                                'created_at' => (clone $sCreatedAt)->addDays($att),
                                'updated_at' => (clone $sCreatedAt)->addDays($att),
                            ];
                        }
                    }

                    // RTO & Return Flow
                    if ($s->status === 'returned' || $s->status === 'return_initiated') {
                        $trackingEvents[] = [
                            'shipment_id' => $s->id,
                            'tracking_number' => $s->tracking_number,
                            'status' => 'return_initiated',
                            'merchant_status' => 'returning',
                            'branch_id' => $s->destination_branch_id,
                            'location_text' => 'Package scheduled for reverse transit back to origin merchant',
                            'visibility' => 'all',
                            'created_at' => (clone $sCreatedAt)->addDays(3),
                            'updated_at' => (clone $sCreatedAt)->addDays(3),
                        ];

                        if ($s->status === 'returned') {
                            $trackingEvents[] = [
                                'shipment_id' => $s->id,
                                'tracking_number' => $s->tracking_number,
                                'status' => 'returned',
                                'merchant_status' => 'returned',
                                'branch_id' => $s->origin_branch_id,
                                'location_text' => 'Returned and verified at merchant warehouse',
                                'visibility' => 'all',
                                'created_at' => $sDeliveredAt ?: (clone $sCreatedAt)->addDays(5),
                                'updated_at' => $sDeliveredAt ?: (clone $sCreatedAt)->addDays(5),
                            ];
                        }
                        continue;
                    }

                    if ($isCross) {
                        $trackingEvents[] = [
                            'shipment_id' => $s->id,
                            'tracking_number' => $s->tracking_number,
                            'status' => 'sorted_for_transfer',
                            'merchant_status' => 'in_transit',
                            'branch_id' => $s->origin_branch_id,
                            'location_text' => 'Container sealed for line-haul dispatch',
                            'visibility' => 'all',
                            'created_at' => (clone $sCreatedAt)->addHours(4),
                            'updated_at' => (clone $sCreatedAt)->addHours(4),
                        ];
                    }

                    if (in_array($s->status, ['in_transit', 'out_for_delivery', 'delivered'])) {
                        $trackingEvents[] = [
                            'shipment_id' => $s->id,
                            'tracking_number' => $s->tracking_number,
                            'status' => 'in_transit',
                            'merchant_status' => 'in_transit',
                            'branch_id' => $s->origin_branch_id,
                            'location_text' => 'Highway line-haul corridor dispatch',
                            'visibility' => 'all',
                            'created_at' => (clone $sCreatedAt)->addHours(6),
                            'updated_at' => (clone $sCreatedAt)->addHours(6),
                        ];
                    }

                    if (in_array($s->status, ['out_for_delivery', 'delivered'])) {
                        $trackingEvents[] = [
                            'shipment_id' => $s->id,
                            'tracking_number' => $s->tracking_number,
                            'status' => 'out_for_delivery',
                            'merchant_status' => 'out_for_delivery',
                            'branch_id' => $s->destination_branch_id,
                            'location_text' => 'Assigned to delivery rider for doorstep drop-off',
                            'visibility' => 'all',
                            'created_at' => (clone $sCreatedAt)->addDays(1)->addHours(3),
                            'updated_at' => (clone $sCreatedAt)->addDays(1)->addHours(3),
                        ];
                    }

                    if ($s->status === 'delivered') {
                        $trackingEvents[] = [
                            'shipment_id' => $s->id,
                            'tracking_number' => $s->tracking_number,
                            'status' => 'delivered',
                            'merchant_status' => 'delivered',
                            'branch_id' => $s->destination_branch_id,
                            'location_text' => 'Delivered to recipient with digital POD signature & OTP',
                            'visibility' => 'all',
                            'created_at' => $sDeliveredAt ?: (clone $sCreatedAt)->addDays(1)->addHours(6),
                            'updated_at' => $sDeliveredAt ?: (clone $sCreatedAt)->addDays(1)->addHours(6),
                        ];

                        $deliveredShipments[] = $s->id;

                        // B. Proof of Delivery (POD Records)
                        if (Schema::hasTable('pod_records')) {
                            $podRows[] = [
                                'shipment_id' => $s->id,
                                'merchant_id' => $s->merchant_id,
                                'pod_amount' => $pod,
                                'delivery_charge' => $fare,
                                'pod_charge' => 0.00,
                                'collected_amount' => $pod + $fare,
                                'status' => 'confirmed',
                                'collected_by' => $assignedRiderId,
                                'collected_at' => $sDeliveredAt,
                                'deposited_to_branch_id' => $s->destination_branch_id,
                                'deposited_at' => $sDeliveredAt,
                                'settled_at' => $sDeliveredAt,
                                'payment_method' => rand(1, 100) <= 65 ? 'cash' : 'esewa',
                                'remarks' => 'Doorstep delivery completed and POD verified',
                                'created_at' => $sDeliveredAt,
                                'updated_at' => $sDeliveredAt,
                            ];
                        }

                        // C. POD Cash Collections
                        if (Schema::hasTable('pod_collections')) {
                            $payMethod = rand(1, 100) <= 65 ? 'cash' : 'esewa';
                            $podCollectionRows[] = [
                                'shipment_id' => $s->id,
                                'merchant_id' => $s->merchant_id,
                                'rider_id' => $assignedRiderId,
                                'pod_amount' => $pod,
                                'delivery_charge_collected' => $fare,
                                'total_collected' => $pod + $fare,
                                'payment_method' => $payMethod,
                                'status' => 'deposited',
                                'collected_at' => $s->delivered_at,
                                'deposited_at' => $s->delivered_at,
                                'created_at' => $s->delivered_at,
                                'updated_at' => $s->delivered_at,
                            ];
                        }

                        // D. HQ Commission Bill (15% Software/Franchise Royalty)
                        if (Schema::hasTable('branch_commission_bills')) {
                            $commAmt = round($fare * 0.15, 2);
                            $commissionBills[] = [
                                'bill_number' => sprintf('HQ-BILL-%s-%06d', $s->created_at ? date('Ymd', strtotime($s->created_at)) : date('Ymd'), $s->id),
                                'branch_id' => $s->origin_branch_id,
                                'shipment_id' => $s->id,
                                'merchant_id' => $s->merchant_id,
                                'delivery_charge_base' => $fare,
                                'commission_rate' => 15.0000,
                                'commission_amount' => $commAmt,
                                'currency' => 'NPR',
                                'status' => 'paid',
                                'payment_method' => 'bank_transfer',
                                'paid_at' => $s->delivered_at,
                                'created_at' => $s->delivered_at,
                                'updated_at' => $s->delivered_at,
                            ];
                        }
                    }

                    // E. Price Breakdown
                    if (Schema::hasTable('shipment_price_breakdowns')) {
                        $priceBreakdowns[] = [
                            'shipment_id' => $s->id,
                            'pricing_quote_id' => null,
                            'base_pickup_fee' => 0.00,
                            'base_delivery_fee' => round($fare * 0.70, 2),
                            'base_transfer_fee' => $isCross ? round($fare * 0.20, 2) : 0.00,
                            'pickup_distance_km' => 1.5,
                            'pickup_extra_km' => 0.0,
                            'pickup_extra_charge' => 0.00,
                            'delivery_distance_km' => 3.2,
                            'delivery_extra_km' => 0.0,
                            'delivery_extra_charge' => 0.00,
                            'weight_charge' => round($fare * 0.10, 2),
                            'pod_fee' => 0.00,
                            'discount' => 0.00,
                            'final_price' => $fare,
                            'snapshot_json' => json_encode(['fare' => $fare, 'return_charge' => $retFare]),
                            'created_at' => $sCreatedAt,
                            'updated_at' => $sCreatedAt,
                        ];
                    }

                    // F. 40 / 45 / 15 Branch Share Split (Earnings)
                    if (Schema::hasTable('shipment_branch_shares')) {
                        $originAmt = round($fare * 0.40, 2);
                        $destAmt = round($fare * 0.45, 2);
                        $hqAmt = round($fare * 0.15, 2);
                        $shareStatus = $s->status === 'delivered' ? 'settled' : 'pending';

                        if ($s->origin_branch_id === $s->destination_branch_id) {
                            $sharesRows[] = [
                                'shipment_id' => $s->id,
                                'branch_id' => $s->origin_branch_id,
                                'role' => 'origin',
                                'position' => 0,
                                'branch_count' => 1,
                                'percent' => 85.0000,
                                'fare_amount' => $fare,
                                'share_amount' => round($fare * 0.85, 2),
                                'transport_amount' => 0.00,
                                'extra_distance_amount' => 0.00,
                                'allocation_amount' => round($fare * 0.85, 2),
                                'hq_rate' => 15.0000,
                                'hq_commission_amount' => $hqAmt,
                                'net_amount' => round($fare * 0.85, 2),
                                'collecting_branch_id' => $s->origin_branch_id,
                                'collection_mode' => 'merchant_invoice',
                                'status' => $shareStatus,
                                'computed_at' => $sCreatedAt,
                                'created_at' => $sCreatedAt,
                                'updated_at' => $sDeliveredAt ?: $sCreatedAt,
                            ];
                        } else {
                            $sharesRows[] = [
                                'shipment_id' => $s->id,
                                'branch_id' => $s->origin_branch_id,
                                'role' => 'origin',
                                'position' => 0,
                                'branch_count' => 2,
                                'percent' => 40.0000,
                                'fare_amount' => $fare,
                                'share_amount' => $originAmt,
                                'transport_amount' => 0.00,
                                'extra_distance_amount' => 0.00,
                                'allocation_amount' => $originAmt,
                                'hq_rate' => 15.0000,
                                'hq_commission_amount' => $hqAmt,
                                'net_amount' => $originAmt,
                                'collecting_branch_id' => $s->destination_branch_id,
                                'collection_mode' => 'merchant_invoice',
                                'status' => $shareStatus,
                                'computed_at' => $sCreatedAt,
                                'created_at' => $sCreatedAt,
                                'updated_at' => $sDeliveredAt ?: $sCreatedAt,
                            ];
                            $sharesRows[] = [
                                'shipment_id' => $s->id,
                                'branch_id' => $s->destination_branch_id,
                                'role' => 'delivery',
                                'position' => 1,
                                'branch_count' => 2,
                                'percent' => 45.0000,
                                'fare_amount' => $fare,
                                'share_amount' => $destAmt,
                                'transport_amount' => 0.00,
                                'extra_distance_amount' => 0.00,
                                'allocation_amount' => $destAmt,
                                'hq_rate' => 0.0000,
                                'hq_commission_amount' => 0.00,
                                'net_amount' => $destAmt,
                                'collecting_branch_id' => $s->destination_branch_id,
                                'collection_mode' => 'customer',
                                'status' => $shareStatus,
                                'computed_at' => $sCreatedAt,
                                'created_at' => $sCreatedAt,
                                'updated_at' => $sDeliveredAt ?: $sCreatedAt,
                            ];
                        }
                    }

                    // G. Collect items for merchant settlement batch
                    if (in_array($s->status, ['delivered', 'returned'])) {
                        $settlementItems[] = [
                            'merchant_id' => $s->merchant_id,
                            'shipment_id' => $s->id,
                            'pod_amount' => $s->status === 'delivered' ? $pod : 0.00,
                            'delivery_charge' => $fare,
                            'return_charge' => $retFare,
                            'net_amount' => ($s->status === 'delivered' ? $pod : 0.00) - $fare - $retFare,
                            'date' => $s->delivered_at ?: $s->created_at,
                        ];
                    }

                    // H. Delivery Assignments (for Last-Mile Deliveries Board & Coverage Map)
                    if (Schema::hasTable('delivery_assignments')) {
                        $assignmentStatus = null;
                        $riderId = null;

                        if ($s->status === 'delivered') {
                            $assignmentStatus = 'delivered';
                            $riderId = $assignedRiderId;
                        } elseif ($s->status === 'delivery_failed') {
                            $assignmentStatus = 'failed';
                            $riderId = $assignedRiderId;
                        } elseif ($s->status === 'out_for_delivery') {
                            $assignmentStatus = 'out_for_delivery';
                            $riderId = $assignedRiderId;
                        } elseif ($monthOffset <= 1) {
                            if (in_array($s->status, ['received_at_origin_branch', 'in_transit', 'sorted_for_transfer'])) {
                                $rAssign = rand(1, 100);
                                if ($rAssign <= 40) {
                                    // Ready to Assign (Unassigned, rider is null -> populates Ready to Assign tab and coverage map pins!)
                                    $assignmentStatus = 'pending';
                                    $riderId = null;
                                } elseif ($rAssign <= 70) {
                                    $assignmentStatus = 'assigned';
                                    $riderId = $assignedRiderId;
                                } else {
                                    $assignmentStatus = 'accepted';
                                    $riderId = $assignedRiderId;
                                }
                            }
                        }

                        if ($assignmentStatus !== null) {
                            $deliveryAssignments[] = [
                                'shipment_id' => $s->id,
                                'branch_id' => $s->destination_branch_id,
                                'sub_branch_id' => null,
                                'delivery_staff_id' => $riderId,
                                'rider_id' => $riderId,
                                'assigned_date' => $sCreatedAt->toDateString(),
                                'status' => $assignmentStatus,
                                'assigned_at' => $riderId ? (clone $sCreatedAt)->addHours(18) : null,
                                'accepted_at' => in_array($assignmentStatus, ['accepted', 'out_for_delivery', 'delivered']) ? (clone $sCreatedAt)->addHours(19) : null,
                                'out_for_delivery_at' => in_array($assignmentStatus, ['out_for_delivery', 'delivered']) ? (clone $sCreatedAt)->addHours(20) : null,
                                'delivered_at' => $assignmentStatus === 'delivered' ? $sDeliveredAt : null,
                                'failed_at' => $assignmentStatus === 'failed' ? (clone $sCreatedAt)->addHours(22) : null,
                                'failed_reason' => $assignmentStatus === 'failed' ? 'customer_unreachable' : null,
                                'failure_reason' => $assignmentStatus === 'failed' ? 'customer_unreachable' : null,
                                'remarks' => $assignmentStatus === 'pending' ? 'Pending delivery rider assignment' : 'Doorstep parcel delivery assignment',
                                'pod_collected_amount' => $assignmentStatus === 'delivered' ? ($pod + $fare) : 0.00,
                                'assigned_by' => 1,
                                'attempt_no' => $s->failed_attempts ?: 1,
                                'otp' => '4821',
                                'delivery_type' => 'last_mile',
                                'created_at' => $sCreatedAt,
                                'updated_at' => $sDeliveredAt ?: $sCreatedAt,
                            ];
                        }
                    }
                }

                // Batch Insert Child Tables in Safe Chunks (prevents MySQL prepared statement placeholder limits)
                if (!empty($trackingEvents) && Schema::hasTable('tracking_events')) {
                    foreach (array_chunk($trackingEvents, 1000) as $chunkRows) {
                        DB::table('tracking_events')->insert($chunkRows);
                    }
                }
                if (!empty($deliveryAttempts) && Schema::hasTable('delivery_attempts')) {
                    foreach (array_chunk($deliveryAttempts, 1000) as $chunkRows) {
                        DB::table('delivery_attempts')->insert($chunkRows);
                    }
                }
                if (!empty($deliveryAssignments) && Schema::hasTable('delivery_assignments')) {
                    foreach (array_chunk($deliveryAssignments, 500) as $chunkRows) {
                        DB::table('delivery_assignments')->insert($chunkRows);
                    }
                }
                if (!empty($podRows) && Schema::hasTable('pod_records')) {
                    foreach (array_chunk($podRows, 1000) as $chunkRows) {
                        DB::table('pod_records')->insert($chunkRows);
                    }
                }
                if (!empty($podCollectionRows) && Schema::hasTable('pod_collections')) {
                    foreach (array_chunk($podCollectionRows, 1000) as $chunkRows) {
                        DB::table('pod_collections')->insert($chunkRows);
                    }
                }
                if (!empty($commissionBills) && Schema::hasTable('branch_commission_bills')) {
                    foreach (array_chunk($commissionBills, 1000) as $chunkRows) {
                        DB::table('branch_commission_bills')->insert($chunkRows);
                    }
                }
                if (!empty($priceBreakdowns) && Schema::hasTable('shipment_price_breakdowns')) {
                    foreach (array_chunk($priceBreakdowns, 1000) as $chunkRows) {
                        DB::table('shipment_price_breakdowns')->insert($chunkRows);
                    }
                }
                if (!empty($sharesRows) && Schema::hasTable('shipment_branch_shares')) {
                    foreach (array_chunk($sharesRows, 1000) as $chunkRows) {
                        DB::table('shipment_branch_shares')->insert($chunkRows);
                    }
                }

                // Create Pickup Requests, Runs & Link Shipments
                if (Schema::hasTable('pickup_requests')) {
                    $this->createPickupRequestsAndRuns($insertedShipments, $branches, $riderBranchMap, $merchantMap, $monthOffset, $monthDate);
                }

                // Create Transfer Manifests & Line-haul Trips (Transport Expenses)
                if (!empty($crossBranchShipmentIds) && Schema::hasTable('dispatch_manifests')) {
                    $this->createTransferRunsAndTrips($crossBranchShipmentIds, $branches, $fleet, $monthDate);
                }

                // Create Delivery Runs, Rider Cash Handover & Bank Deposits
                if (!empty($deliveredShipments) && Schema::hasTable('delivery_runs')) {
                    $this->createDeliveryRuns($deliveredShipments, $branches, $riderBranchMap, $monthDate);
                }

                // Create Merchant Settlements, Invoices, Payment Receipts & Payment Ledger
                if (!empty($settlementItems) && Schema::hasTable('merchant_settlements')) {
                    $this->createMerchantSettlement($settlementItems, $monthDate);
                }

                $generated += $batchSize;
                $monthGenerated += $batchSize;
                $this->output->progressAdvance($batchSize);
            }

            // Create Monthly Inter-Branch and HQ Royalty Settlements
            $this->createMonthlyAccountingStatements($monthDate);
        }

        $this->output->progressFinish();
        $elapsed = microtime(true) - $startTime;
        $rate = $generated / max(1, $elapsed);

        $this->newLine();
        $this->info("==========================================================================");
        $this->info(sprintf("✓ SUCCESS: %d Shipments across 350+ Merchants & 65 Branches in %.2fs (~%d/sec)", $generated, $elapsed, $rate));
        $this->info("  Complete Financial Records Created: Gross Revenue, Branch Earnings (40/45/15),");
        $this->info("  HQ Commissions, Linehaul Transport Expenses, Rider Deposits, Invoices & Receipts.");
        $this->info("==========================================================================");

        return self::SUCCESS;
    }

    private function ensureBranches()
    {
        $cityCoordinates = [
            'Kathmandu' => ['lat' => 27.6951753, 'lng' => 85.3408307],
            'Lalitpur' => ['lat' => 27.6672000, 'lng' => 85.3218000],
            'Bhaktapur' => ['lat' => 27.6710000, 'lng' => 85.4298000],
            'Kirtipur' => ['lat' => 27.6799000, 'lng' => 85.2754000],
            'Pokhara' => ['lat' => 28.2077274, 'lng' => 83.9899107],
            'Biratnagar' => ['lat' => 26.4525000, 'lng' => 87.2718000],
            'Birgunj' => ['lat' => 27.0135000, 'lng' => 84.8770000],
            'Butwal' => ['lat' => 27.6990535, 'lng' => 83.4655165],
            'Bharatpur' => ['lat' => 27.6805000, 'lng' => 84.4333000],
            'Hetauda' => ['lat' => 27.4285000, 'lng' => 85.0322000],
            'Dharan' => ['lat' => 26.8124000, 'lng' => 87.2834000],
            'Itahari' => ['lat' => 26.6629361, 'lng' => 87.2745301],
            'Birtamod' => ['lat' => 26.6430367, 'lng' => 87.9918647],
            'Damak' => ['lat' => 26.6660000, 'lng' => 87.6980000],
            'Janakpur' => ['lat' => 26.7271000, 'lng' => 85.9407000],
            'Nepalgunj' => ['lat' => 28.0500000, 'lng' => 81.6167000],
            'Kohalpur' => ['lat' => 28.1944000, 'lng' => 81.6888000],
            'Dhangadhi' => ['lat' => 28.6852000, 'lng' => 80.5960000],
            'Bhimdatta' => ['lat' => 28.9642000, 'lng' => 80.1774000],
            'Surkhet' => ['lat' => 28.5997000, 'lng' => 81.6322000],
            'Birendranagar' => ['lat' => 28.5997000, 'lng' => 81.6322000],
            'Ghorahi' => ['lat' => 28.0436000, 'lng' => 82.4938000],
            'Tulsipur' => ['lat' => 28.1308000, 'lng' => 82.2961000],
            'Bhairahawa' => ['lat' => 27.5045000, 'lng' => 83.4475000],
            'Baglung' => ['lat' => 28.2719000, 'lng' => 83.5936000],
            'Banepa' => ['lat' => 27.6298000, 'lng' => 85.5214000],
            'Ilam' => ['lat' => 26.9110000, 'lng' => 87.9270000],
            'Dhankuta' => ['lat' => 26.9830000, 'lng' => 87.3330000],
            'Gaighat' => ['lat' => 26.7900000, 'lng' => 86.6900000],
            'Lahan' => ['lat' => 26.7296000, 'lng' => 86.4950000],
            'Rajbiraj' => ['lat' => 26.5414000, 'lng' => 86.7547000],
            'Bardibas' => ['lat' => 26.9890000, 'lng' => 85.9010000],
            'Kalaiya' => ['lat' => 27.0300000, 'lng' => 85.0000000],
            'Gaur' => ['lat' => 26.7640000, 'lng' => 85.2780000],
            'Malangwa' => ['lat' => 26.8570000, 'lng' => 85.5580000],
            'Dhading' => ['lat' => 27.8680000, 'lng' => 84.9080000],
            'Bidur' => ['lat' => 27.9100000, 'lng' => 85.1600000],
            'Sindhulimadhi' => ['lat' => 27.2500000, 'lng' => 85.9700000],
            'Beni' => ['lat' => 28.3440000, 'lng' => 83.5640000],
            'Damauli' => ['lat' => 27.9730000, 'lng' => 84.2850000],
            'Gorkha' => ['lat' => 28.0050000, 'lng' => 84.6300000],
            'Kawasoti' => ['lat' => 27.6430000, 'lng' => 84.1250000],
            'Besisahar' => ['lat' => 28.2300000, 'lng' => 84.3770000],
            'Putalibazar' => ['lat' => 28.0960000, 'lng' => 83.8750000],
            'Tansen' => ['lat' => 27.8670000, 'lng' => 83.5480000],
            'Gulariya' => ['lat' => 28.2330000, 'lng' => 81.3330000],
            'Taulihawa' => ['lat' => 27.5450000, 'lng' => 83.0560000],
            'Dailekh' => ['lat' => 28.8380000, 'lng' => 81.7100000],
            'Chandannath' => ['lat' => 29.2740000, 'lng' => 82.1840000],
            'Salyan' => ['lat' => 28.3750000, 'lng' => 82.1640000],
            'Khalanga' => ['lat' => 28.7000000, 'lng' => 82.2000000],
            'Attariya' => ['lat' => 28.8140000, 'lng' => 80.5500000],
            'Tikapur' => ['lat' => 28.5200000, 'lng' => 81.1200000],
            'Dadeldhura' => ['lat' => 29.3000000, 'lng' => 80.5830000],
            'Amargadhi' => ['lat' => 29.3000000, 'lng' => 80.5830000],
            'Dipayal' => ['lat' => 29.2600000, 'lng' => 80.9400000],
            'Dipayal Silgadhi' => ['lat' => 29.2600000, 'lng' => 80.9400000],
            'Dasharathchand' => ['lat' => 29.5400000, 'lng' => 80.4200000],
        ];

        // Backfill latitude and longitude on any branch with missing GPS
        foreach ($cityCoordinates as $cCity => $cCoord) {
            DB::table('branches')
                ->where(function ($q) use ($cCity) {
                    $q->where('city', $cCity)->orWhere('name', 'like', "%{$cCity}%");
                })
                ->where(function ($q) {
                    $q->whereNull('latitude')->orWhere('latitude', 0);
                })
                ->update([
                    'latitude' => $cCoord['lat'],
                    'longitude' => $cCoord['lng'],
                ]);
        }

        $existing = DB::table('branches')->get();
        if ($existing->count() >= 50) {
            return DB::table('branches')->select('id', 'name', 'code', 'city', 'area', 'latitude', 'longitude')->get();
        }

        $existingCodes = $existing->pluck('code')->all();

        // Full 65 Nationwide Branches Covering All 7 Provinces of Nepal
        $masterBranches = [
            // Kathmandu Valley Hubs
            ['name' => 'Kathmandu Head Office & Central Hub', 'code' => 'BR-KTM', 'city' => 'Kathmandu', 'area' => 'Teku', 'address' => 'Teku Logistics Park, Kathmandu', 'type' => 'head_branch'],
            ['name' => 'Lalitpur Sub-Hub', 'code' => 'BR-LLP', 'city' => 'Lalitpur', 'area' => 'Lagankhel', 'address' => 'Lagankhel Bus Park Road, Lalitpur', 'type' => 'sub_branch'],
            ['name' => 'Bhaktapur Sub-Hub', 'code' => 'BR-BKT', 'city' => 'Bhaktapur', 'area' => 'Suryabinayak', 'address' => 'Suryabinayak Chowk, Bhaktapur', 'type' => 'sub_branch'],
            ['name' => 'Chabahil Branch', 'code' => 'BR-CBH', 'city' => 'Kathmandu', 'area' => 'Chabahil', 'address' => 'Chabahil Ring Road, Kathmandu', 'type' => 'branch'],
            ['name' => 'Kirtipur Branch', 'code' => 'BR-KTP', 'city' => 'Kirtipur', 'area' => 'Naya Bazaar', 'address' => 'Naya Bazaar, Kirtipur', 'type' => 'branch'],

            // Koshi Province (Province 1)
            ['name' => 'Biratnagar Regional Hub', 'code' => 'BR-BRT', 'city' => 'Biratnagar', 'area' => 'Main Road', 'address' => 'Traffic Chowk, Biratnagar', 'type' => 'regional_hub'],
            ['name' => 'Dharan Branch', 'code' => 'BR-DRN', 'city' => 'Dharan', 'area' => 'Bhanu Chowk', 'address' => 'Bhanu Chowk, Dharan', 'type' => 'branch'],
            ['name' => 'Itahari Junction Hub', 'code' => 'BR-ITH', 'city' => 'Itahari', 'area' => 'Highway Chowk', 'address' => 'Main Highway Chowk, Itahari', 'type' => 'branch'],
            ['name' => 'Birtamod Branch', 'code' => 'BR-BTM', 'city' => 'Birtamod', 'area' => 'Mukti Chowk', 'address' => 'Mukti Chowk, Birtamod, Jhapa', 'type' => 'branch'],
            ['name' => 'Damak Branch', 'code' => 'BR-DMK', 'city' => 'Damak', 'area' => 'Thana Chowk', 'address' => 'Thana Chowk, Damak, Jhapa', 'type' => 'branch'],
            ['name' => 'Ilam Branch', 'code' => 'BR-ILM', 'city' => 'Ilam', 'area' => 'Bazaar', 'address' => 'Main Bazaar, Ilam', 'type' => 'branch'],
            ['name' => 'Dhankuta Branch', 'code' => 'BR-DKT', 'city' => 'Dhankuta', 'area' => 'Hile', 'address' => 'Hile Bazaar, Dhankuta', 'type' => 'branch'],
            ['name' => 'Gaighat Branch', 'code' => 'BR-GGT', 'city' => 'Gaighat', 'area' => 'Triyuga', 'address' => 'Main Road, Gaighat, Udayapur', 'type' => 'branch'],

            // Madhesh Province (Province 2)
            ['name' => 'Birgunj Regional Hub', 'code' => 'BR-BRG', 'city' => 'Birgunj', 'area' => 'Adarshnagar', 'address' => 'Custom Bypass, Birgunj', 'type' => 'regional_hub'],
            ['name' => 'Janakpur Regional Hub', 'code' => 'BR-JNK', 'city' => 'Janakpur', 'area' => 'Station Road', 'address' => 'Station Road, Janakpurdham', 'type' => 'regional_hub'],
            ['name' => 'Lahan Branch', 'code' => 'BR-LHN', 'city' => 'Lahan', 'area' => 'Hospital Chowk', 'address' => 'Hospital Chowk, Lahan, Siraha', 'type' => 'branch'],
            ['name' => 'Rajbiraj Branch', 'code' => 'BR-RJB', 'city' => 'Rajbiraj', 'area' => 'Netraganj', 'address' => 'Main Road, Rajbiraj, Saptari', 'type' => 'branch'],
            ['name' => 'Bardibas Junction Hub', 'code' => 'BR-BDB', 'city' => 'Bardibas', 'area' => 'Highway Junction', 'address' => 'BP Highway Chowk, Bardibas', 'type' => 'branch'],
            ['name' => 'Kalaiya Branch', 'code' => 'BR-KLY', 'city' => 'Kalaiya', 'area' => 'Bazaar', 'address' => 'Main Chowk, Kalaiya, Bara', 'type' => 'branch'],
            ['name' => 'Gaur Branch', 'code' => 'BR-GAR', 'city' => 'Gaur', 'area' => 'Custom Road', 'address' => 'Custom Road, Gaur, Rautahat', 'type' => 'branch'],
            ['name' => 'Malangwa Branch', 'code' => 'BR-MLG', 'city' => 'Malangwa', 'area' => 'Bazaar', 'address' => 'Main Road, Malangwa, Sarlahi', 'type' => 'branch'],

            // Bagmati Province (Province 3)
            ['name' => 'Narayangarh Mega Junction Hub', 'code' => 'BR-NRG', 'city' => 'Bharatpur', 'area' => 'Pulchowk Narayangarh', 'address' => 'Lions Chowk, Narayangarh, Chitwan', 'type' => 'regional_hub'],
            ['name' => 'Bharatpur Branch', 'code' => 'BR-BHP', 'city' => 'Bharatpur', 'area' => 'Chaubiskothi', 'address' => 'Chaubiskothi, Bharatpur, Chitwan', 'type' => 'branch'],
            ['name' => 'Hetauda Regional Hub', 'code' => 'BR-HTD', 'city' => 'Hetauda', 'area' => 'Industrial Area', 'address' => 'Kamana Chowk, Hetauda, Makwanpur', 'type' => 'regional_hub'],
            ['name' => 'Banepa Branch', 'code' => 'BR-BNP', 'city' => 'Banepa', 'area' => 'Chandeshwori', 'address' => 'Araniko Highway, Banepa, Kavre', 'type' => 'branch'],
            ['name' => 'Dhadingbesi Branch', 'code' => 'BR-DHD', 'city' => 'Dhading', 'area' => 'Besi', 'address' => 'Puchhar Bazaar, Dhadingbesi', 'type' => 'branch'],
            ['name' => 'Bidur Branch', 'code' => 'BR-BDR', 'city' => 'Bidur', 'area' => 'Trishuli', 'address' => 'Battar Bazaar, Bidur, Nuwakot', 'type' => 'branch'],
            ['name' => 'Sindhuli Branch', 'code' => 'BR-SDH', 'city' => 'Sindhulimadhi', 'area' => 'Bazaar', 'address' => 'Kamalamai Chowk, Sindhuli', 'type' => 'branch'],

            // Gandaki Province (Province 4)
            ['name' => 'Pokhara Regional Hub', 'code' => 'BR-PKR', 'city' => 'Pokhara', 'area' => 'Prithvi Chowk', 'address' => 'Prithvi Chowk Commercial Complex, Pokhara', 'type' => 'regional_hub'],
            ['name' => 'Lekhnath Branch', 'code' => 'BR-LKN', 'city' => 'Pokhara', 'area' => 'Talchowk', 'address' => 'Talchowk, Lekhnath, Kaski', 'type' => 'branch'],
            ['name' => 'Baglung Branch', 'code' => 'BR-BGL', 'city' => 'Baglung', 'area' => 'Traffic Chowk', 'address' => 'Traffic Chowk, Baglung Bazaar', 'type' => 'branch'],
            ['name' => 'Beni Branch', 'code' => 'BR-BNI', 'city' => 'Beni', 'area' => 'Campus Chowk', 'address' => 'Campus Chowk, Beni, Myagdi', 'type' => 'branch'],
            ['name' => 'Damauli Branch', 'code' => 'BR-DML', 'city' => 'Damauli', 'area' => 'Main Bazaar', 'address' => 'Highway Chowk, Damauli, Tanahu', 'type' => 'branch'],
            ['name' => 'Gorkha Branch', 'code' => 'BR-GKH', 'city' => 'Gorkha', 'area' => 'Old Buspark', 'address' => 'Old Buspark, Gorkha Bazaar', 'type' => 'branch'],
            ['name' => 'Kawasoti Branch', 'code' => 'BR-KWS', 'city' => 'Kawasoti', 'area' => 'Thana Chowk', 'address' => 'Highway Road, Kawasoti, Nawalpur', 'type' => 'branch'],
            ['name' => 'Besisahar Branch', 'code' => 'BR-BSS', 'city' => 'Besisahar', 'area' => 'Bazaar', 'address' => 'Main Bazaar, Besisahar, Lamjung', 'type' => 'branch'],
            ['name' => 'Syangja Branch', 'code' => 'BR-SYG', 'city' => 'Putalibazar', 'area' => 'Bazaar', 'address' => 'Shahid Chowk, Putalibazar, Syangja', 'type' => 'branch'],

            // Lumbini Province (Province 5)
            ['name' => 'Butwal Regional Hub', 'code' => 'BR-BTW', 'city' => 'Butwal', 'area' => 'Traffic Chowk', 'address' => 'Traffic Chowk, Butwal, Rupandehi', 'type' => 'regional_hub'],
            ['name' => 'Bhairahawa Branch', 'code' => 'BR-BHR', 'city' => 'Bhairahawa', 'area' => 'Bank Road', 'address' => 'Bank Road, Bhairahawa, Rupandehi', 'type' => 'branch'],
            ['name' => 'Nepalgunj Regional Hub', 'code' => 'BR-NPJ', 'city' => 'Nepalgunj', 'area' => 'Dhamboji Chowk', 'address' => 'Dhamboji Chowk, Nepalgunj, Banke', 'type' => 'regional_hub'],
            ['name' => 'Kohalpur Junction Hub', 'code' => 'BR-KHL', 'city' => 'Kohalpur', 'area' => 'Chauraha', 'address' => 'Kohalpur Chauraha, Banke', 'type' => 'branch'],
            ['name' => 'Ghorahi Hub', 'code' => 'BR-GHR', 'city' => 'Ghorahi', 'area' => 'Tulsipur Chowk', 'address' => 'Traffic Chowk, Ghorahi, Dang', 'type' => 'branch'],
            ['name' => 'Tulsipur Branch', 'code' => 'BR-TLS', 'city' => 'Tulsipur', 'area' => 'Buspark', 'address' => 'Buspark Road, Tulsipur, Dang', 'type' => 'branch'],
            ['name' => 'Tansen Palpa Branch', 'code' => 'BR-TNS', 'city' => 'Tansen', 'area' => 'Silkhan Chowk', 'address' => 'Silkhan Chowk, Tansen, Palpa', 'type' => 'branch'],
            ['name' => 'Bardiya Branch', 'code' => 'BR-BRD', 'city' => 'Gulariya', 'area' => 'Main Chowk', 'address' => 'Main Chowk, Gulariya, Bardiya', 'type' => 'branch'],
            ['name' => 'Kapilvastu Branch', 'code' => 'BR-KPV', 'city' => 'Taulihawa', 'area' => 'Bazaar', 'address' => 'Main Bazaar, Taulihawa, Kapilvastu', 'type' => 'branch'],

            // Karnali Province (Province 6)
            ['name' => 'Surkhet Regional Hub', 'code' => 'BR-SKT', 'city' => 'Birendranagar', 'area' => 'Mangalgarhi Chowk', 'address' => 'Mangalgarhi Chowk, Birendranagar, Surkhet', 'type' => 'regional_hub'],
            ['name' => 'Dailekh Branch', 'code' => 'BR-DLK', 'city' => 'Dailekh', 'area' => 'Bazaar', 'address' => 'Purano Bazaar, Narayan, Dailekh', 'type' => 'branch'],
            ['name' => 'Jumla Branch', 'code' => 'BR-JML', 'city' => 'Chandannath', 'area' => 'Khalanga', 'address' => 'Khalanga Bazaar, Chandannath, Jumla', 'type' => 'branch'],
            ['name' => 'Salyan Branch', 'code' => 'BR-SLN', 'city' => 'Salyan', 'area' => 'Srinagar', 'address' => 'Srinagar Chowk, Sharada, Salyan', 'type' => 'branch'],
            ['name' => 'Jajarkot Branch', 'code' => 'BR-JJK', 'city' => 'Khalanga', 'area' => 'Bazaar', 'address' => 'Main Chowk, Bheri, Jajarkot', 'type' => 'branch'],

            // Sudurpashchim Province (Province 7)
            ['name' => 'Dhangadhi Regional Hub', 'code' => 'BR-DGD', 'city' => 'Dhangadhi', 'area' => 'Chauraha', 'address' => 'Main Road, Chauraha, Dhangadhi, Kailali', 'type' => 'regional_hub'],
            ['name' => 'Mahendranagar Hub', 'code' => 'BR-MHN', 'city' => 'Bhimdatta', 'area' => 'Traffic Chowk', 'address' => 'Traffic Chowk, Mahendranagar, Kanchanpur', 'type' => 'branch'],
            ['name' => 'Attariya Junction Hub', 'code' => 'BR-ATR', 'city' => 'Attariya', 'area' => 'Highway Chauraha', 'address' => 'Highway Chauraha, Godawari, Kailali', 'type' => 'branch'],
            ['name' => 'Tikapur Branch', 'code' => 'BR-TKP', 'city' => 'Tikapur', 'area' => 'Khadaula', 'address' => 'Block A, Tikapur, Kailali', 'type' => 'branch'],
            ['name' => 'Dadeldhura Branch', 'code' => 'BR-DDL', 'city' => 'Amargadhi', 'area' => 'Tuphan Danda', 'address' => 'Tuphan Danda, Amargadhi, Dadeldhura', 'type' => 'branch'],
            ['name' => 'Dipayal Silgadhi Branch', 'code' => 'BR-DPS', 'city' => 'Dipayal Silgadhi', 'area' => 'Pipalla', 'address' => 'Pipalla Bazaar, Dipayal, Doti', 'type' => 'branch'],
            ['name' => 'Baitadi Branch', 'code' => 'BR-BTD', 'city' => 'Dasharathchand', 'area' => 'Gothalapani', 'address' => 'Gothalapani, Dasharathchand, Baitadi', 'type' => 'branch'],
        ];

        foreach ($masterBranches as $b) {
            if (!in_array($b['code'], $existingCodes)) {
                DB::table('branches')->insert([
                    'name' => $b['name'],
                    'code' => $b['code'],
                    'type' => $b['type'],
                    'phone' => '01-' . rand(4000000, 5999999),
                    'email' => strtolower(str_replace([' ', '&', '(', ')'], '', $b['code'])) . '@tukaatu.com',
                    'city' => $b['city'],
                    'area' => $b['area'],
                    'address' => $b['address'],
                    'latitude' => $cityCoordinates[$b['city']]['lat'] ?? 27.6951753,
                    'longitude' => $cityCoordinates[$b['city']]['lng'] ?? 85.3408307,
                    'status' => 'active',
                    'created_at' => now()->subYears(2),
                    'updated_at' => now()->subYears(2),
                ]);
            }
        }

        return DB::table('branches')->select('id', 'name', 'code', 'city', 'area', 'latitude', 'longitude')->get();
    }

    private function buildWeightedDestBranches($branches): array
    {
        $weighted = [];
        $priorityCities = [
            'Kathmandu' => 25, 'Lalitpur' => 10, 'Bhaktapur' => 6, 'Pokhara' => 12,
            'Biratnagar' => 8, 'Birgunj' => 7, 'Butwal' => 8, 'Bharatpur' => 7,
            'Nepalgunj' => 5, 'Dhangadhi' => 4, 'Dharan' => 4, 'Janakpur' => 4,
            'Bhimdatta' => 3, 'Hetauda' => 3, 'Itahari' => 3, 'Birtamod' => 3,
        ];

        foreach ($branches as $b) {
            $weight = $priorityCities[$b->city] ?? 1;
            for ($k = 0; $k < $weight; $k++) {
                $weighted[] = $b;
            }
        }

        return $weighted;
    }

    private function ensureMerchantsAndLocations($branches): array
    {
        $existing = DB::table('merchants')->get()->keyBy('code');

        $list = [];

        // Tier 1: 15 Enterprise Platforms & National Key Accounts (Weight = 40)
        $tier1 = [
            ['name' => 'Daraz Flagship Express Mall', 'code' => 'MKT-DARAZ-EXP', 'owner' => 'Kishore Shrestha', 'city' => 'Kathmandu', 'cat' => 'marketplace', 'weight' => 45],
            ['name' => 'Goldstar Shoes Official Flagship', 'code' => 'MKT-GOLDSTAR', 'owner' => 'Amir Pratap Rana', 'city' => 'Kathmandu', 'cat' => 'footwear', 'weight' => 40],
            ['name' => 'CG Digital Electronics E-Store', 'code' => 'MKT-CG-DIGITAL', 'owner' => 'Niranjan Chaudhary', 'city' => 'Kathmandu', 'cat' => 'electronics', 'weight' => 38],
            ['name' => 'Bhatbhateni Online Superstore', 'code' => 'MKT-BHATBHATENI', 'owner' => 'Min Bahadur Gurung', 'city' => 'Kathmandu', 'cat' => 'grocery_fmcg', 'weight' => 42],
            ['name' => 'Xiaomi Mi Store Nepal', 'code' => 'MKT-XIAOMI-NP', 'owner' => 'Sourabh Kedia', 'city' => 'Kathmandu', 'cat' => 'mobile_tech', 'weight' => 35],
            ['name' => 'Remax Nepal Official Store', 'code' => 'MKT-REMAX-NP', 'owner' => 'Pemba Sherpa', 'city' => 'Kathmandu', 'cat' => 'accessories', 'weight' => 30],
            ['name' => 'BigMart Express Online Delivery', 'code' => 'MKT-BIGMART', 'owner' => 'Sunil Shrestha', 'city' => 'Kathmandu', 'cat' => 'fmcg', 'weight' => 32],
            ['name' => 'Samsung Plaza Authorized Direct', 'code' => 'MKT-SAMSUNG-PLZ', 'owner' => 'Rajan Vaidya', 'city' => 'Lalitpur', 'cat' => 'electronics', 'weight' => 28],
            ['name' => 'Miniso Life Nepal Direct', 'code' => 'MKT-MINISO-NP', 'owner' => 'Manish Agrawal', 'city' => 'Kathmandu', 'cat' => 'lifestyle', 'weight' => 25],
            ['name' => 'Hoco Official Tech Nepal', 'code' => 'MKT-HOCO-OFFICIAL', 'owner' => 'Sonam Tamang', 'city' => 'Kathmandu', 'cat' => 'gadgets', 'weight' => 25],
            ['name' => 'Jagadamba Textiles Mega Hub', 'code' => 'MKT-JAGADAMBA-TEX', 'owner' => 'Rameshwor Shah', 'city' => 'Birgunj', 'cat' => 'textiles', 'weight' => 26],
            ['name' => 'Kiran Shoes Manufacturers', 'code' => 'MKT-KIRAN-SHOES', 'owner' => 'Bikram Thapa', 'city' => 'Biratnagar', 'cat' => 'footwear', 'weight' => 22],
            ['name' => 'Himalayan Java Retail Packs', 'code' => 'MKT-HIM-JAVA', 'owner' => 'Gagan Pradhan', 'city' => 'Kathmandu', 'cat' => 'food_beverage', 'weight' => 20],
            ['name' => 'Ekata Books World Direct', 'code' => 'MKT-EKATA-BOOKS', 'owner' => 'Prof. S. R. Sharma', 'city' => 'Kathmandu', 'cat' => 'books', 'weight' => 18],
            ['name' => 'Nutiva Premium Foods', 'code' => 'MKT-NUTIVA-PREM', 'owner' => 'Deepak Agrawal', 'city' => 'Bharatpur', 'cat' => 'organic_dryfruits', 'weight' => 20],
        ];

        // Tier 2: 65 Mid-Market Retailers & D2C Brands (Weight = 8)
        $tier2Prefixes = ['Nepal', 'Himalayan', 'Urban', 'Metro', 'Everest', 'Elite', 'Classic', 'Royal', 'Hamro', 'Patan', 'Pokhara', 'Chitwan'];
        $tier2Suffixes = [
            ['title' => 'Trekking & Mountaineering Gear', 'cat' => 'sports', 'city' => 'Pokhara'],
            ['title' => 'Ayurvedic Pharmacy & Herbs', 'cat' => 'pharmacy', 'city' => 'Kathmandu'],
            ['title' => 'Boutique Apparel Studio', 'cat' => 'fashion', 'city' => 'Lalitpur'],
            ['title' => 'Baby Care & Maternal Mart', 'cat' => 'baby_care', 'city' => 'Kathmandu'],
            ['title' => 'Smart Home & Solar Tech', 'cat' => 'tech', 'city' => 'Butwal'],
            ['title' => 'Beauty Care Cosmetics Lab', 'cat' => 'cosmetics', 'city' => 'Dharan'],
            ['title' => 'Organic Honey & Tea Estate', 'cat' => 'organic', 'city' => 'Biratnagar'],
            ['title' => 'Fitness Gym Equipment World', 'cat' => 'sports', 'city' => 'Kathmandu'],
            ['title' => 'Leather Bags & Accessories', 'cat' => 'accessories', 'city' => 'Kathmandu'],
            ['title' => 'Kitchenware & Crockery Mall', 'cat' => 'home', 'city' => 'Birgunj'],
            ['title' => 'Mithila Cultural Art & Crafts', 'cat' => 'crafts', 'city' => 'Janakpur'],
            ['title' => 'Computer Peripherals & Parts', 'cat' => 'it_hardware', 'city' => 'Nepalgunj'],
            ['title' => 'Natural Skincare Herbal Essence', 'cat' => 'beauty', 'city' => 'Kathmandu'],
        ];

        $tier2 = [];
        $t2Count = 0;
        foreach ($tier2Prefixes as $p) {
            foreach ($tier2Suffixes as $s) {
                if ($t2Count >= 65) break 2;
                $t2Count++;
                $cleanP = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $p), 0, 4));
                $tier2[] = [
                    'name' => "{$p} {$s['title']}",
                    'code' => sprintf('T2-%02d-%s', $t2Count, $cleanP),
                    'owner' => 'Founder ' . $p,
                    'city' => $s['city'],
                    'cat' => $s['cat'],
                    'weight' => 8,
                ];
            }
        }

        // Tier 3: 270 Boutique, Instagram & Social Media D2C Sellers (Weight = 1)
        $tier3Niche = ['Clothing', 'Crafts', 'Bakehouse', 'Jewels', 'Thrift', 'Prints', 'Shoes', 'Tea', 'Studio', 'Creations', 'Collectibles', 'Pashmina', 'Books', 'Organics', 'Accessories'];
        $tier3Cities = ['Kathmandu', 'Lalitpur', 'Bhaktapur', 'Pokhara', 'Butwal', 'Biratnagar', 'Dharan', 'Bharatpur', 'Hetauda', 'Nepalgunj', 'Janakpur', 'Dhangadhi'];
        $tier3 = [];
        for ($k = 1; $k <= 270; $k++) {
            $niche = $tier3Niche[($k - 1) % count($tier3Niche)];
            $tCity = $tier3Cities[($k - 1) % count($tier3Cities)];
            $cleanN = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $niche), 0, 4));
            $tier3[] = [
                'name' => sprintf('Studio %s #%d', $niche, $k),
                'code' => sprintf('D2C-%03d-%s', $k, $cleanN),
                'owner' => 'Creator ' . $k,
                'city' => $tCity,
                'cat' => strtolower($niche),
                'weight' => 1,
            ];
        }

        $allMerchants = array_merge($tier1, $tier2, $tier3);

        foreach ($allMerchants as $mIdx => $m) {
            $branch = $branches->firstWhere('city', $m['city']) ?: $branches->first();

            // Set a realistic distribution of applications for the Merchant Applications section
            $mStatus = 'approved';
            if ($mIdx >= 80 && $mIdx < 88) {
                $mStatus = 'pending';
            } elseif ($mIdx >= 88 && $mIdx < 92) {
                $mStatus = 'under_review';
            } elseif ($mIdx >= 92 && $mIdx < 95) {
                $mStatus = 'rejected';
            }

            if ($existing->has($m['code'])) {
                $mId = $existing->get($m['code'])->id;
            } else {
                $mId = DB::table('merchants')->insertGetId([
                    'name' => $m['name'],
                    'code' => $m['code'],
                    'owner_name' => $m['owner'],
                    'contact_person' => $m['owner'],
                    'phone' => '98' . rand(10000000, 99999999),
                    'email' => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $m['code'])) . '@merchant.tukaatu.com',
                    'business_type' => $m['cat'],
                    'pan_vat_number' => (string) rand(100000000, 999999999),
                    'city' => $m['city'],
                    'address' => $m['city'] . ' Commercial Complex, Ward ' . rand(1, 10),
                    'default_branch_id' => $branch->id,
                    'marketplace_id' => ($m['weight'] >= 8) ? 4 : null,
                    'status' => $mStatus,
                    'created_at' => now()->subYears(2),
                    'updated_at' => now()->subYears(2),
                ]);
            }

            $pLoc = DB::table('merchant_pickup_locations')->where('merchant_id', $mId)->first();
            if ($pLoc) {
                $pLocId = $pLoc->id;
            } else {
                $pLocId = DB::table('merchant_pickup_locations')->insertGetId([
                    'merchant_id' => $mId,
                    'branch_id' => $branch->id,
                    'name' => $m['name'] . ' Primary Warehouse',
                    'contact_person' => $m['owner'],
                    'phone' => '98' . rand(10000000, 99999999),
                    'city' => $m['city'],
                    'address' => $m['city'] . ' Hub Depot, Ward ' . rand(1, 10),
                    'latitude' => (float) ($branch->latitude ?: 27.6951753),
                    'longitude' => (float) ($branch->longitude ?: 85.3408307),
                    'is_default' => 1,
                    'status' => 'active',
                    'created_at' => now()->subYears(2),
                    'updated_at' => now()->subYears(2),
                ]);
            }

            $list[] = [
                'id' => $mId,
                'name' => $m['name'],
                'phone' => '98' . rand(10000000, 99999999),
                'city' => $m['city'],
                'address' => $m['city'] . ' Hub Depot',
                'branch_id' => $branch->id,
                'pickup_location_id' => $pLocId,
                'weight' => $m['weight'],
            ];
        }

        // Also incorporate any pre-existing merchants from earlier dump/seeders
        foreach ($existing as $exCode => $exMerchant) {
            if (!in_array($exCode, array_column($allMerchants, 'code'))) {
                $pLoc = DB::table('merchant_pickup_locations')->where('merchant_id', $exMerchant->id)->first();
                $list[] = [
                    'id' => $exMerchant->id,
                    'name' => $exMerchant->name,
                    'phone' => $exMerchant->phone ?? '9841000000',
                    'city' => $exMerchant->city ?? 'Kathmandu',
                    'address' => $exMerchant->address ?? 'Trade Center',
                    'branch_id' => $exMerchant->default_branch_id ?: $pLoc?->branch_id ?: $branches->first()->id,
                    'pickup_location_id' => $pLoc?->id ?: null,
                    'weight' => 2,
                ];
            }
        }

        return $list;
    }

    private function ensureRiders($branches): array
    {
        $existingRiders = DB::table('users')->where('role', 'rider')->orWhere('role', 'delivery_staff')->get();
        if ($existingRiders->count() >= 120) {
            $map = [];
            foreach ($existingRiders as $r) {
                $bId = $r->branch_id ?: $branches->first()->id;
                $map[$bId][] = $r->id;
            }
            return $map;
        }

        $riderMap = [];
        $riderIndex = 1;
        $existingEmails = DB::table('users')->pluck('id', 'email')->all();

        // Rider distribution by branch size
        $riderQuotas = [
            'Kathmandu' => 25, 'Lalitpur' => 10, 'Bhaktapur' => 6, 'Pokhara' => 10,
            'Biratnagar' => 8, 'Birgunj' => 7, 'Butwal' => 8, 'Bharatpur' => 7,
            'Nepalgunj' => 5, 'Dhangadhi' => 4, 'Dharan' => 4, 'Janakpur' => 4,
            'Hetauda' => 3, 'Itahari' => 3, 'Birtamod' => 3, 'Bhimdatta' => 3,
        ];

        foreach ($branches as $branch) {
            $quota = $riderQuotas[$branch->city] ?? 2;
            for ($k = 1; $k <= $quota; $k++) {
                $riderEmail = sprintf('rider%d.%s@tukaatu.com', $riderIndex, strtolower(substr($branch->code, 3)));
                if (isset($existingEmails[$riderEmail])) {
                    $riderId = $existingEmails[$riderEmail];
                } else {
                    $riderId = DB::table('users')->insertGetId([
                        'branch_id' => $branch->id,
                        'name' => sprintf('Rider %s #%d', $branch->city, $k),
                        'email' => $riderEmail,
                        'phone' => '98' . rand(10000000, 99999999),
                        'role' => 'rider',
                        'password' => bcrypt('rider123'),
                        'is_active' => 1,
                        'created_at' => now()->subYears(2),
                        'updated_at' => now()->subYears(2),
                    ]);
                }

                $riderMap[$branch->id][] = $riderId;
                $riderIndex++;
            }
        }

        return $riderMap;
    }

    private function ensureFleet($branchIds): array
    {
        $existingVehicles = Schema::hasTable('transport_vehicles') ? DB::table('transport_vehicles')->get() : collect();
        if ($existingVehicles->count() >= 30) {
            return [
                'vehicles' => $existingVehicles->all(),
                'drivers' => Schema::hasTable('transport_drivers') ? DB::table('transport_drivers')->get()->all() : [],
            ];
        }

        $vehicles = [];
        $drivers = [];

        if (Schema::hasTable('transport_vehicles')) {
            $existingPlates = DB::table('transport_vehicles')->pluck('id', 'vehicle_number')->all();

            $plates = [
                // 10 Heavy 22ft Trucks (East-West Highway Trunk)
                ['plate' => 'NA 5 KHA 8912', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'BA 3 KHA 1102', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'LU 2 KHA 7741', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'ME 1 KHA 2290', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'KO 1 KHA 9081', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'NA 6 KHA 3345', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'BA 4 KHA 5512', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'GA 3 KHA 4410', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'SE 1 KHA 6632', 'type' => 'heavy_truck_22ft', 'cap' => 8500],
                ['plate' => 'BHE 1 KHA 7819', 'type' => 'heavy_truck_22ft', 'cap' => 8500],

                // 12 Medium 14ft Feeder Trucks
                ['plate' => 'BA 2 KHA 4521', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'LU 1 KHA 7744', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'GA 1 KHA 8820', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'KO 2 KHA 3319', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'NA 4 KHA 1205', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'BA 5 KHA 9921', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'ME 2 KHA 6711', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'SE 2 KHA 4519', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'GA 2 KHA 3108', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'LU 3 KHA 2240', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'BA 1 KHA 7712', 'type' => 'mini_truck_14ft', 'cap' => 3500],
                ['plate' => 'KO 3 KHA 5509', 'type' => 'mini_truck_14ft', 'cap' => 3500],

                // 8 Hill 4x4 Bolero Pickups (Mid-Hill, Karnali, Far-West)
                ['plate' => 'BA 1 JHA 3211', 'type' => 'pickup', 'cap' => 1200],
                ['plate' => 'GA 1 CHA 5510', 'type' => 'pickup', 'cap' => 1200],
                ['plate' => 'LU 1 CHA 9912', 'type' => 'pickup', 'cap' => 1200],
                ['plate' => 'KO 1 CHA 4432', 'type' => 'pickup', 'cap' => 1200],
                ['plate' => 'KA 1 CHA 1109', 'type' => 'pickup', 'cap' => 1200],
                ['plate' => 'SE 1 CHA 8821', 'type' => 'pickup', 'cap' => 1200],
                ['plate' => 'ME 1 CHA 7714', 'type' => 'pickup', 'cap' => 1200],
                ['plate' => 'BA 2 CHA 6601', 'type' => 'pickup', 'cap' => 1200],

                // 6 Valley Feeder Vans (Maruti Eeco)
                ['plate' => 'BA 1 PA 4421', 'type' => 'van', 'cap' => 600],
                ['plate' => 'BA 2 CHA 5619', 'type' => 'van', 'cap' => 600],
                ['plate' => 'BA 3 CHA 7812', 'type' => 'van', 'cap' => 600],
                ['plate' => 'BA 4 CHA 9015', 'type' => 'van', 'cap' => 600],
                ['plate' => 'BA 5 CHA 3310', 'type' => 'van', 'cap' => 600],
                ['plate' => 'BA 6 CHA 2289', 'type' => 'van', 'cap' => 600],
            ];

            foreach ($plates as $p) {
                if (isset($existingPlates[$p['plate']])) {
                    $vId = $existingPlates[$p['plate']];
                } else {
                    $vId = DB::table('transport_vehicles')->insertGetId([
                        'vehicle_number' => $p['plate'],
                        'vehicle_type' => $p['type'],
                        'ownership_type' => 'company_owned',
                        'capacity_kg' => $p['cap'],
                        'current_branch_id' => $branchIds[array_rand($branchIds)],
                        'status' => 'available',
                        'is_active' => 1,
                        'created_at' => now()->subYears(2),
                        'updated_at' => now()->subYears(2),
                    ]);
                }
                $vehicles[] = (object) ['id' => $vId, 'vehicle_number' => $p['plate'], 'vehicle_type' => $p['type']];
            }
        }

        if (Schema::hasTable('transport_drivers')) {
            $existingDrivers = DB::table('transport_drivers')->pluck('id', 'name')->all();

            $driverNames = [
                'Ram Bahadur Thapa', 'Hari Prasad Sharma', 'Bikash Tamang', 'Krishna Gurung', 'Dinesh Yadav',
                'Suraj Magar', 'Manoj Chaudhary', 'Gopal Shrestha', 'Khem Raj Poudel', 'Dipendra Rai',
                'Sujan KC', 'Lokendra Bohara', 'Sunil Tiwari', 'Mahesh Mahato', 'Dhruba Basnet',
                'Prem Bahadur Karki', 'Rabi Shakya', 'Ganesh Adhikari', 'Tanka Prasad Bhatta', 'Narayan Joshi',
                'Ashok Kumar Sah', 'Bhim Bahadur Ale', 'Kiran Lama', 'Sanjiv Giri', 'Chitra Bahadur Rana',
                'Purna Shrestha', 'Rajan Pandey', 'Roshan Nepali', 'Bharat Regmi', 'Shanker Tamang',
                'Devendra Rawal', 'Khadga Bahadur KC', 'Subash BK', 'Tej Prasad Aryal', 'Yogesh Dahal',
                'Anil Bista', 'Dhan Bahadur Thapa', 'Padam Bahadur Oli', 'Rishi Ram Sapkota', 'Birbal Yadav',
            ];

            foreach ($driverNames as $idx => $dName) {
                if (isset($existingDrivers[$dName])) {
                    $dId = $existingDrivers[$dName];
                } else {
                    $dId = DB::table('transport_drivers')->insertGetId([
                        'name' => $dName,
                        'phone' => '980' . rand(1000000, 9999999),
                        'license_number' => sprintf('DL-NP-COMM-%06d', rand(100000, 999999)),
                        'base_branch_id' => $branchIds[array_rand($branchIds)],
                        'status' => 'available',
                        'is_active' => 1,
                        'created_at' => now()->subYears(2),
                        'updated_at' => now()->subYears(2),
                    ]);
                }
                $drivers[] = (object) ['id' => $dId, 'name' => $dName];
            }
        }

        return ['vehicles' => $vehicles, 'drivers' => $drivers];
    }

    private function createTransferRunsAndTrips(array $shipmentIds, $branches, $fleet, $date): void
    {
        $chunk = array_slice($shipmentIds, 0, 30);
        $fromBranch = $branches->random();
        $toBranch = $branches->random();
        if ($fromBranch->id === $toBranch->id) return;

        $this->manifestSeq++;
        $trNumber = sprintf('TR-%s-%06d', strtoupper(substr($fromBranch->code ?? 'BR', 3, 3) ?: 'TR'), $this->baseManifestId + $this->manifestSeq);
        $manifestNumber = sprintf('MF-%s-%06d', $date->format('ymd'), $this->baseManifestId + $this->manifestSeq);
        $seal = 'SEAL-' . strtoupper(Str::random(6));

        $manifestId = DB::table('dispatch_manifests')->insertGetId([
            'manifest_number' => $manifestNumber,
            'transfer_number' => $trNumber,
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'vehicle_number' => !empty($fleet['vehicles']) ? $fleet['vehicles'][array_rand($fleet['vehicles'])]->vehicle_number : 'BA 2 KHA 4521',
            'driver_name' => !empty($fleet['drivers']) ? $fleet['drivers'][array_rand($fleet['drivers'])]->name : 'Ram Bahadur Thapa',
            'driver_phone' => '9801234567',
            'seal_number' => $seal,
            'seal_status' => 'ok',
            'status' => 'received',
            'expected_count' => count($chunk),
            'received_count' => count($chunk),
            'dispatched_at' => (clone $date)->addHours(4),
            'received_at' => (clone $date)->addHours(14),
            'created_at' => $date,
            'updated_at' => (clone $date)->addHours(14),
        ]);

        if (Schema::hasTable('dispatch_manifest_items')) {
            $items = [];
            foreach ($chunk as $sid) {
                $items[] = [
                    'dispatch_manifest_id' => $manifestId,
                    'shipment_id' => $sid,
                    'status' => 'received',
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            DB::table('dispatch_manifest_items')->insert($items);
        }

        // Highway Line-haul Trip (Transportation Expenses)
        if (Schema::hasTable('transport_trips') && !empty($fleet['vehicles'])) {
            $this->tripSeq++;
            $tripNumber = sprintf('TRIP-%s-%06d', $date->format('ymd'), $this->baseTripId + $this->tripSeq);
            $tripId = DB::table('transport_trips')->insertGetId([
                'trip_number' => $tripNumber,
                'from_branch_id' => $fromBranch->id,
                'to_branch_id' => $toBranch->id,
                'vehicle_id' => $fleet['vehicles'][array_rand($fleet['vehicles'])]->id ?? null,
                'driver_id' => !empty($fleet['drivers']) ? $fleet['drivers'][array_rand($fleet['drivers'])]->id : null,
                'status' => 'completed',
                'departure_seal_number' => $seal,
                'arrival_seal_status' => 'ok',
                'total_manifests_count' => 1,
                'total_parcels_count' => count($chunk),
                'freight_cost' => (float) rand(3500, 8500),
                'departed_at' => (clone $date)->addHours(4),
                'arrived_at' => (clone $date)->addHours(14),
                'completed_at' => (clone $date)->addHours(14),
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            if (Schema::hasTable('transport_trip_manifests')) {
                DB::table('transport_trip_manifests')->insert([
                    'transport_trip_id' => $tripId,
                    'dispatch_manifest_id' => $manifestId,
                    'status' => 'received',
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    private function createDeliveryRuns(array $shipmentIds, $branches, array $riderBranchMap, $date): void
    {
        $chunk = array_slice($shipmentIds, 0, 20);
        $branch = $branches->random();
        $this->deliveryRunSeq++;
        $runNum = sprintf('DR-%s-%06d', $date->format('ymd'), $this->baseRunId + $this->deliveryRunSeq);

        $branchRiders = $riderBranchMap[$branch->id] ?? reset($riderBranchMap);
        $riderId = $branchRiders[array_rand($branchRiders)];
        $totalCollected = count($chunk) * 1800.00;

        $drId = DB::table('delivery_runs')->insertGetId([
            'run_number' => $runNum,
            'branch_id' => $branch->id,
            'rider_user_id' => $riderId,
            'vehicle_type' => 'bike',
            'status' => 'completed',
            'expected_count' => count($chunk),
            'delivered_count' => count($chunk),
            'failed_count' => 0,
            'returned_count' => 0,
            'reattempt_count' => 0,
            'cod_expected' => $totalCollected,
            'cod_collected' => $totalCollected,
            'cash_received' => $totalCollected,
            'cash_variance' => 0.00,
            'cash_deposited' => 1,
            'dispatched_at' => (clone $date)->addHours(2),
            'completed_at' => (clone $date)->addHours(6),
            'created_at' => $date,
            'updated_at' => (clone $date)->addHours(6),
        ]);

        if (Schema::hasTable('delivery_run_items')) {
            $items = [];
            foreach ($chunk as $sid) {
                $items[] = [
                    'delivery_run_id' => $drId,
                    'shipment_id' => $sid,
                    'status' => 'delivered',
                    'outcome' => 'delivered',
                    'attempt' => 1,
                    'cod_amount' => 1800.00,
                    'collected_amount' => 1800.00,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            DB::table('delivery_run_items')->insert($items);
        }

        // Rider Cash Deposit to Branch Cashier (Cash Ledger)
        if (Schema::hasTable('rider_cash_deposits')) {
            $this->depositSeq++;
            DB::table('rider_cash_deposits')->insert([
                'deposit_number' => sprintf('DEP-%s-%06d', $date->format('ymd'), $this->baseDepositId + $this->depositSeq),
                'rider_id' => $riderId,
                'branch_id' => $branch->id,
                'amount' => $totalCollected,
                'status' => 'confirmed',
                'confirmed_by' => 1,
                'confirmed_at' => (clone $date)->addHours(7),
                'remarks' => 'Daily delivery run cash verified and confirmed by branch cashier',
                'created_at' => (clone $date)->addHours(6),
                'updated_at' => (clone $date)->addHours(7),
            ]);
        }

        // Branch Cash Deposit to Bank / Vault
        if (Schema::hasTable('pod_deposits')) {
            DB::table('pod_deposits')->insert([
                'branch_id' => $branch->id,
                'staff_id' => 1,
                'amount' => $totalCollected,
                'status' => 'approved',
                'remarks' => 'End-of-day branch cash collection deposited to bank account',
                'created_at' => (clone $date)->addHours(8),
                'updated_at' => (clone $date)->addHours(8),
            ]);
        }
    }

    private function createMerchantSettlement(array $items, $date): void
    {
        $mId = $items[0]['merchant_id'];
        $totalPod = array_sum(array_column($items, 'pod_amount'));
        $totalDelivery = array_sum(array_column($items, 'delivery_charge'));
        $totalReturns = array_sum(array_column($items, 'return_charge'));
        $finalPayable = max(0, $totalPod - $totalDelivery - $totalReturns);

        $this->settlementSeq++;
        $setNumber = sprintf('SET-%s-%06d', $date->format('ymd'), $this->baseSettlementId + $this->settlementSeq);

        $setId = DB::table('merchant_settlements')->insertGetId([
            'merchant_id' => $mId,
            'settlement_number' => $setNumber,
            'period_from' => (clone $date)->startOfMonth()->toDateString(),
            'period_to' => (clone $date)->endOfMonth()->toDateString(),
            'total_pod_collected' => $totalPod,
            'total_delivery_charges' => $totalDelivery,
            'total_pod_charges' => 0.00,
            'return_charges' => $totalReturns,
            'adjustments' => 0.00,
            'final_payable_amount' => $finalPayable,
            'status' => 'completed',
            'cash_path' => 'bank_transfer',
            'payment_method' => 'esewa',
            'settled_at' => $date,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        if (Schema::hasTable('merchant_settlement_items')) {
            $rows = [];
            foreach (array_slice($items, 0, 40) as $it) {
                $rows[] = [
                    'merchant_settlement_id' => $setId,
                    'shipment_id' => $it['shipment_id'],
                    'pod_amount' => $it['pod_amount'],
                    'delivery_charge' => $it['delivery_charge'],
                    'pod_charge' => 0.00,
                    'net_amount' => $it['net_amount'],
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            DB::table('merchant_settlement_items')->insert($rows);
        }

        // Delivery Charges Invoice
        if (Schema::hasTable('invoices')) {
            $this->invoiceSeq++;
            $invNum = sprintf('INV-DLV-%s-%06d', $date->format('ymd'), $this->baseInvoiceId + $this->invoiceSeq);
            $billedAmount = $totalDelivery + $totalReturns;

            $invId = DB::table('invoices')->insertGetId([
                'merchant_id' => $mId,
                'branch_id' => 19,
                'invoice_number' => $invNum,
                'type' => 'delivery_charges',
                'payer_type' => 'merchant',
                'payee_type' => 'branch',
                'invoice_date' => $date->toDateString(),
                'subtotal' => $billedAmount,
                'tax_amount' => 0.00,
                'total_amount' => $billedAmount,
                'status' => 'paid',
                'paid_at' => $date,
                'paid_amount' => $billedAmount,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            if (Schema::hasTable('invoice_items')) {
                DB::table('invoice_items')->insert([
                    'invoice_id' => $invId,
                    'description' => sprintf('Logistics charges reconciliation for %d parcels (including %d returns)', count($items), count(array_filter($items, fn($i) => $i['return_charge'] > 0))),
                    'quantity' => count($items),
                    'unit_price' => round($billedAmount / max(1, count($items)), 2),
                    'total' => $billedAmount,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }

            // Payment Receipt for Invoice
            if (Schema::hasTable('payment_receipts')) {
                $this->receiptSeq++;
                DB::table('payment_receipts')->insert([
                    'invoice_id' => $invId,
                    'receipt_number' => sprintf('RCP-%s-%06d', $date->format('ymd'), $this->baseReceiptId + $this->receiptSeq),
                    'amount' => $billedAmount,
                    'payment_method' => 'esewa',
                    'reference_number' => 'TXN-ESEWA-' . strtoupper(Str::random(10)),
                    'paid_at' => $date,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }

            // Merchant Invoice Payments Record
            if (Schema::hasTable('merchant_invoice_payments')) {
                DB::table('merchant_invoice_payments')->insert([
                    'invoice_id' => $invId,
                    'merchant_id' => $mId,
                    'branch_id' => 19,
                    'amount' => $billedAmount,
                    'method' => 'esewa',
                    'reference' => 'TXN-INV-PAY-' . strtoupper(Str::random(8)),
                    'paid_at' => $date,
                    'note' => 'Automatic invoice settlement deduction from monthly COD pool',
                    'source' => 'system_settlement',
                    'recorded_by_type' => 'system',
                    'status' => 'completed',
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    private function createMonthlyAccountingStatements($monthDate): void
    {
        // 1. Branch Commission Settlements (Branch -> HQ Royalty Clearance)
        if (Schema::hasTable('branch_commission_settlements') && Schema::hasTable('branch_commission_bills')) {
            $unsettled = DB::table('branch_commission_bills')
                ->whereNull('settlement_id')
                ->whereBetween('paid_at', [(clone $monthDate)->startOfMonth(), (clone $monthDate)->endOfMonth()])
                ->select('branch_id', DB::raw('SUM(commission_amount) as total_comm'), DB::raw('COUNT(*) as total_bills'))
                ->groupBy('branch_id')
                ->get();

            foreach ($unsettled as $row) {
                $setNum = sprintf('HQ-SET-%s-%04d', $monthDate->format('Ym'), $row->branch_id);
                $existing = DB::table('branch_commission_settlements')->where('settlement_number', $setNum)->first();
                if (!$existing) {
                    $sId = DB::table('branch_commission_settlements')->insertGetId([
                        'settlement_number' => $setNum,
                        'branch_id' => $row->branch_id,
                        'total_commission' => $row->total_comm,
                        'adjustments' => 0.00,
                        'final_payable_amount' => $row->total_comm,
                        'status' => 'paid',
                        'payment_method' => 'bank_transfer',
                        'gateway' => 'connect_ips',
                        'payment_reference' => 'NPI-' . rand(10000000, 99999999),
                        'paid_at' => (clone $monthDate)->endOfMonth(),
                        'created_at' => (clone $monthDate)->endOfMonth(),
                        'updated_at' => (clone $monthDate)->endOfMonth(),
                    ]);

                    DB::table('branch_commission_bills')
                        ->where('branch_id', $row->branch_id)
                        ->whereNull('settlement_id')
                        ->whereBetween('paid_at', [(clone $monthDate)->startOfMonth(), (clone $monthDate)->endOfMonth()])
                        ->update(['settlement_id' => $sId]);
                }
            }
        }

        // 2. Inter-Branch Balancing Statements (Destination Branch -> Origin Branch 40% Share Transfer)
        if (Schema::hasTable('inter_branch_statements') && Schema::hasTable('shipment_branch_shares')) {
            $pairs = DB::table('shipment_branch_shares')
                ->where('role', 'origin')
                ->where('status', 'settled')
                ->whereBetween('created_at', [(clone $monthDate)->startOfMonth(), (clone $monthDate)->endOfMonth()])
                ->whereNotNull('collecting_branch_id')
                ->whereColumn('branch_id', '!=', 'collecting_branch_id')
                ->select(
                    'collecting_branch_id as from_branch_id',
                    'branch_id as to_branch_id',
                    DB::raw('SUM(share_amount) as total_origin_owed'),
                    DB::raw('COUNT(*) as total_lines')
                )
                ->groupBy('collecting_branch_id', 'branch_id')
                ->limit(25)
                ->get();

            foreach ($pairs as $p) {
                $stmtNum = sprintf('IBS-%s-%03d-%03d', $monthDate->format('Ym'), $p->from_branch_id, $p->to_branch_id);
                $existing = DB::table('inter_branch_statements')->where('statement_number', $stmtNum)->first();
                if (!$existing) {
                    DB::table('inter_branch_statements')->insert([
                        'statement_number' => $stmtNum,
                        'from_branch_id' => $p->from_branch_id,
                        'to_branch_id' => $p->to_branch_id,
                        'period_start' => (clone $monthDate)->startOfMonth()->toDateString(),
                        'period_end' => (clone $monthDate)->endOfMonth()->toDateString(),
                        'total_amount' => $p->total_origin_owed,
                        'line_count' => $p->total_lines,
                        'status' => 'paid',
                        'issued_at' => (clone $monthDate)->endOfMonth(),
                        'paid_at' => (clone $monthDate)->endOfMonth(),
                        'payment_method' => 'bank_transfer',
                        'payment_reference' => 'RTGS-NP-' . rand(100000, 999999),
                        'notes' => 'Monthly inter-branch revenue share clearance',
                        'created_at' => (clone $monthDate)->endOfMonth(),
                        'updated_at' => (clone $monthDate)->endOfMonth(),
                    ]);
                }
            }
        }
    }

    private function createPickupRequestsAndRuns(
        $shipments,
        $branches,
        array $riderBranchMap,
        array $merchantMap,
        int $monthOffset,
        $monthDate
    ): void {
        $merchantShipments = [];
        foreach ($shipments as $s) {
            if ($s->status !== 'cancelled') {
                $merchantShipments[$s->merchant_id][] = $s;
            }
        }

        $merchantLookup = [];
        foreach ($merchantMap as $m) {
            $merchantLookup[$m['id']] = $m;
        }

        $prShipments = [];

        foreach ($merchantShipments as $mId => $sList) {
            $mInfo = $merchantLookup[$mId] ?? null;
            if (!$mInfo) continue;

            $chunks = array_chunk($sList, min(5, max(1, count($sList))));
            foreach ($chunks as $pack) {
                $this->pickupSeq++;
                $firstS = $pack[0];
                $sCreatedAt = \Illuminate\Support\Carbon::parse($firstS->created_at);
                $puNumber = sprintf('PU-%s-%06d', $sCreatedAt->format('ymd'), $this->basePickupId + $this->pickupSeq);

                $originRiders = $riderBranchMap[$firstS->origin_branch_id] ?? reset($riderBranchMap);
                $puRiderId = $originRiders[array_rand($originRiders)];

                $origBranch = $branches->firstWhere('id', $firstS->origin_branch_id);
                $puLat = round((float) ($origBranch?->latitude ?: 27.6951753) + (rand(-120, 120) / 10000), 7);
                $puLng = round((float) ($origBranch?->longitude ?: 85.3408307) + (rand(-120, 120) / 10000), 7);

                if ($monthOffset > 1 || $firstS->status === 'delivered') {
                    $puStatus = 'completed';
                } else {
                    $r = rand(1, 100);
                    if ($r <= 20) $puStatus = 'requested';
                    elseif ($r <= 35) $puStatus = 'assigned';
                    elseif ($r <= 50) $puStatus = 'accepted';
                    elseif ($r <= 65) $puStatus = 'started';
                    elseif ($r <= 78) $puStatus = 'arrived';
                    elseif ($r <= 90) $puStatus = 'collected';
                    else $puStatus = 'completed';
                }

                $puId = DB::table('pickup_requests')->insertGetId([
                    'request_number' => $puNumber,
                    'merchant_id' => $mId,
                    'pickup_location_id' => $mInfo['pickup_location_id'],
                    'store_reference' => sprintf('STOR-PU-%06d', $this->basePickupId + $this->pickupSeq),
                    'branch_id' => $firstS->origin_branch_id,
                    'pickup_branch_id' => $firstS->origin_branch_id,
                    'assigned_to' => in_array($puStatus, ['requested']) ? null : $puRiderId,
                    'picked_up_by' => in_array($puStatus, ['collected', 'completed']) ? $puRiderId : null,
                    'pickup_name' => $mInfo['name'],
                    'pickup_phone' => $mInfo['phone'],
                    'pickup_address' => $mInfo['address'] . ', Ward ' . rand(1, 10),
                    'pickup_city' => $mInfo['city'],
                    'pickup_area' => 'Commercial Hub',
                    'pickup_lat' => $puLat,
                    'pickup_lng' => $puLng,
                    'preferred_pickup_at' => (clone $sCreatedAt)->addHours(2),
                    'parcel_quantity' => count($pack),
                    'status' => $puStatus,
                    'remarks' => 'Merchant bulk parcel dispatch intake',
                    'requested_at' => $sCreatedAt,
                    'assigned_at' => in_array($puStatus, ['requested']) ? null : (clone $sCreatedAt)->addMinutes(30),
                    'accepted_at' => in_array($puStatus, ['requested', 'assigned']) ? null : (clone $sCreatedAt)->addMinutes(45),
                    'arrived_at' => in_array($puStatus, ['arrived', 'collected', 'completed']) ? (clone $sCreatedAt)->addMinutes(75) : null,
                    'picked_up_at' => in_array($puStatus, ['collected', 'completed']) ? (clone $sCreatedAt)->addHours(2) : null,
                    'received_at_origin_at' => $puStatus === 'completed' ? (clone $sCreatedAt)->addHours(3) : null,
                    'created_at' => $sCreatedAt,
                    'updated_at' => $sCreatedAt,
                ]);

                if (Schema::hasTable('pickup_request_shipments')) {
                    foreach ($pack as $pShipment) {
                        $prShipments[] = [
                            'pickup_request_id' => $puId,
                            'shipment_id' => $pShipment->id,
                            'added_at' => $sCreatedAt,
                            'status' => $puStatus === 'completed' ? 'picked_up' : 'pending',
                            'collection_status' => $puStatus === 'completed' ? 'collected' : 'pending',
                            'collected_at' => $puStatus === 'completed' ? (clone $sCreatedAt)->addHours(2) : null,
                            'collected_by' => $puStatus === 'completed' ? $puRiderId : null,
                            'created_at' => $sCreatedAt,
                            'updated_at' => $sCreatedAt,
                        ];
                    }
                }
            }
        }

        if (!empty($prShipments) && Schema::hasTable('pickup_request_shipments')) {
            foreach (array_chunk($prShipments, 500) as $chunkPRS) {
                DB::table('pickup_request_shipments')->insert($chunkPRS);
            }
        }
    }

    private function ensureCustomers(array $merchantMap, $branches): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        if (DB::table('customers')->count() >= 300) {
            return;
        }

        $nepalNames = [
            'Aarav Sharma', 'Suman Shrestha', 'Pooja Thapa', 'Bibek Adhikari', 'Sunil Gurung',
            'Anjali KC', 'Ramesh Dahal', 'Prakash Tamang', 'Bishal Poudel', 'Deepa Khadka',
            'Siddhartha Joshi', 'Anita Maharjan', 'Prashant Rai', 'Kiran Giri', 'Sita Magar',
            'Roshan Gautam', 'Sarita Acharya', 'Manish Yadav', 'Kabita Bhattarai', 'Binod Neupane',
            'Pradeep Karki', 'Mina Shrestha', 'Dipesh Baral', 'Sushma Basnet', 'Gopal Regmi',
            'Bijay Shrestha', 'Alisha Shakya', 'Nabin KC', 'Smriti Subedi', 'Ashish Thapa',
            'Kopila Chaudhary', 'Santosh Pandit', 'Reena Maharjan', 'Suraj Bajracharya', 'Mandira Rijal',
            'Dhiraj Shah', 'Laxmi Paudel', 'Hemant Khatri', 'Pramila Karki', 'Bipin Adhikari',
        ];

        $cities = [
            'Kathmandu' => ['New Baneshwor', 'Thamel', 'Chabahil', 'Koteshwor', 'Putalisadak', 'Tinkune'],
            'Lalitpur' => ['Lagankhel', 'Kupondole', 'Jawalakhel', 'Pulchowk', 'Sanepa'],
            'Bhaktapur' => ['Suryabinayak', 'Thimi', 'Sallaghari', 'Kamalbinayak'],
            'Pokhara' => ['Lakeside', 'Prithvi Chowk', 'Mahendrapool', 'Birauta', 'Talchowk'],
            'Butwal' => ['Traffic Chowk', 'Milanchowk', 'Golpark', 'Amarpath', 'Kalikanagar'],
            'Biratnagar' => ['Traffic Chowk', 'Main Road', 'Bargachhi', 'Rani', 'Tintoliya'],
            'Birgunj' => ['Adarshnagar', 'Ghantaghar', 'Maisthan', 'Bypass Road'],
            'Bharatpur' => ['Chaubiskothi', 'Lions Chowk', 'Pulchowk', 'Hakim Chowk'],
            'Dharan' => ['Bhanu Chowk', 'Putali Line', 'Chhata Chowk'],
            'Nepalgunj' => ['Dhamboji Chowk', 'Tribhuvan Chowk', 'Surkhet Road'],
            'Dhangadhi' => ['Chauraha', 'Main Road', 'Campus Chowk'],
            'Janakpur' => ['Station Road', 'Bhanu Chowk', 'Ramanand Chowk'],
        ];

        $customerRows = [];
        $existingPhones = DB::table('customers')->pluck('phone')->all();
        $existingPhoneMap = array_flip($existingPhones);

        for ($c = 1; $c <= 500; $c++) {
            $name = $nepalNames[array_rand($nepalNames)] . ' ' . (rand(1, 100) > 60 ? chr(65 + rand(0, 25)) . '.' : '');
            $city = array_rand($cities);
            $area = $cities[$city][array_rand($cities[$city])];
            $phone = '98' . rand(10000000, 99999999);
            while (isset($existingPhoneMap[$phone])) {
                $phone = '98' . rand(10000000, 99999999);
            }
            $existingPhoneMap[$phone] = true;

            $cleanName = strtolower(preg_replace('/[^a-zA-Z]/', '', $name));
            $email = $cleanName . rand(10, 99) . '@gmail.com';
            $mId = !empty($merchantMap) ? $merchantMap[array_rand($merchantMap)]['id'] : null;

            $customerRows[] = [
                'merchant_id' => $mId,
                'name' => trim($name),
                'phone' => $phone,
                'email' => $email,
                'city' => $city,
                'area' => $area,
                'address' => sprintf('%s, Ward %d, %s', $area, rand(1, 12), $city),
                'type' => rand(1, 100) <= 85 ? 'individual' : 'corporate',
                'created_at' => now()->subMonths(rand(1, 12)),
                'updated_at' => now()->subMonths(rand(0, 3)),
            ];
        }

        foreach (array_chunk($customerRows, 250) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $this->info(sprintf("✓ Retail Customer Directory: %d customers registered across nationwide urban hubs.", count($customerRows)));
    }

    private function ensureTransferLanesAndRoutes($branches): void
    {
        if (!Schema::hasTable('branch_transfer_lanes') || !Schema::hasTable('branch_transfer_routes')) {
            return;
        }

        if (DB::table('branch_transfer_lanes')->count() >= 15) {
            return;
        }

        $ktm = $branches->firstWhere('city', 'Kathmandu') ?: $branches->first();
        $nrg = $branches->firstWhere('city', 'Bharatpur') ?: $branches->first();
        $pkr = $branches->firstWhere('city', 'Pokhara') ?: $branches->first();
        $btw = $branches->firstWhere('city', 'Butwal') ?: $branches->first();
        $npj = $branches->firstWhere('city', 'Nepalgunj') ?: $branches->first();
        $dgd = $branches->firstWhere('city', 'Dhangadhi') ?: $branches->first();
        $brg = $branches->firstWhere('city', 'Birgunj') ?: $branches->first();
        $jnk = $branches->firstWhere('city', 'Janakpur') ?: $branches->first();
        $ith = $branches->firstWhere('city', 'Itahari') ?: $branches->first();
        $brt = $branches->firstWhere('city', 'Biratnagar') ?: $branches->first();
        $drn = $branches->firstWhere('city', 'Dharan') ?: $branches->first();
        $btm = $branches->firstWhere('city', 'Birtamod') ?: $branches->first();

        $lanes = [
            ['from' => $ktm, 'to' => $nrg, 'km' => 146, 'hrs' => 5.5, 'name' => 'Kathmandu - Narayangarh Highway Corridor'],
            ['from' => $nrg, 'to' => $pkr, 'km' => 126, 'hrs' => 4.5, 'name' => 'Narayangarh - Pokhara Corridor'],
            ['from' => $nrg, 'to' => $btw, 'km' => 114, 'hrs' => 3.5, 'name' => 'Narayangarh - Butwal Highway Corridor'],
            ['from' => $btw, 'to' => $npj, 'km' => 238, 'hrs' => 6.0, 'name' => 'Butwal - Nepalgunj Highway Corridor'],
            ['from' => $npj, 'to' => $dgd, 'km' => 187, 'hrs' => 4.5, 'name' => 'Nepalgunj - Dhangadhi Highway Corridor'],
            ['from' => $nrg, 'to' => $brg, 'km' => 132, 'hrs' => 4.0, 'name' => 'Narayangarh - Birgunj Commercial Trunk'],
            ['from' => $brg, 'to' => $jnk, 'km' => 156, 'hrs' => 4.5, 'name' => 'Birgunj - Janakpur Highway Corridor'],
            ['from' => $jnk, 'to' => $ith, 'km' => 210, 'hrs' => 5.5, 'name' => 'Janakpur - Itahari East Trunk'],
            ['from' => $ith, 'to' => $brt, 'km' => 24, 'hrs' => 0.8, 'name' => 'Itahari - Biratnagar Feeder Line'],
            ['from' => $ith, 'to' => $drn, 'km' => 18, 'hrs' => 0.6, 'name' => 'Itahari - Dharan Feeder Line'],
            ['from' => $ith, 'to' => $btm, 'km' => 85, 'hrs' => 2.2, 'name' => 'Itahari - Birtamod Eastern Trunk'],
            ['from' => $ktm, 'to' => $pkr, 'km' => 200, 'hrs' => 6.5, 'name' => 'Kathmandu - Pokhara Direct Expressway'],
            ['from' => $ktm, 'to' => $btw, 'km' => 260, 'hrs' => 7.5, 'name' => 'Kathmandu - Butwal Direct Corridor'],
            ['from' => $ktm, 'to' => $brg, 'km' => 135, 'hrs' => 4.2, 'name' => 'Kathmandu - Birgunj Fast-Track Line'],
            ['from' => $ktm, 'to' => $brt, 'km' => 375, 'hrs' => 10.0, 'name' => 'Kathmandu - Biratnagar Highway Trunk'],
        ];

        foreach ($lanes as $lane) {
            $fromId = $lane['from']?->id;
            $toId = $lane['to']?->id;
            if (!$fromId || !$toId || $fromId === $toId) continue;

            $laneId = DB::table('branch_transfer_lanes')->insertGetId([
                'from_branch_id' => $fromId,
                'to_branch_id' => $toId,
                'service_type' => 'standard',
                'transport_mode' => 'road',
                'distance_km' => $lane['km'],
                'estimated_hours' => $lane['hrs'],
                'checkpoints' => json_encode(['Highway Weighbridge', 'Transit Depot']),
                'variant_name' => 'Direct Highway',
                'path_signature' => "{$fromId}-{$toId}-STD",
                'priority' => 1,
                'is_active' => 1,
                'created_at' => now()->subYears(2),
                'updated_at' => now()->subYears(2),
            ]);

            DB::table('branch_transfer_routes')->insert([
                'route_code' => sprintf('RT-%03d-%03d', $fromId, $toId),
                'name' => $lane['name'],
                'branch_transfer_lane_id' => $laneId,
                'origin_branch_id' => $fromId,
                'destination_branch_id' => $toId,
                'service_type' => 'standard',
                'base_rate' => round($lane['km'] * 0.85, 2),
                'currency' => 'NPR',
                'distance_km' => $lane['km'],
                'estimated_hours' => $lane['hrs'],
                'transfer_count' => 0,
                'transit_count' => 0,
                'transit_branch_ids' => '[]',
                'stops' => json_encode([]),
                'total_distance_km' => $lane['km'],
                'total_estimated_hours' => $lane['hrs'],
                'priority' => 1,
                'is_default' => 1,
                'is_active' => 1,
                'notes' => 'Highway trunk route for interstate express line-haul',
                'created_at' => now()->subYears(2),
                'updated_at' => now()->subYears(2),
            ]);
        }

        $this->info("✓ Nationwide Highway Transport Grid: 15 arterial transfer lanes and routes activated.");
    }

    private function ensurePricingQuotes($branches, array $merchantMap): void
    {
        if (!Schema::hasTable('pricing_quotes')) {
            return;
        }

        if (DB::table('pricing_quotes')->count() >= 25) {
            return;
        }

        for ($q = 1; $q <= 25; $q++) {
            $m = !empty($merchantMap) ? $merchantMap[array_rand($merchantMap)] : null;
            $fromBranch = $branches->random();
            $toBranch = $branches->random();
            $weight = round(rand(5, 50) / 10, 2);
            $dist = round(rand(20, 250) / 10, 1);
            $baseFare = round(90 + ($dist * 5) + ($weight * 20), 2);

            DB::table('pricing_quotes')->insert([
                'quote_number' => sprintf('QUO-%s-%05d', now()->format('ymd'), $q),
                'merchant_id' => $m ? $m['id'] : null,
                'pickup_branch_id' => $fromBranch->id,
                'delivery_branch_id' => $toBranch->id,
                'pickup_address' => ($fromBranch->city ?: 'Kathmandu') . ' Warehouse',
                'pickup_latitude' => (float) ($fromBranch->latitude ?: 27.6951753),
                'pickup_longitude' => (float) ($fromBranch->longitude ?: 85.3408307),
                'delivery_address' => ($toBranch->city ?: 'Pokhara') . ' Residential Drop',
                'delivery_latitude' => (float) ($toBranch->latitude ?: 28.2077274),
                'delivery_longitude' => (float) ($toBranch->longitude ?: 83.9899107),
                'distance_km' => $dist,
                'parcel_weight' => $weight,
                'chargeable_weight' => $weight,
                'parcel_value' => rand(1000, 15000),
                'packet_count' => 1,
                'service_type' => 'standard',
                'base_price' => $baseFare,
                'weight_charge' => round($weight * 20, 2),
                'distance_charge' => round($dist * 5, 2),
                'subtotal' => $baseFare,
                'final_price' => $baseFare,
                'currency' => 'NPR',
                'snapshot_json' => json_encode(['base_fare' => $baseFare, 'weight' => $weight]),
                'status' => rand(1, 100) <= 70 ? 'accepted' : 'pending',
                'expires_at' => now()->addDays(7),
                'created_at' => now()->subDays(rand(1, 30)),
                'updated_at' => now()->subDays(rand(1, 30)),
            ]);
        }
    }

    protected function cleanPreviousSeedData(): void
    {
        $this->warn("⚠ --fresh option enabled: Truncating previous demo delivery & financial transaction ledger tables...");

        $tables = [
            'shipment_route_steps',
            'delivery_route_segments',
            'delivery_attempts',
            'tracking_events',
            'shipment_items',
            'shipment_price_breakdowns',
            'shipment_branch_shares',
            'inter_branch_statement_lines',
            'inter_branch_statements',
            'branch_commission_bills',
            'branch_commission_settlements',
            'pod_records',
            'pod_collections',
            'pod_deposits',
            'rider_cash_deposits',
            'delivery_run_items',
            'delivery_runs',
            'transport_trip_manifests',
            'transport_trips',
            'dispatch_manifest_items',
            'dispatch_manifests',
            'merchant_settlement_items',
            'merchant_settlements',
            'payment_receipts',
            'merchant_invoice_payments',
            'invoice_items',
            'invoices',
            'pickup_run_items',
            'pickup_runs',
            'pickup_request_shipments',
            'pickup_requests',
            'pricing_quote_items',
            'pricing_quotes',
            'customers',
            'transfer_batch_items',
            'transfer_shipment_trackers',
            'delivery_assignments',
            'shipments',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info("✓ Cleaned transactional tables. Ready for fresh high-performance seeding.");
    }
}
