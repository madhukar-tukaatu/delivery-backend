# Pickup Workflow Guide - STRICT MODE

## Overview

The pickup workflow manages the complete lifecycle of a pickup request from creation through completion by a rider, then reception at origin branch for sorting and routing.

**KEY PRINCIPLE**: Rider must ACCEPT before STARTING. New pickups are BLOCKED until previous pickup is COMPLETED.

## Pickup Statuses

### States
- **REQUESTED** - Pickup created, awaiting assignment
- **ASSIGNED** - Rider assigned to pickup
- **ACCEPTED** - Rider has accepted the assignment
- **STARTED** - Rider has started travelling to merchant
- **ARRIVED** - Rider has arrived at merchant location
- **COMPLETED** - All shipments collected successfully
- **FAILED** - Pickup failed before completion
- **CANCELLED** - Pickup was cancelled

### State Machine
```
REQUESTED
    ↓ (assign)
ASSIGNED
    ↓ (accept)
ACCEPTED
    ↓ (start)
STARTED
    ↓ (arrive)
ARRIVED
    ↓ (complete - after collecting all shipments)
COMPLETED
```

Alternative paths:
- ASSIGNED → FAILED (skip accept/start if failed before starting)
- REQUESTED/ASSIGNED/ACCEPTED/STARTED/ARRIVED → CANCELLED

## Shipment Consolidation

### KEY RULE: NO MULTIPLE ACTIVE PICKUPS
**STRICT MODE**: Only ONE active pickup per merchant/location at a time.

If a store tries to request a new pickup while a previous one is:
- REQUESTED, ASSIGNED, ACCEPTED, STARTED, or ARRIVED

The request is REJECTED with message:
> "An active pickup already exists for this location (Request #PR-001, Status: accepted). Please wait for the rider to complete this pickup before requesting a new one."

This prevents:
- Multiple riders assigned to same merchant
- Shipments getting lost or mixed up
- Consolidation bugs
- Race conditions

### Valid Consolidation Flow
```
[Hour 1] Store requests pickup #1 with Shipment A
→ Creates pickup PR-001 (REQUESTED)

[Hour 1:10] Admin assigns rider X
→ PR-001 now ASSIGNED

[Hour 1:20] Rider accepts
→ PR-001 now ACCEPTED

[Hour 1:30] Rider starts
→ PR-001 now STARTED

[Hour 1:40] Store tries to request pickup #2 with Shipment B
→ ❌ REJECTED (PR-001 is still STARTED)
→ Store must wait

[Hour 2:00] Rider arrives
→ PR-001 now ARRIVED

[Hour 2:10] Rider collects Shipment A
→ Shipment A: AWAITING_PICKUP → PICKED_UP

[Hour 2:15] Store tries again with Shipment B
→ ❌ REJECTED (PR-001 is still ARRIVED)
→ Store must wait

[Hour 2:30] Rider collects Shipment A
→ All shipments collected

[Hour 2:35] Rider completes
→ PR-001 now COMPLETED ✅

[Hour 2:40] Store requests pickup #2 with Shipment B
→ ✅ ACCEPTED (PR-001 is COMPLETED)
→ Creates new pickup PR-002 (REQUESTED)
```

### Comparison: Old vs New

**OLD (Buggy)**:
- Multiple pickups created for same merchant
- Shipments scattered across pickups
- Riders get confused which pickup to do
- Consolidation creates duplicates

**NEW (Strict)**:
- ONE pickup at a time per merchant/location
- All shipments go to same pickup
- Clear workflow for rider
- No consolidation bugs
- Store gets clear rejection message

## Rider Workflow (STRICT SEQUENCE)

### MANDATORY SEQUENCE
1. **Accept** (ASSIGNED → ACCEPTED)
2. **Start** (ACCEPTED → STARTED)
3. **Arrive** (STARTED → ARRIVED)
4. **Collect** (collect ALL shipments)
5. **Complete** (ARRIVED → COMPLETED)

### 1. Accept Pickup ⚠️ REQUIRED
- **Endpoint**: `POST /staff/pickups/{id}/accept`
- **Permission**: `pickups.accept`
- **Precondition**: Status = ASSIGNED ONLY
- **Result**: Status → ACCEPTED, `accepted_at` timestamp set
- **Error if**: Rider calls /start without /accept first
- **Callback**: `riderAccepted()`

### 2. Start Pickup ⚠️ REQUIRES ACCEPT
- **Endpoint**: `POST /staff/pickups/{id}/start`
- **Permission**: `pickups.start`
- **Precondition**: Status = ACCEPTED ONLY
- **Result**: Status → STARTED, `started_at` timestamp set
- **Error if**: Status is ASSIGNED (must accept first) or REQUESTED
- **Callback**: `riderStarted()`

### 3. Arrive at Merchant
- **Endpoint**: `POST /staff/pickups/{id}/arrive`
- **Permission**: `pickups.status`
- **Precondition**: Status = STARTED
- **Result**: Status → ARRIVED, `arrived_at` timestamp set
- **Callback**: `riderArrived()`

### 4. Collect Shipments (one by one)
- **Endpoint**: `POST /staff/pickups/{id}/shipments/{shipment}/collect`
- **Permission**: `pickups.picked_up`
- **Precondition**: Status = ARRIVED, Shipment status = AWAITING_PICKUP
- **Result**: Shipment status → PICKED_UP
- **Repeat**: Until ALL shipments collected
- **Callback**: `shipmentCollected()`

### 5. Complete Pickup ⚠️ ALL SHIPMENTS REQUIRED
- **Endpoint**: `POST /staff/pickups/{id}/complete`
- **Permission**: `pickups.status`
- **Precondition**: Status = ARRIVED, ALL shipments = PICKED_UP or CANCELLED
- **Result**: Status → COMPLETED, `completed_at` timestamp set
- **Error if**: ANY shipment is still AWAITING_PICKUP (rejected)
- **Callback**: `pickupCompleted()`

## After Pickup Complete: Origin Branch Reception

### 6. Rider Delivers to Origin Branch
- Rider arrives at origin branch with shipments
- Branch staff scan/receive shipments
- Endpoint: `POST /staff/pickups/{id}/shipments/{shipment}/receive`
- Shipment status: PICKED_UP → RECEIVED_AT_ORIGIN

### 7. Branch Sorts Shipments
Origin branch now sorts shipments:
- **Same Branch**: Local delivery to merchant
- **Different Branch**: Transfer to other branch via transfer route

### Shipment Routing After Reception
```
Shipment received at origin branch (RECEIVED_AT_ORIGIN)
→ Check destination city/branch
→ IF same branch: queue for local delivery
→ IF different branch: queue for branch transfer (use transfer routes + lanes)
```

## Permissions

### Rider Role
```
pickups.view             - View assigned pickups
pickups.status          - Accept, start, arrive, complete
pickups.accept          - Accept assignment
pickups.start           - Start pickup
pickups.picked_up       - Collect/mark shipment as picked up
pickups.assignable_staff - View assignable staff
pickups.receive         - Receive at origin (if applicable)
pickups.transfer        - Transfer to another staff member
```

### Pickup Staff Role
```
pickups.view            - View pickups
pickups.status          - Status updates
pickups.accept          - Accept assignment
pickups.start           - Start pickup
pickups.picked_up       - Collect shipments
pickups.assignable_staff - View staff
pickups.receive         - Receive shipments
pickups.transfer        - Transfer pickups
```

## Callbacks

Callbacks are sent via webhooks/events to notify stores of pickup status changes.

### Available Callbacks
- `riderAccepted(pickup)` - When rider accepts
- `riderStarted(pickup)` - When rider starts traveling
- `riderArrived(pickup)` - When rider arrives at merchant
- `shipmentCollected(pickup, shipment)` - When shipment is collected
- `pickupCompleted(pickup)` - When pickup is finished

## Testing Checklist

### Setup
- [ ] 1. Create merchant with active status
- [ ] 2. Create pickup location for merchant
- [ ] 3. Create shipments with status = AWAITING_PICKUP

### Accept Flow
- [ ] 4. Store requests pickup #1 with Shipment A → creates PR-001 (REQUESTED)
- [ ] 5. Admin assigns rider X → PR-001 becomes ASSIGNED
- [ ] 6. Rider calls Accept → PR-001 becomes ACCEPTED ✓

### Multiple Shipments Consolidation
- [ ] 7. Store requests pickup #2 with Shipment B (same location) → attached to PR-001
- [ ] 8. PR-001 still has status = ACCEPTED (not replaced)
- [ ] 9. List pickups shows PR-001 with 2 shipments (A + B)

### Start → Arrive → Collect
- [ ] 10. Rider calls Start → PR-001 becomes STARTED ✓
- [ ] 11. Rider calls Arrive → PR-001 becomes ARRIVED ✓
- [ ] 12. Rider collects Shipment A → status PICKED_UP ✓
- [ ] 13. Rider collects Shipment B → status PICKED_UP ✓

### Completion
- [ ] 14. Rider calls Complete → PR-001 becomes COMPLETED ✓
- [ ] 15. Try to collect shipment after COMPLETED → should fail
- [ ] 16. Check timestamps: accepted_at, started_at, arrived_at, completed_at

### Edge Cases
- [ ] 17. Try to complete with 1 shipment PICKED_UP, 1 AWAITING_PICKUP → should reject
- [ ] 18. Create CANCELLED shipment → can complete without collecting it
- [ ] 19. Rider without pickups.accept → can't call Accept endpoint (403)
- [ ] 20. Rider without pickups.start → can't call Start endpoint (403)

### Consolidation Edge Cases
- [ ] 21. Request with different pickup_location_id → should create new pickup (not consolidate)
- [ ] 22. Request after PR-001 COMPLETED → should create new pickup PR-002
- [ ] 23. Two simultaneous requests → should lock properly, consolidate into one

## Diagnostics

### Check Consolidation Status
```
$service = app(GatewayPickupService::class);
$diagnostics = $service->diagnoseConsolidation(
    merchantId: 1,
    pickupLocationId: 5
);
// Returns:
// - merchant info
// - location info
// - open pickups (requesting shipments)
// - recently closed pickups
// - count of awaiting shipments
```

### Common Issues

**Issue**: New shipment creates separate pickup instead of consolidating
**Debug**:
- Check `pickup_location_id` matches between requests
- Check merchant status = 'active'
- Check shipment status = AWAITING_PICKUP
- Check shipment self_drop = false
- Run diagnostics above

**Issue**: Can't complete pickup (error: "All shipments must be collected")
**Debug**:
- Check all shipments have status = PICKED_UP or CANCELLED
- Run: `$pickup->activeShipments()->get()` to see pending shipments
- Manually collect any missed shipments

**Issue**: Rider can't see Start button
**Debug**:
- Check rider role has `pickups.start` permission
- Verify pickup status = ACCEPTED (not ASSIGNED or STARTED)
- Check pickup is assigned to this rider

## Migration Notes

If migrating from old pickup system:
1. Update all permissions in roles to include: accept, start
2. Reseed roles: `php artisan db:seed --class=Database\\Seeders\\System\\RoleSeeder`
3. Test workflow with test rider account
4. Monitor webhook callbacks for store integration issues
