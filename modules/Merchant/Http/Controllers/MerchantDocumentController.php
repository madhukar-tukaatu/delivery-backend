<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Merchant\Models\MerchantDocument;

class MerchantDocumentController extends Controller
{
    public function preview(MerchantDocument $document)
    {
        $this->authorizeDocumentAccess($document);

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

    public function download(MerchantDocument $document)
    {
        $this->authorizeDocumentAccess($document);

        $disk = $document->disk ?: 'local';

        if (!$document->file_path || !Storage::disk($disk)->exists($document->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk($disk)->download(
            $document->file_path,
            $document->original_name ?: basename($document->file_path)
        );
    }

    private function authorizeDocumentAccess(MerchantDocument $document): void
    {
        $user = request()->user();

        $isOwnerMerchant = $user->hasRole('merchant')
            && $user->merchant
            && (int) $user->merchant->id === (int) $document->merchant_id;

        $isAdmin = $user->hasRole('super_admin')
            || $user->hasRole('main_admin')
            || $user->can('merchant_applications.view')
            || $user->can('merchants.view');

        if (!$isOwnerMerchant && !$isAdmin) {
            abort(403, 'You are not allowed to view this document.');
        }
    }
}