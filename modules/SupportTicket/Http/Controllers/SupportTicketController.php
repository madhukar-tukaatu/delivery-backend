<?php

namespace Modules\SupportTicket\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\SupportTicket\Models\SupportTicket;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $query = SupportTicket::query()->latest();
        if ($request->filled('_scope_merchant_id')) $query->where('merchant_id', $request->get('_scope_merchant_id'));
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('priority')) $query->where('priority', $request->priority);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'subject' => ['required', 'string'],
            'message' => ['required', 'string'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);
        $data['user_id'] = $request->user()?->id;
        $data['merchant_id'] = $request->get('_scope_merchant_id', $data['merchant_id'] ?? $request->user()?->merchant_id);
        return ApiResponse::success(SupportTicket::create($data), 'Support ticket created.', 201);
    }

    public function show(SupportTicket $ticket)
    {
        return ApiResponse::success($ticket);
    }

    public function update(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'subject' => ['sometimes', 'string'],
            'message' => ['sometimes', 'string'],
            'status' => ['nullable', 'in:open,pending,resolved,closed'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
        ]);
        $ticket->update($data);
        return ApiResponse::success($ticket->fresh(), 'Support ticket updated.');
    }

    public function destroy(SupportTicket $ticket)
    {
        $ticket->delete();
        return ApiResponse::success(null, 'Support ticket deleted.');
    }
}
