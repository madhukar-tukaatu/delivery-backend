<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Shipment\Models\Shipment;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with('items')->latest();
        if ($request->user()->role === 'merchant') $query->where('merchant_id', $request->user()->merchant_id);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function shipmentInvoice(Request $request, Shipment $shipment)
    {
        $invoice = Invoice::create([
            'merchant_id' => $shipment->merchant_id,
            'shipment_id' => $shipment->id,
            'invoice_number' => 'INV-'.now()->format('YmdHis').'-'.random_int(100, 999),
            'type' => 'shipment',
            'invoice_date' => now()->toDateString(),
            'subtotal' => $shipment->delivery_charge + $shipment->cod_charge,
            'tax_amount' => 0,
            'total_amount' => $shipment->delivery_charge + $shipment->cod_charge,
            'status' => 'unpaid',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Delivery charge for '.$shipment->tracking_number,
            'quantity' => 1,
            'unit_price' => $shipment->delivery_charge,
            'total' => $shipment->delivery_charge,
        ]);
        if ($shipment->cod_charge > 0) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'COD charge for '.$shipment->tracking_number,
                'quantity' => 1,
                'unit_price' => $shipment->cod_charge,
                'total' => $shipment->cod_charge,
            ]);
        }
        return ApiResponse::success($invoice->load('items'), 'Invoice generated.', 201);
    }
}
