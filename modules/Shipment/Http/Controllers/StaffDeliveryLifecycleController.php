<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use Modules\Delivery\Http\Controllers\StaffDeliveryController;

/**
 * Compatibility alias for the old Shipment delivery route set.
 *
 * Delivery ownership now belongs to the Delivery module. Keeping this alias
 * prevents an older route registration from reintroducing the legacy flow
 * that accepted unverified payment methods and had no arrival/proof step.
 */
final class StaffDeliveryLifecycleController extends StaffDeliveryController
{
}
