<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchDocument;
use Modules\Branch\Services\BranchDocumentService;

class BranchDocumentController extends Controller
{
    public function store(
        Request $request,
        Branch $branch,
        BranchDocumentService $documentService
    ): JsonResponse {
        $data = $request->validate([
            'document_type' => [
                'required',
                'string',
                'max:80',
            ],
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx',
                'max:10240',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $document = $documentService->storeOrReplace(
            branch: $branch,
            file: $request->file('file'),
            documentType: $data['document_type'],
            remarks: $data['remarks'] ?? null,
        );

        return response()->json([
            'message' => 'Branch document uploaded successfully.',
            'data' => $document,
        ], 201);
    }

    public function update(
        Request $request,
        BranchDocument $document,
        BranchDocumentService $documentService
    ): JsonResponse {
        $data = $request->validate([
            'document_type' => [
                'required',
                'string',
                'max:80',
            ],
            'file' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx',
                'max:10240',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $updatedDocument = $documentService->update(
            document: $document,
            documentType: $data['document_type'],
            remarks: $data['remarks'] ?? null,
            file: $request->file('file'),
        );

        return response()->json([
            'message' => 'Branch document updated successfully.',
            'data' => $updatedDocument,
        ]);
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

        if (
            !$document->file_path ||
            !Storage::disk($disk)->exists($document->file_path)
        ) {
            abort(404, 'File not found.');
        }

        return Storage::disk($disk)->download(
            $document->file_path,
            $document->original_name ?: basename($document->file_path)
        );
    }

    public function verify(
        Request $request,
        BranchDocument $document
    ): JsonResponse {
        $data = $request->validate([
            'status' => [
                'required',
                'string',
                'in:pending,verified,rejected',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $document->update([
            'status' => $data['status'],
            'remarks' => array_key_exists('remarks', $data)
                ? $data['remarks']
                : $document->remarks,
            'verified_by' => $data['status'] === 'verified'
                ? $request->user()?->id
                : null,
            'verified_at' => $data['status'] === 'verified'
                ? now()
                : null,
        ]);

        return response()->json([
            'message' => 'Branch document status updated.',
            'data' => $document->fresh(),
        ]);
    }

    public function destroy(
        BranchDocument $document,
        BranchDocumentService $documentService
    ): JsonResponse {
        $documentService->delete($document);

        return response()->json([
            'message' => 'Branch document deleted successfully.',
        ]);
    }

    private function inlineFileResponse(BranchDocument $document)
    {
        $disk = $document->disk ?: 'local';

        if (
            !$document->file_path ||
            !Storage::disk($disk)->exists($document->file_path)
        ) {
            abort(404, 'File not found.');
        }

        $mimeType = $document->mime_type
            ?: Storage::disk($disk)->mimeType($document->file_path)
            ?: 'application/octet-stream';

        $fileName = $document->original_name
            ?: basename($document->file_path);

        return response(
            Storage::disk($disk)->get($document->file_path),
            200
        )
            ->header('Content-Type', $mimeType)
            ->header(
                'Content-Disposition',
                'inline; filename="' . addslashes($fileName) . '"'
            )
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
