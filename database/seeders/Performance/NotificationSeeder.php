<?php

namespace Database\Seeders\Performance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('notification_logs')->where('event','performance.seed')->exists()) return;
        $notifications = $sms = $webhooks = $apiLogs = [];
        DB::table('shipments')->where('source','performance_seed')->select('id','merchant_id','tracking_number','receiver_phone','receiver_email','status')->limit(5000)->orderBy('id')->chunk(1000, function ($shipments) use (&$notifications, &$sms, &$webhooks, &$apiLogs) {
            foreach ($shipments as $s) {
                $notifications[] = ['merchant_id'=>$s->merchant_id,'shipment_id'=>$s->id,'channel'=>'in_app','event'=>'performance.seed','recipient'=>$s->receiver_phone,'subject'=>'Shipment '.$s->tracking_number,'message'=>'Your shipment status is '.$s->status,'payload'=>json_encode(['tracking_number'=>$s->tracking_number,'status'=>$s->status]),'status'=>'sent','sent_at'=>now(),'created_at'=>now(),'updated_at'=>now()];
                $sms[] = ['shipment_id'=>$s->id,'phone'=>$s->receiver_phone,'message'=>'Courier update: '.$s->tracking_number.' is '.$s->status,'provider'=>'demo','provider_reference'=>'SMS'.mt_rand(100000,999999),'status'=>'sent','sent_at'=>now(),'created_at'=>now(),'updated_at'=>now()];
                $webhooks[] = ['merchant_id'=>$s->merchant_id,'shipment_id'=>$s->id,'event'=>'shipment.status_changed','webhook_url'=>'https://webhook.site/perf','payload'=>json_encode(['tracking_number'=>$s->tracking_number,'status'=>$s->status]),'signature'=>hash('sha256',$s->tracking_number),'response_status_code'=>200,'response_body'=>'OK','attempt_count'=>1,'last_attempt_at'=>now(),'next_retry_at'=>null,'status'=>'success','created_at'=>now(),'updated_at'=>now()];
                $apiLogs[] = ['merchant_id'=>$s->merchant_id,'merchant_api_key_id'=>null,'endpoint'=>'/api/v1/gateway/shipments','method'=>'POST','request_payload'=>json_encode(['tracking_number'=>$s->tracking_number]),'response_payload'=>json_encode(['status'=>$s->status]),'status_code'=>201,'ip_address'=>'127.0.0.1','error_message'=>null,'created_at'=>now(),'updated_at'=>now()];
            }
            if (count($notifications) >= 1000) { DB::table('notification_logs')->insert($notifications); $notifications = []; }
            if (count($sms) >= 1000) { DB::table('sms_logs')->insert($sms); $sms = []; }
            if (count($webhooks) >= 1000) { DB::table('webhook_delivery_logs')->insert($webhooks); $webhooks = []; }
            if (count($apiLogs) >= 1000) { DB::table('api_logs')->insert($apiLogs); $apiLogs = []; }
        });
        if ($notifications) DB::table('notification_logs')->insert($notifications);
        if ($sms) DB::table('sms_logs')->insert($sms);
        if ($webhooks) DB::table('webhook_delivery_logs')->insert($webhooks);
        if ($apiLogs) DB::table('api_logs')->insert($apiLogs);
    }
}
