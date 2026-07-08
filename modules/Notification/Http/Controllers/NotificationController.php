<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\EmailLog;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Models\SmsLog;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = NotificationLog::query()->latest();
        if ($request->filled('channel')) $query->where('channel', $request->channel);
        if ($request->filled('status')) $query->where('status', $request->status);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'channel' => ['required', 'in:in_app,sms,email,whatsapp'],
            'event' => ['nullable', 'string'],
            'recipient' => ['nullable', 'string'],
            'subject' => ['nullable', 'string'],
            'message' => ['required', 'string'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'shipment_id' => ['nullable', 'exists:shipments,id'],
        ]);
        $log = NotificationLog::create($data + ['status' => 'pending']);
        return ApiResponse::success($log, 'Notification queued.', 201);
    }

    public function markSent(NotificationLog $notification)
    {
        $notification->update(['status' => 'sent', 'sent_at' => now()]);
        return ApiResponse::success($notification, 'Notification marked as sent.');
    }

    public function sms(Request $request)
    {
        return ApiResponse::success(SmsLog::query()->latest()->paginate((int) $request->get('per_page', 20)));
    }

    public function emails(Request $request)
    {
        return ApiResponse::success(EmailLog::query()->latest()->paginate((int) $request->get('per_page', 20)));
    }
}
