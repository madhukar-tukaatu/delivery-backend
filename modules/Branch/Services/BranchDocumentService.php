<?php

namespace Modules\Branch\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchDocument;

class BranchDocumentService
{
    public function storeOrReplace(
        Branch $branch,
        UploadedFile $file,
        string $documentType,
        ?string $remarks = null
    ): BranchDocument {
        $documentType = trim($documentType);

        if ($documentType === '') {
            throw ValidationException::withMessages([
                'document_type' => [
                    'The document type is required.',
                ],
            ]);
        }

        $disk = 'local';
        $path = $this->storeFile(
            branch: $branch,
            file: $file,
            documentType: $documentType,
            disk: $disk,
        );

        $existing = BranchDocument::query()
            ->where('branch_id', $branch->id)
            ->where('document_type', $documentType)
            ->first();

        $oldDisk = $existing?->disk ?: 'local';
        $oldPath = $existing?->file_path;

        try {
            $document = BranchDocument::updateOrCreate(
                [
                    'branch_id' => $branch->id,
                    'document_type' => $documentType,
                ],
                [
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'disk' => $disk,
                    'status' => 'pending',
                    'remarks' => $remarks,
                    'verified_by' => null,
                    'verified_at' => null,
                ]
            );
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        if (
            $oldPath &&
            $oldPath !== $path
        ) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $document->fresh();
    }

    public function update(
        BranchDocument $document,
        string $documentType,
        ?string $remarks = null,
        ?UploadedFile $file = null
    ): BranchDocument {
        $documentType = trim($documentType);

        if ($documentType === '') {
            throw ValidationException::withMessages([
                'document_type' => [
                    'The document type is required.',
                ],
            ]);
        }

        $duplicateExists = BranchDocument::query()
            ->where('branch_id', $document->branch_id)
            ->where('document_type', $documentType)
            ->where('id', '!=', $document->id)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'document_type' => [
                    'This branch already has a document of the selected type.',
                ],
            ]);
        }

        $oldDisk = $document->disk ?: 'local';
        $oldPath = $document->file_path;
        $newDisk = null;
        $newPath = null;
        $documentTypeChanged = $document->document_type !== $documentType;

        if ($file) {
            $newDisk = 'local';
            $branch = Branch::query()->findOrFail(
                $document->branch_id
            );

            $newPath = $this->storeFile(
                branch: $branch,
                file: $file,
                documentType: $documentType,
                disk: $newDisk,
            );
        }

        try {
            $document->document_type = $documentType;
            $document->remarks = $remarks;

            if ($file && $newPath) {
                $document->file_path = $newPath;
                $document->original_name = $file->getClientOriginalName();
                $document->mime_type = $file->getMimeType();
                $document->size_bytes = $file->getSize();
                $document->disk = $newDisk;
            }

            if ($newPath || $documentTypeChanged) {
                $document->status = 'pending';
                $document->verified_by = null;
                $document->verified_at = null;
            }

            $document->save();
        } catch (\Throwable $exception) {
            if ($newPath && $newDisk) {
                Storage::disk($newDisk)->delete($newPath);
            }

            throw $exception;
        }

        if (
            $newPath &&
            $oldPath &&
            $oldPath !== $newPath
        ) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return $document->fresh();
    }

    public function delete(BranchDocument $document): void
    {
        $disk = $document->disk ?: 'local';
        $path = $document->file_path;

        $document->delete();

        if ($path) {
            Storage::disk($disk)->delete($path);
        }
    }

    private function storeFile(
        Branch $branch,
        UploadedFile $file,
        string $documentType,
        string $disk
    ): string {
        $safeType = Str::slug($documentType, '_');
        $extension = strtolower($file->getClientOriginalExtension());

        $filename = $safeType
            . '_'
            . now()->format('YmdHis')
            . '_'
            . Str::random(8)
            . ($extension !== '' ? ".{$extension}" : '');

        $folder = "branch-documents/{$branch->id}";

        return $file->storeAs(
            $folder,
            $filename,
            $disk
        );
    }
}
