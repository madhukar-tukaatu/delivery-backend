<?php

namespace Database\Seeders\Performance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('invoices')->where('invoice_number','like','PERF-INV-%')->exists()) return;
        $rows = $items = $receipts = [];
        DB::table('merchant_settlements')->where('settlement_number','like','PERF-SET-%')->orderBy('id')->chunk(500, function ($settlements) use (&$rows, &$items, &$receipts) {
            foreach ($settlements as $s) {
                $invoice = 'PERF-INV-'.$s->id;
                $rows[] = ['merchant_id'=>$s->merchant_id,'shipment_id'=>null,'invoice_number'=>$invoice,'type'=>'settlement','invoice_date'=>now()->toDateString(),'subtotal'=>$s->total_delivery_charges + $s->total_pod_charges,'tax_amount'=>0,'total_amount'=>$s->total_delivery_charges + $s->total_pod_charges,'status'=>$s->status === 'settled' ? 'paid' : 'unpaid','created_at'=>now(),'updated_at'=>now()];
            }
        });
        if ($rows) DB::table('invoices')->insert($rows);
        $invoiceIds = DB::table('invoices')->where('invoice_number','like','PERF-INV-%')->get();
        foreach ($invoiceIds as $inv) {
            $items[] = ['invoice_id'=>$inv->id,'description'=>'Courier delivery and POD service charges','quantity'=>1,'unit_price'=>$inv->total_amount,'total'=>$inv->total_amount,'created_at'=>now(),'updated_at'=>now()];
            if ($inv->status === 'paid') $receipts[] = ['invoice_id'=>$inv->id,'receipt_number'=>'PERF-RCP-'.$inv->id,'amount'=>$inv->total_amount,'payment_method'=>'bank_transfer','reference_number'=>'RCPT'.mt_rand(100000,999999),'paid_at'=>now()->subDays(mt_rand(0,5)),'created_at'=>now(),'updated_at'=>now()];
        }
        if ($items) DB::table('invoice_items')->insert($items);
        if ($receipts) DB::table('payment_receipts')->insert($receipts);
    }
}
