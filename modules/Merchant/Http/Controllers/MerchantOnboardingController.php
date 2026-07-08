<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Merchant\Models\MerchantDocument;
use Modules\Merchant\Services\MerchantOnboardingService;
use Illuminate\Support\Str;

class MerchantOnboardingController extends Controller
{
    public function show(Request $request)
    {
        $merchant = $request->user()->merchant;

        return ApiResponse::success($merchant->load([
            'documents',
            'pickupLocations',
            'defaultBranch',
            'defaultSubBranch',
        ]));
    }

    public function businessProfile(Request $request, MerchantOnboardingService $service)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:180'],
            'business_type' => ['required', 'string', 'max:100'],
            'pan_vat_number' => ['nullable', 'string', 'max:80'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
        ]);

        return ApiResponse::success(
            $service->updateBusinessProfile($request->user()->merchant, $data),
            'Business profile saved.'
        );
    }

    public function pickupLocation(Request $request, MerchantOnboardingService $service)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return ApiResponse::success(
            $service->updatePickupLocation($request->user()->merchant, $data)->load(['branch', 'subBranch']),
            'Pickup location saved.'
        );
    }


    // public function pickupLocation(\Illuminate\Http\Request $request)
    // {
    //     $merchant = $this->currentMerchant($request);

    //     $data = $request->validate([
    //         'name' => ['nullable', 'string', 'max:190'],
    //         'contact_person' => ['required', 'string', 'max:190'],
    //         'phone' => ['required', 'string', 'max:30'],
    //         'address' => ['required', 'string', 'max:1000'],
    //         'city' => ['required', 'string', 'max:100'],
    //         'area' => ['required', 'string', 'max:100'],
    //         'latitude' => ['nullable', 'numeric', 'between:-90,90'],
    //         'longitude' => ['nullable', 'numeric', 'between:-180,180'],
    //         'is_default' => ['nullable', 'boolean'],
    //     ]);

    //     \Modules\Merchant\Models\MerchantPickupLocation::where('merchant_id', $merchant->id)
    //         ->update(['is_default' => false]);

    //     $pickupLocation = \Modules\Merchant\Models\MerchantPickupLocation::updateOrCreate(
    //         [
    //             'merchant_id' => $merchant->id,
    //             'is_default' => true,
    //         ],
    //         [
    //             'name' => $data['name'] ?? 'Default Pickup Location',
    //             'contact_person' => $data['contact_person'],
    //             'phone' => $data['phone'],
    //             'address' => $data['address'],
    //             'city' => $data['city'],
    //             'area' => $data['area'],
    //             'latitude' => $data['latitude'] ?? null,
    //             'longitude' => $data['longitude'] ?? null,
    //             'status' => 'active',
    //             'is_default' => true,
    //         ]
    //     );

    //     foreach (
    //         [
    //             'pickup_address' => $pickupLocation->address,
    //             'pickup_city' => $pickupLocation->city,
    //             'pickup_area' => $pickupLocation->area,
    //             'pickup_lat' => $pickupLocation->latitude,
    //             'pickup_lng' => $pickupLocation->longitude,
    //             'contact_person' => $pickupLocation->contact_person,
    //             'phone' => $pickupLocation->phone,
    //         ] as $key => $value
    //     ) {
    //         if (\Illuminate\Support\Facades\Schema::hasColumn($merchant->getTable(), $key)) {
    //             $merchant->{$key} = $value;
    //         }
    //     }

    //     $merchant->save();

    //     return response()->json([
    //         'message' => 'Default pickup location saved.',
    //         'data' => $pickupLocation,
    //     ]);
    // }

    public function pickupLocations(\Illuminate\Http\Request $request)
    {
        $merchant = $this->currentMerchant($request);

        $rows = \Modules\Merchant\Models\MerchantPickupLocation::query()
            ->where('merchant_id', $merchant->id)
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['active', 'approved', 'pending']);
            })
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows]);
    }
    public function bankDetails(Request $request, MerchantOnboardingService $service)
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:150'],
            'bank_account_name' => ['required', 'string', 'max:150'],
            'bank_account_number' => ['required', 'string', 'max:80'],
            'bank_branch' => ['nullable', 'string', 'max:150'],
        ]);

        return ApiResponse::success(
            $service->updateBankDetails($request->user()->merchant, $data),
            'Bank details saved.'
        );
    }

    // public function uploadDocument(Request $request, MerchantOnboardingService $service)
    // {
    //     $data = $request->validate([
    //         'document_type' => ['required', 'string', 'in:business_registration,pan_vat,owner_id,bank_proof,other'],
    //         'file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,pdf,webp'],
    //     ]);

    //     return ApiResponse::success(
    //         $service->uploadDocument($request->user()->merchant, $data['document_type'], $data['file']),
    //         'Document uploaded.'
    //     );
    // }

    public function uploadDocument(Request $request)
    {
        $user = $request->user();
        $merchant = $user->merchant;

        if (!$merchant) {
            return response()->json([
                'message' => 'Merchant profile not found.',
            ], 422);
        }

        if ($merchant->status === 'active') {
            return response()->json([
                'message' => 'Approved merchant documents cannot be changed from onboarding.',
            ], 422);
        }

        $data = $request->validate([
            'document_type' => [
                'required',
                'string',
                'in:business_registration,pan_vat,owner_id,bank_proof,other',
            ],
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $file = $request->file('file');

        $disk = 'local'; // private storage
        $folder = "merchant-documents/{$merchant->id}";

        $extension = $file->getClientOriginalExtension();
        $safeType = Str::slug($data['document_type'], '_');
        $filename = $safeType . '_' . now()->format('YmdHis') . '_' . Str::random(8) . '.' . $extension;

        $path = $file->storeAs($folder, $filename, $disk);

        $existing = MerchantDocument::where('merchant_id', $merchant->id)
            ->where('document_type', $data['document_type'])
            ->first();

        if ($existing && $existing->file_path) {
            Storage::disk($existing->disk ?: 'local')->delete($existing->file_path);
        }

        $document = MerchantDocument::updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'document_type' => $data['document_type'],
            ],
            [
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'disk' => $disk,
                'status' => 'pending',
                'remarks' => null,
                'verified_by' => null,
                'verified_at' => null,
            ]
        );

        $merchant->update([
            'verification_status' => 'documents_pending',
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'data' => $document->fresh(),
        ]);
    }

    public function submit(Request $request, MerchantOnboardingService $service)
    {
        return ApiResponse::success(
            $service->submitForReview($request->user()->merchant),
            'Submitted for verification.'
        );
    }
}
