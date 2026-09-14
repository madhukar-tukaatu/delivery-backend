<?php

namespace Modules\Shipment\Http\Controllers;

use Modules\Delivery\Http\Controllers\StaffDeliveryController;

/**
 * Compatibility alias for the old Shipment operations route set.
 *
 * Delivery lifecycle behavior is implemented only by the Delivery module so
 * every caller gets arrival gating, prepaid proof, and POD verification.
 */
class StaffDeliveryOperationsController extends StaffDeliveryController
{
}
