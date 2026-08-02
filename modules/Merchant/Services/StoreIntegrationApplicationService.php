<?php

namespace Modules\Merchant\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantDocument;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Routing\Services\BranchLocatorService;

class StoreIntegrationApplicationService
{
    private const REQUIRED_DOCUMENTS = [
        'business_registration',
        'pan_vat',
        'owner_id',
        'bank_proof',
    ];

    public function __construct(private BranchLocatorService $branchLocator)
    {
    }

    public function submit(
        string $applicationNumber,
        array $data,
        array $documents
    ): array {
        return DB::transaction(function () use (
            $applicationNumber,
            $data,
            $documents
        ) {
            $merchant = Merchant::query()
                ->where('application_number', $applicationNumber)
                ->lockForUpdate()
                ->first();

            $created = !$merchant;

            if (
                $merchant &&
                in_array($merchant->integration_status, ['approved', 'suspended'], true)
            ) {
                throw ValidationException::withMessages([
                    'application_number' => [
                        'This Store Manager application has already been approved.',
                    ],
                ]);
            }

            $this->ensureNoDuplicateMerchant(
                $data,
                $merchant?->id,
                $applicationNumber
            );

            $this->ensureRequiredDocumentsExist($merchant, $documents);

            $pickup = $data['pickup_location'];
            $suggested = $this->branchLocator->nearestBranchSet(
                (float) $pickup['latitude'],
                (float) $pickup['longitude']
            );

            if (!$merchant) {
                $merchant = new Merchant();
                $merchant->application_number = $applicationNumber;
                $merchant->code = $this->makeCode($data['store']['name']);
            }

            $merchant->forceFill([
                'application_source' => Merchant::SOURCE_STORE_MANAGER,
                'external_store_id' => $data['store']['external_store_id'],
                'external_platform' => $data['store']['platform'],
                'store_category' => $data['store']['category'] ?? null,
                'store_url' => $data['store']['url'] ?? null,

                'name' => $data['store']['name'],
                'owner_name' => $data['business']['owner_name'],
                'contact_person' =>
                    $data['business']['contact_person']
                    ?? $data['business']['owner_name'],
                'phone' => $data['business']['phone'],
                'email' => strtolower($data['business']['email']),
                'website_url' => $data['store']['url'] ?? null,
                'business_type' => $data['business']['type'] ?? null,
                'pan_vat_number' => $data['business']['pan_vat_number'],
                'registration_number' => $data['business']['registration_number'],
                'address' => $data['business']['registered_address'],

                'pickup_address' => $pickup['address'],
                'pickup_city' => $pickup['city'],
                'pickup_area' => $pickup['area'],
                'pickup_lat' => $pickup['latitude'],
                'pickup_lng' => $pickup['longitude'],
                'suggested_branch_id' => $suggested['branch']->id ?? null,
                'suggested_sub_branch_id' => $suggested['sub_branch']->id ?? null,

                'requested_services' => array_values(
                    array_unique($data['requested_services'])
                ),
                'approved_services' => null,
                'integration_payload' => $data,
                'integration_callback_url' => $data['callback']['url'],
                'integration_callback_secret' => $data['callback']['secret'],
                'integration_status' => 'pending_review',
                'integration_callback_status' => null,
                'submitted_at' => now(),

                // Reuse existing public workflow statuses so the existing
                // admin application page and approval service keep working.
                'status' => 'pending_verification',
                'verification_status' => 'submitted',
                'more_info_message' => null,
                'rejected_reason' => null,
            ])->save();

            $this->savePickupLocation($merchant, $pickup, $suggested);
            $this->saveDocuments($merchant, $documents);

            return [
                'merchant' => $merchant->fresh([
                    'documents',
                    'pickupLocations',
                    'suggestedBranch',
                    'suggestedSubBranch',
                ]),
                'created' => $created,
            ];
        }, 3);
    }

    private function ensureNoDuplicateMerchant(
        array $data,
        ?int $currentMerchantId,
        string $applicationNumber
    ): void {
        $business = $data['business'];
        $store = $data['store'];

        $duplicate = Merchant::query()
            ->when(
                $currentMerchantId,
                fn ($query) => $query->where('id', '!=', $currentMerchantId)
            )
            ->where(function ($query) use ($business, $store, $applicationNumber) {
                $query
                    ->where('application_number', $applicationNumber)
                    ->orWhere(function ($query) use ($store) {
                        $query
                            ->where('application_source', Merchant::SOURCE_STORE_MANAGER)
                            ->where('external_store_id', $store['external_store_id'])
                            ->where('external_platform', $store['platform']);
                    })
                    ->orWhere('pan_vat_number', $business['pan_vat_number'])
                    ->orWhere('registration_number', $business['registration_number'])
                    ->orWhere('email', strtolower($business['email']));
            })
            ->first();

        if (!$duplicate) {
            return;
        }

        throw ValidationException::withMessages([
            'store' => [
                'A merchant or Store Manager application already exists with the submitted business information.',
            ],
            'existing_application' => [
                $duplicate->application_number ?: (string) $duplicate->id,
            ],
        ]);
    }

    private function ensureRequiredDocumentsExist(
        ?Merchant $merchant,
        array $incomingDocuments
    ): void {
        $existingTypes = $merchant
            ? $merchant->documents()->pluck('document_type')->all()
            : [];

        $incomingTypes = collect($incomingDocuments)
            ->filter(fn ($file) => $file instanceof UploadedFile)
            ->keys()
            ->all();

        $availableTypes = collect([
            ...$existingTypes,
            ...$incomingTypes,
        ])->unique();

        $missing = collect(self::REQUIRED_DOCUMENTS)
            ->reject(fn ($type) => $availableTypes->contains($type))
            ->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' => [
                    'Missing required documents: ' . $missing->join(', '),
                ],
            ]);
        }
    }

    private function savePickupLocation(
        Merchant $merchant,
        array $pickup,
        array $suggested
    ): void {
        MerchantPickupLocation::updateOrCreate(
            [
                'merchant_id' => $merchant->id,
                'is_default' => true,
            ],
            [
                'name' => $pickup['name'],
                'contact_person' => $pickup['contact_person'],
                'phone' => $pickup['phone'],
                'address' => $pickup['address'],
                'city' => $pickup['city'],
                'area' => $pickup['area'],
                'latitude' => $pickup['latitude'],
                'longitude' => $pickup['longitude'],
                'suggested_branch_id' => $suggested['branch']->id ?? null,
                'suggested_sub_branch_id' => $suggested['sub_branch']->id ?? null,
                'branch_id' => null,
                'sub_branch_id' => null,
                'status' => 'pending_verification',
            ]
        );
    }

    private function saveDocuments(Merchant $merchant, array $documents): void
    {
        foreach ($documents as $type => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $existing = $merchant->documents()
                ->where('document_type', $type)
                ->first();

            $path = $file->store(
                "merchant-documents/{$merchant->id}",
                'public'
            );

            if ($existing?->file_path) {
                Storage::disk('public')->delete($existing->file_path);
            }

            $values = [
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'status' => 'pending',
            ];

            if (Schema::hasColumn('merchant_documents', 'size_bytes')) {
                $values['size_bytes'] = $file->getSize();
            } elseif (Schema::hasColumn('merchant_documents', 'size')) {
                $values['size'] = $file->getSize();
            }

            if (Schema::hasColumn('merchant_documents', 'remarks')) {
                $values['remarks'] = null;
            }

            if (Schema::hasColumn('merchant_documents', 'verified_by')) {
                $values['verified_by'] = null;
            }

            if (Schema::hasColumn('merchant_documents', 'verified_at')) {
                $values['verified_at'] = null;
            }

            MerchantDocument::updateOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'document_type' => $type,
                ],
                $values
            );
        }
    }

    private function makeCode(string $name): string
    {
        $base = strtoupper((string) str($name)->slug('-'));
        $base = substr($base, 0, 20) ?: 'STORE';
        $code = $base;
        $counter = 1;

        while (Merchant::query()->where('code', $code)->exists()) {
            $code = $base . '-' . $counter++;
        }

        return $code;
    }
}
