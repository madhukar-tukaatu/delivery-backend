<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchAgreement;

class BranchAgreementController extends Controller
{
    public function store(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'agreement_type' => ['required', 'string', 'max:80'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:20480'],
            'status' => ['nullable', 'string', 'in:pending,signed,expired,cancelled'],
            'signed_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('file');
        $disk = 'local';
        $safeType = Str::slug($data['agreement_type'], '_');
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = $safeType . '_' . now()->format('YmdHis') . '_' . Str::random(8) . '.' . $extension;
        $folder = "branch-agreements/{$branch->id}";
        $path = $file->storeAs($folder, $filename, $disk);

        $existing = BranchAgreement::where('branch_id', $branch->id)
            ->where('agreement_type', $data['agreement_type'])
            ->first();

        if ($existing && $existing->file_path) {
            Storage::disk($existing->disk ?: 'local')->delete($existing->file_path);
        }

        $agreement = BranchAgreement::updateOrCreate(
            [
                'branch_id' => $branch->id,
                'agreement_type' => $data['agreement_type'],
            ],
            [
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'disk' => $disk,
                'status' => $data['status'] ?? 'pending',
                'signed_at' => $data['signed_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'uploaded_by' => request()->user()?->id,
            ]
        );

        return response()->json([
            'message' => 'Branch agreement uploaded successfully.',
            'data' => $agreement,
        ], 201);
    }

    public function preview(BranchAgreement $agreement)
    {
        $this->authorizeAgreementAccess();
        return $this->inlineFileResponse($agreement);
    }

    public function download(BranchAgreement $agreement)
    {
        $this->authorizeAgreementAccess();

        $disk = $agreement->disk ?: 'local';

        if (!$agreement->file_path || !Storage::disk($disk)->exists($agreement->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk($disk)->download(
            $agreement->file_path,
            $agreement->original_name ?: basename($agreement->file_path)
        );
    }

    public function destroy(BranchAgreement $agreement): JsonResponse
    {
        if ($agreement->file_path) {
            Storage::disk($agreement->disk ?: 'local')->delete($agreement->file_path);
        }

        $agreement->delete();

        return response()->json(['message' => 'Branch agreement deleted successfully.']);
    }

    private function inlineFileResponse(BranchAgreement $agreement)
    {
        $disk = $agreement->disk ?: 'local';

        if (!$agreement->file_path || !Storage::disk($disk)->exists($agreement->file_path)) {
            abort(404, 'File not found.');
        }

        $mimeType = $agreement->mime_type
            ?: Storage::disk($disk)->mimeType($agreement->file_path)
            ?: 'application/octet-stream';

        $fileName = $agreement->original_name ?: basename($agreement->file_path);

        return response(Storage::disk($disk)->get($agreement->file_path), 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . addslashes($fileName) . '"')
            ->header('Cache-Control', 'private, max-age=300');
    }

    private function authorizeAgreementAccess(): void
    {
        $user = request()->user();

        $allowed = $user?->hasRole('super_admin')
            || $user?->hasRole('main_admin')
            || $user?->can('branches.view')
            || $user?->can('branch_agreements.view');

        if (!$allowed) {
            abort(403, 'You are not allowed to view branch agreements.');
        }
    }
}
