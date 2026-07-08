<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchDocument;

class BranchDocumentController extends Controller
{
    public function store(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', 'max:80'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx', 'max:10240'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('file');
        $disk = 'local';
        $safeType = Str::slug($data['document_type'], '_');
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = $safeType . '_' . now()->format('YmdHis') . '_' . Str::random(8) . '.' . $extension;
        $folder = "branch-documents/{$branch->id}";
        $path = $file->storeAs($folder, $filename, $disk);

        $existing = BranchDocument::where('branch_id', $branch->id)
            ->where('document_type', $data['document_type'])
            ->first();

        if ($existing && $existing->file_path) {
            Storage::disk($existing->disk ?: 'local')->delete($existing->file_path);
        }

        $document = BranchDocument::updateOrCreate(
            [
                'branch_id' => $branch->id,
                'document_type' => $data['document_type'],
            ],
            [
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'disk' => $disk,
                'status' => 'pending',
                'remarks' => $data['remarks'] ?? null,
                'verified_by' => null,
                'verified_at' => null,
            ]
        );

        return response()->json([
            'message' => 'Branch document uploaded successfully.',
            'data' => $document,
        ], 201);
    }

    public function preview(BranchDocument $document)
    {
        $this->authorizeDocumentAccess();
        return $this->inlineFileResponse($document);
    }

    public function download(BranchDocument $document)
    {
        $this->authorizeDocumentAccess();

        $disk = $document->disk ?: 'local';

        if (!$document->file_path || !Storage::disk($disk)->exists($document->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk($disk)->download(
            $document->file_path,
            $document->original_name ?: basename($document->file_path)
        );
    }

    public function verify(Request $request, BranchDocument $document): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,verified,rejected'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $document->update([
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? $document->remarks,
            'verified_by' => $data['status'] === 'verified' ? request()->user()?->id : null,
            'verified_at' => $data['status'] === 'verified' ? now() : null,
        ]);

        return response()->json([
            'message' => 'Branch document status updated.',
            'data' => $document->fresh(),
        ]);
    }

    public function destroy(BranchDocument $document): JsonResponse
    {
        if ($document->file_path) {
            Storage::disk($document->disk ?: 'local')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['message' => 'Branch document deleted successfully.']);
    }

    private function inlineFileResponse(BranchDocument $document)
    {
        $disk = $document->disk ?: 'local';

        if (!$document->file_path || !Storage::disk($disk)->exists($document->file_path)) {
            abort(404, 'File not found.');
        }

        $mimeType = $document->mime_type
            ?: Storage::disk($disk)->mimeType($document->file_path)
            ?: 'application/octet-stream';

        $fileName = $document->original_name ?: basename($document->file_path);

        return response(Storage::disk($disk)->get($document->file_path), 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . addslashes($fileName) . '"')
            ->header('Cache-Control', 'private, max-age=300');
    }

    private function authorizeDocumentAccess(): void
    {
        $user = request()->user();

        $allowed = $user?->hasRole('super_admin')
            || $user?->hasRole('main_admin')
            || $user?->can('branches.view')
            || $user?->can('branch_documents.view');

        if (!$allowed) {
            abort(403, 'You are not allowed to view branch documents.');
        }
    }
}
