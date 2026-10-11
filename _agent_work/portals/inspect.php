<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
foreach (['invoices','marketplace_bills','marketplace_bill_payments','users','marketplaces','payment_gateway_accounts','notification_logs'] as $t) {
  echo $t.': '.implode(',', Schema::getColumnListing($t))."\n";
}
echo "roles: ".DB::table('roles')->pluck('name')->implode(',')."\n";
$mr = DB::table('roles')->where('name','merchant')->first();
echo "merchant perms: ".DB::table('role_has_permissions')->join('permissions','permissions.id','=','permission_id')->where('role_id',$mr->id)->pluck('name')->implode(',')."\n";
echo "marketplaces: ".json_encode(DB::table('marketplaces')->select('id','name','code','email','is_active')->get())."\n";
echo "merchant 50: ".json_encode(DB::table('merchants')->where('id',50)->select('id','name','marketplace_id','default_branch_id','email')->first())."\n";
echo "merchant users 50: ".json_encode(DB::table('users')->where('merchant_id',50)->select('id','email','role','is_active')->get())."\n";
echo "other merchant users: ".json_encode(DB::table('users')->whereNotNull('merchant_id')->where('merchant_id','<>',50)->select('id','merchant_id','role')->limit(3)->get())."\n";
echo "invoice status counts: ".json_encode(DB::table('invoices')->selectRaw('payer_type,status,count(*) c')->groupBy('payer_type','status')->get())."\n";
echo "mp bills: ".json_encode(DB::table('marketplace_bills')->select('id','bill_number','branch_id','marketplace_id','status','total_amount')->get())."\n";
echo "branches: ".json_encode(DB::table('branches')->select('id','name','code','email')->limit(40)->get())."\n";
echo "gateway accts: ".json_encode(DB::table('payment_gateway_accounts')->get()->map(fn($r)=>collect((array)$r)->except(['credentials'])))."\n";
echo "menu merchant invoices: ".json_encode(DB::table('menu_items')->where('section','merchant')->where('path','/merchant/invoices')->get())."\n";
echo "mail: ".config('mail.default').' '.config('mail.mailers.smtp.host')."; queue: ".config('queue.default')."\n";
echo "invoice sample: ".json_encode(DB::table('invoices')->where('payer_type','merchant')->latest('id')->first())."\n";
echo "app key ok: "; try { echo strlen(Illuminate\Support\Facades\Crypt::encryptString('x')) ? 'yes' : 'no'; } catch (\Throwable $e) { echo 'NO '.$e->getMessage(); } echo "\n";