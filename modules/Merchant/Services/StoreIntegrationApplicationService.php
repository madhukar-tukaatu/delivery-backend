<?php

namespace Modules\Merchant\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantDocument;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Routing\Services\BranchLocatorService;
use Throwable;

class StoreIntegrationApplicationService
{
    /**
     * Only these document groups are mandatory.
     *
     * Each group may contain ONE OR MANY files.
     */
    private const REQUIRED_DOCUMENTS = [
        'pan_vat',
        'owner_id',
    ];

    /**
     * All supported document groups.
     */
    private const DOCUMENT_TYPES = [
        'business_registration',
        'pan_vat',
        'owner_id',
        'bank_proof',
        'office_photo',
        'authorisation_letter',
        'additional_documents',
    ];

    private const DOCUMENT_DISK = 'public';

    private const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    private const DOWNLOAD_TIMEOUT_SECONDS = 60;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private BranchLocatorService $branchLocator
    ) {
    }

    /**
     * Submit Store Manager integration application.
     */
    public function submit(
        string $applicationNumber,
        array $data,
        array $documents
    ): array {
        $newFiles = [];

        $oldFiles = [];

        try {
            $result = DB::transaction(
                function () use (
                    $applicationNumber,
                    $data,
                    $documents,
                    &$newFiles,
                    &$oldFiles
                ) {
                    /*
                     * =====================================================
                     * Find existing merchant/application
                     * =====================================================
                     */

                    $merchant = Merchant::query()
                        ->where(
                            'application_number',
                            $applicationNumber
                        )
                        ->lockForUpdate()
                        ->first();

                    $created = ! $merchant;

                    /*
                     * Already approved/suspended applications
                     * cannot be submitted again.
                     */
                    if (
                        $merchant &&
                        in_array(
                            $merchant->integration_status,
                            [
                                'approved',
                                'suspended',
                            ],
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'application_number' => [
                                'This Store Manager application has already been approved.',
                            ],
                        ]);
                    }

                    /*
                     * =====================================================
                     * Duplicate merchant validation
                     * =====================================================
                     */

                    $this->ensureNoDuplicateMerchant(
                        $data,
                        $merchant?->id,
                        $applicationNumber
                    );

                    /*
                     * =====================================================
                     * Required documents
                     * =====================================================
                     *
                     * ONLY:
                     *
                     *   pan_vat
                     *   owner_id
                     *
                     * are mandatory.
                     *
                     * Existing documents are considered during
                     * resubmission.
                     */

                    $this->ensureRequiredDocumentsExist(
                        $merchant,
                        $documents
                    );

                    /*
                     * =====================================================
                     * Pickup location
                     * =====================================================
                     */

                    $pickup = $data['pickup_location'];

                    $location = $this->branchLocator->locate(
                        (float) $pickup['latitude'],
                        (float) $pickup['longitude']
                    );

                    $suggestedBranch =
                        $location['branch'] ?? null;

                    $suggestedSubBranch =
                        $location['sub_branch'] ?? null;

                    /*
                     * =====================================================
                     * Create merchant when necessary
                     * =====================================================
                     */

                    if (! $merchant) {
                        $merchant = new Merchant();

                        $merchant->application_number =
                            $applicationNumber;

                        $merchant->code =
                            $this->makeCode(
                                $data['store']['name']
                            );
                    }

                    /*
                     * =====================================================
                     * Sanitize integration payload
                     * =====================================================
                     */

                    $integrationPayload =
                        $this->sanitizeIntegrationPayload(
                            $data
                        );

                    /*
                     * =====================================================
                     * Save merchant
                     * =====================================================
                     */

                    $merchant->forceFill([

                        'application_source' =>
                            Merchant::SOURCE_STORE_MANAGER,

                        'external_store_id' =>
                            $data['store']['external_store_id'],

                        'external_platform' =>
                            $data['store']['platform'],

                        'store_category' =>
                            $data['store']['category'] ?? null,

                        'store_url' =>
                            $data['store']['url'] ?? null,

                        'name' =>
                            $data['store']['name'],

                        'owner_name' =>
                            $data['business']['owner_name'],

                        'contact_person' =>
                            $data['business']['contact_person']
                            ?? $data['business']['owner_name'],

                        'phone' =>
                            $data['business']['phone'],

                        'email' =>
                            strtolower(
                                $data['business']['email']
                            ),

                        'website_url' =>
                            $data['store']['url'] ?? null,

                        'business_type' =>
                            $data['business']['type'] ?? null,

                        'pan_vat_number' =>
                            $data['business']['pan_vat_number'],

                        'registration_number' =>
                            $data['business']['registration_number'],

                        'address' =>
                            $data['business']['registered_address'],

                        'pickup_address' =>
                            $pickup['address'] ?? null,

                        'pickup_city' =>
                            $pickup['city'] ?? null,

                        'pickup_area' =>
                            $pickup['area'] ?? null,

                        'pickup_lat' =>
                            $pickup['latitude'],

                        'pickup_lng' =>
                            $pickup['longitude'],

                        'suggested_branch_id' =>
                            $suggestedBranch?->id,

                        'suggested_sub_branch_id' =>
                            $suggestedSubBranch?->id,

                        'requested_services' =>
                            array_values(
                                array_unique(
                                    $data['requested_services']
                                )
                            ),

                        'approved_services' => null,

                        'integration_payload' =>
                            $integrationPayload,

                        'integration_callback_url' =>
                            $data['callback']['url'],

                        'integration_callback_secret' =>
                            $data['callback']['secret'],

                        'integration_status' =>
                            'pending_review',

                        'integration_callback_status' =>
                            null,

                        'submitted_at' =>
                            now(),

                        'status' =>
                            'pending_verification',

                        'verification_status' =>
                            'submitted',

                        'more_info_message' =>
                            null,

                        'rejected_reason' =>
                            null,

                    ])->save();

                    /*
                     * =====================================================
                     * Save pickup location
                     * =====================================================
                     */

                    $this->savePickupLocation(
                        $merchant,
                        $pickup,
                        $suggestedBranch?->id,
                        $suggestedSubBranch?->id
                    );

                    /*
                     * =====================================================
                     * Save documents
                     * =====================================================
                     *
                     * Optional groups are accepted if supplied.
                     *
                     * Required:
                     *   pan_vat
                     *   owner_id
                     *
                     * Optional:
                     *   business_registration
                     *   bank_proof
                     *   office_photo
                     *   authorisation_letter
                     *   additional_documents
                     */

                    $this->saveDocuments(
                        $merchant,
                        $documents,
                        $newFiles,
                        $oldFiles
                    );

                    /*
                     * =====================================================
                     * Return fresh merchant
                     * =====================================================
                     */

                    return [
                        'merchant' => $merchant->fresh([
                            'documents',
                            'pickupLocations',
                            'suggestedBranch',
                            'suggestedSubBranch',
                        ]),

                        'created' => $created,
                    ];
                }
            );
        } catch (Throwable $exception) {
            /*
             * If transaction fails, remove files that were uploaded
             * during this request.
             */
            $this->deleteFiles($newFiles);

            throw $exception;
        }

        /*
         * Delete replaced documents only after transaction succeeds.
         */
        $this->deleteFiles($oldFiles);

        return $result;
    }

    /**
     * Prevent duplicate merchant/application records.
     */
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
                fn ($query) =>
                    $query->where(
                        'id',
                        '!=',
                        $currentMerchantId
                    )
            )
            ->where(function ($query) use (
                $business,
                $store,
                $applicationNumber
            ) {
                $query
                    ->where(
                        'application_number',
                        $applicationNumber
                    )

                    ->orWhere(function ($query) use ($store) {
                        $query
                            ->where(
                                'application_source',
                                Merchant::SOURCE_STORE_MANAGER
                            )
                            ->where(
                                'external_store_id',
                                $store['external_store_id']
                            )
                            ->where(
                                'external_platform',
                                $store['platform']
                            );
                    })

                    ->orWhere(
                        'pan_vat_number',
                        $business['pan_vat_number']
                    )

                    ->orWhere(
                        'registration_number',
                        $business['registration_number']
                    )

                    ->orWhere(
                        'email',
                        strtolower(
                            $business['email']
                        )
                    );
            })
            ->first();

        if (! $duplicate) {
            return;
        }

        throw ValidationException::withMessages([
            'store' => [
                'A merchant or Store Manager application already exists with the submitted business information.',
            ],

            'existing_application' => [
                $duplicate->application_number
                    ?: (string) $duplicate->id,
            ],
        ]);
    }

    /**
     * Ensure ONLY required document groups exist.
     *
     * Required:
     *
     *   pan_vat
     *   owner_id
     *
     * Existing documents are considered during resubmission.
     */
    private function ensureRequiredDocumentsExist(
        ?Merchant $merchant,
        array $incomingDocuments
    ): void {
        /*
         * Existing document types.
         */
        $existingTypes = $merchant
            ? $merchant->documents()
                ->pluck('document_type')
                ->unique()
                ->values()
                ->all()
            : [];

        /*
         * Incoming document types that actually contain
         * at least one document.
         */
        $incomingTypes = collect(
            $incomingDocuments
        )
            ->filter(function ($documents) {
                return is_array($documents)
                    && count($documents) > 0;
            })
            ->keys()
            ->all();

        /*
         * Combine existing and incoming documents.
         */
        $availableTypes = collect([
            ...$existingTypes,
            ...$incomingTypes,
        ])
            ->unique();

        /*
         * Find missing required types.
         */
        $missing = collect(
            self::REQUIRED_DOCUMENTS
        )
            ->reject(
                fn (string $type) =>
                    $availableTypes->contains($type)
            )
            ->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' => [
                    'Missing required documents: '
                    . $missing->join(', '),
                ],
            ]);
        }
    }

    /**
     * Save merchant pickup location.
     */
    private function savePickupLocation(
        Merchant $merchant,
        array $pickup,
        ?int $suggestedBranchId,
        ?int $suggestedSubBranchId
    ): void {
        MerchantPickupLocation::updateOrCreate(
            [
                'merchant_id' =>
                    $merchant->id,

                'is_default' =>
                    true,
            ],
            [
                'name' =>
                    $pickup['name'] ?? null,

                'contact_person' =>
                    $pickup['contact_person'] ?? null,

                'phone' =>
                    $pickup['phone'],

                'address' =>
                    $pickup['address'] ?? null,

                'city' =>
                    $pickup['city'] ?? null,

                'area' =>
                    $pickup['area'] ?? null,

                'latitude' =>
                    $pickup['latitude'],

                'longitude' =>
                    $pickup['longitude'],

                'suggested_branch_id' =>
                    $suggestedBranchId,

                'suggested_sub_branch_id' =>
                    $suggestedSubBranchId,

                /*
                 * Actual branch assignment remains pending
                 * until verification.
                 */
                'branch_id' => null,

                'sub_branch_id' => null,

                'status' =>
                    'pending_verification',
            ]
        );
    }

    /**
     * Save multiple documents per document type.
     *
     * Example:
     *
     * owner_id => [
     *     file1,
     *     file2,
     *     file3,
     * ]
     */
    private function saveDocuments(
        Merchant $merchant,
        array $documents,
        array &$newFiles,
        array &$oldFiles
    ): void {
        foreach ($documents as $type => $documentList) {

            /*
             * Ignore empty document groups.
             */
            if (
                ! is_array($documentList) ||
                empty($documentList)
            ) {
                continue;
            }

            /*
             * Only accept known document groups.
             */
            if (
                ! in_array(
                    $type,
                    self::DOCUMENT_TYPES,
                    true
                )
            ) {
                continue;
            }

            /*
             * If a document group is submitted again,
             * replace the old files for that group.
             *
             * Example:
             *
             * Existing owner_id:
             *
             *   A
             *   B
             *
             * New owner_id:
             *
             *   C
             *
             * Result:
             *
             *   C
             */
            $this->removeExistingDocumentsForType(
                $merchant,
                (string) $type,
                $oldFiles
            );

            /*
             * Save each document.
             */
            foreach ($documentList as $document) {

                if (
                    ! is_array($document) ||
                    empty($document['url'])
                ) {
                    continue;
                }

                $this->downloadAndStoreDocument(
                    $merchant,
                    (string) $type,
                    $document,
                    $newFiles
                );
            }
        }
    }

    /**
     * Mark old files for deletion and remove database records.
     */
    private function removeExistingDocumentsForType(
        Merchant $merchant,
        string $type,
        array &$oldFiles
    ): void {
        $existingDocuments = $merchant
            ->documents()
            ->where(
                'document_type',
                $type
            )
            ->get();

        foreach ($existingDocuments as $existing) {

            if ($existing->file_path) {
                $oldFiles[] = [
                    'disk' =>
                        $existing->disk
                        ?: self::DOCUMENT_DISK,

                    'path' =>
                        $existing->file_path,
                ];
            }

            $existing->delete();
        }
    }

    /**
     * Download document from source URL and store it.
     */
    private function downloadAndStoreDocument(
        Merchant $merchant,
        string $type,
        array $document,
        array &$newFiles
    ): void {
        $sourceUrl = (string) $document['url'];

        /*
         * Validate URL before making HTTP request.
         */
        $this->validateDocumentSourceUrl(
            $sourceUrl,
            $type
        );

        $temporaryPath = tempnam(
            sys_get_temp_dir(),
            'tukaatu_merchant_'
        );

        if ($temporaryPath === false) {
            throw ValidationException::withMessages([
                "documents.{$type}" => [
                    'Could not create a temporary document file.',
                ],
            ]);
        }

        try {

            /*
             * =========================================================
             * Download document
             * =========================================================
             */

            try {
                $response = Http::connectTimeout(
                    self::CONNECT_TIMEOUT_SECONDS
                )
                    ->timeout(
                        self::DOWNLOAD_TIMEOUT_SECONDS
                    )
                    ->retry(2, 500)
                    ->withOptions([
                        'sink' =>
                            $temporaryPath,

                        /*
                         * Do not follow redirects automatically.
                         *
                         * This prevents redirect-based SSRF bypasses.
                         */
                        'allow_redirects' =>
                            false,

                        'verify' =>
                            true,
                    ])
                    ->get($sourceUrl);

            } catch (Throwable) {
                throw ValidationException::withMessages([
                    "documents.{$type}.url" => [
                        'Tukaatu could not download this document URL.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * HTTP status
             * =========================================================
             */

            if (! $response->successful()) {
                throw ValidationException::withMessages([
                    "documents.{$type}.url" => [
                        "Unable to download document. HTTP {$response->status()}.",
                    ],
                ]);
            }

            /*
             * =========================================================
             * File size
             * =========================================================
             */

            $fileSize = filesize(
                $temporaryPath
            );

            if (
                $fileSize === false ||
                $fileSize < 1
            ) {
                throw ValidationException::withMessages([
                    "documents.{$type}.url" => [
                        'The downloaded document is empty.',
                    ],
                ]);
            }

            if (
                $fileSize >
                self::MAX_DOCUMENT_BYTES
            ) {
                throw ValidationException::withMessages([
                    "documents.{$type}.url" => [
                        'The downloaded document must not exceed 10 MB.',
                    ],
                ]);
            }

            /*
             * If the sender supplied size_bytes,
             * verify it against the actual downloaded file.
             */
            if (
                isset($document['size_bytes']) &&
                (int) $document['size_bytes']
                !== (int) $fileSize
            ) {
                throw ValidationException::withMessages([
                    "documents.{$type}.size_bytes" => [
                        'The submitted document size does not match the downloaded file.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * Detect MIME type
             * =========================================================
             */

            $mimeType = (
                new \finfo(
                    FILEINFO_MIME_TYPE
                )
            )->file(
                $temporaryPath
            );

            /*
             * Office photo is image-only.
             *
             * Other document groups can contain:
             *
             * PDF
             * JPEG
             * PNG
             * WEBP
             */
            $allowedMimeTypes =
                $type === 'office_photo'
                    ? [
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                    ]
                    : [
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                    ];

            if (
                ! $mimeType ||
                ! in_array(
                    $mimeType,
                    $allowedMimeTypes,
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    "documents.{$type}.url" => [
                        'Unsupported document type.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * SHA-256 checksum
             * =========================================================
             */

            $actualChecksum = hash_file(
                'sha256',
                $temporaryPath
            );

            if (
                ! empty($document['sha256']) &&
                ! hash_equals(
                    strtolower(
                        (string) $document['sha256']
                    ),
                    strtolower(
                        (string) $actualChecksum
                    )
                )
            ) {
                throw ValidationException::withMessages([
                    "documents.{$type}.sha256" => [
                        'The document checksum does not match.',
                    ],
                ]);
            }

            /*
             * =========================================================
             * Determine extension from actual MIME type
             * =========================================================
             */

            $extension = match ($mimeType) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',

                default =>
                    throw new \RuntimeException(
                        'Unsupported document MIME type.'
                    ),
            };

            /*
             * =========================================================
             * Generate unique storage path
             * =========================================================
             */

            $path = sprintf(
                'merchant-documents/%d/%s-%s.%s',
                $merchant->id,
                $type,
                Str::uuid(),
                $extension
            );

            /*
             * =========================================================
             * Store file
             * =========================================================
             */

            $stream = fopen(
                $temporaryPath,
                'rb'
            );

            if ($stream === false) {
                throw new \RuntimeException(
                    'Could not open the downloaded document.'
                );
            }

            try {
                $stored = Storage::disk(
                    self::DOCUMENT_DISK
                )->put(
                    $path,
                    $stream
                );
            } finally {
                fclose($stream);
            }

            if (! $stored) {
                throw ValidationException::withMessages([
                    "documents.{$type}" => [
                        'Tukaatu could not save the downloaded document.',
                    ],
                ]);
            }

            /*
             * Keep track of newly created file so it can be
             * removed if the transaction fails.
             */
            $newFiles[] = [
                'disk' =>
                    self::DOCUMENT_DISK,

                'path' =>
                    $path,
            ];

            /*
             * =========================================================
             * Original filename
             * =========================================================
             *
             * This is OPTIONAL.
             *
             * Office photo does NOT require a name.
             */

            $originalName =
                $this->sanitizeOriginalName(
                    $document['original_name']
                    ?? "{$type}.{$extension}"
                );

            /*
             * =========================================================
             * Database values
             * =========================================================
             */

            $values = [
                'file_path' =>
                    $path,

                'original_name' =>
                    $originalName,

                'mime_type' =>
                    $mimeType,

                'status' =>
                    'pending',
            ];

            /*
             * Disk column.
             */
            if (
                Schema::hasColumn(
                    'merchant_documents',
                    'disk'
                )
            ) {
                $values['disk'] =
                    self::DOCUMENT_DISK;
            }

            /*
             * File size.
             */
            if (
                Schema::hasColumn(
                    'merchant_documents',
                    'size_bytes'
                )
            ) {
                $values['size_bytes'] =
                    $fileSize;
            } elseif (
                Schema::hasColumn(
                    'merchant_documents',
                    'size'
                )
            ) {
                $values['size'] =
                    $fileSize;
            }

            /*
             * SHA-256 checksum.
             */
            if (
                Schema::hasColumn(
                    'merchant_documents',
                    'checksum_sha256'
                )
            ) {
                $values['checksum_sha256'] =
                    $actualChecksum;
            }

            /*
             * Source host.
             */
            if (
                Schema::hasColumn(
                    'merchant_documents',
                    'source_host'
                )
            ) {
                $values['source_host'] =
                    parse_url(
                        $sourceUrl,
                        PHP_URL_HOST
                    );
            }

            /*
             * Remarks.
             */
            if (
                Schema::hasColumn(
                    'merchant_documents',
                    'remarks'
                )
            ) {
                $values['remarks'] =
                    null;
            }

            /*
             * Verification user.
             */
            if (
                Schema::hasColumn(
                    'merchant_documents',
                    'verified_by'
                )
            ) {
                $values['verified_by'] =
                    null;
            }

            /*
             * Verification timestamp.
             */
            if (
                Schema::hasColumn(
                    'merchant_documents',
                    'verified_at'
                )
            ) {
                $values['verified_at'] =
                    null;
            }

            /*
             * IMPORTANT:
             *
             * create(), NOT updateOrCreate().
             *
             * This allows multiple documents:
             *
             * owner_id #1
             * owner_id #2
             * owner_id #3
             */
            MerchantDocument::create([
                'merchant_id' =>
                    $merchant->id,

                'document_type' =>
                    $type,

                ...$values,
            ]);

        } finally {

            /*
             * Always remove temporary file.
             */
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * Validate document source URL.
     *
     * Only:
     *
     *   HTTPS
     *   port 443
     *   public IP
     *
     * are allowed.
     */
    private function validateDocumentSourceUrl(
        string $url,
        string $documentType
    ): void {
        $parts = parse_url($url);

        $scheme = strtolower(
            (string) (
                $parts['scheme'] ?? ''
            )
        );

        $host = strtolower(
            (string) (
                $parts['host'] ?? ''
            )
        );

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : 443;

        /*
         * Basic URL validation.
         */
        if (
            $scheme !== 'https' ||
            $host === '' ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            $port !== 443
        ) {
            throw ValidationException::withMessages([
                "documents.{$documentType}.url" => [
                    'The document URL must be a public HTTPS URL using port 443.',
                ],
            ]);
        }

        /*
         * Resolve host.
         */
        $addresses = [];

        if (
            filter_var(
                $host,
                FILTER_VALIDATE_IP
            )
        ) {
            $addresses[] = $host;
        } else {

            $records = @dns_get_record(
                $host,
                DNS_A | DNS_AAAA
            );

            if (is_array($records)) {
                foreach ($records as $record) {

                    if (! empty($record['ip'])) {
                        $addresses[] =
                            $record['ip'];
                    }

                    if (! empty($record['ipv6'])) {
                        $addresses[] =
                            $record['ipv6'];
                    }
                }
            }

            /*
             * Fallback IPv4 resolution.
             */
            if (! $addresses) {
                $ipv4Addresses =
                    @gethostbynamel($host);

                if (is_array($ipv4Addresses)) {
                    $addresses = array_merge(
                        $addresses,
                        $ipv4Addresses
                    );
                }
            }
        }

        $addresses = array_values(
            array_unique($addresses)
        );

        if (! $addresses) {
            throw ValidationException::withMessages([
                "documents.{$documentType}.url" => [
                    'The document host could not be resolved.',
                ],
            ]);
        }

        /*
         * Prevent private/reserved IP access.
         */
        foreach ($addresses as $address) {

            if (
                ! $this->isPublicIpAddress(
                    $address
                )
            ) {
                throw ValidationException::withMessages([
                    "documents.{$documentType}.url" => [
                        'The document URL resolves to a private or reserved network address.',
                    ],
                ]);
            }
        }
    }

    /**
     * Determine whether an IP is public.
     */
    private function isPublicIpAddress(
        string $address
    ): bool {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE |
            FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Remove sensitive and unnecessary data
     * before storing integration payload.
     */
    private function sanitizeIntegrationPayload(
        array $data
    ): array {
        $payload = $data;

        /*
         * Never persist callback secret.
         */
        unset(
            $payload['callback']['secret']
        );

        /*
         * Remove source document URLs.
         *
         * Actual documents are stored locally/S3
         * and tracked in merchant_documents.
         */
        if (! empty($payload['documents'])) {

            $payload['documents'] =
                collect(
                    $payload['documents']
                )
                    ->map(
                        function ($documents) {

                            if (
                                ! is_array(
                                    $documents
                                )
                            ) {
                                return null;
                            }

                            return collect(
                                $documents
                            )
                                ->map(
                                    function ($document) {

                                        if (
                                            ! is_array(
                                                $document
                                            )
                                        ) {
                                            return null;
                                        }

                                        /*
                                         * Do not store external
                                         * source URL.
                                         */
                                        unset(
                                            $document['url']
                                        );

                                        return $document;
                                    }
                                )
                                ->filter()
                                ->values()
                                ->all();
                        }
                    )
                    ->filter()
                    ->all();
        }

        return $payload;
    }

    /**
     * Sanitize original document filename.
     *
     * Filename is optional.
     */
    private function sanitizeOriginalName(
        string $name
    ): string {
        $name = basename(
            str_replace(
                '\\',
                '/',
                trim($name)
            )
        );

        if (
            $name === '' ||
            $name === '.' ||
            $name === '..'
        ) {
            return 'document';
        }

        return Str::limit(
            $name,
            255,
            ''
        );
    }

    /**
     * Delete files safely.
     */
    private function deleteFiles(
        array $files
    ): void {
        foreach ($files as $file) {

            $disk =
                $file['disk']
                ?? self::DOCUMENT_DISK;

            $path =
                $file['path']
                ?? null;

            if (! $path) {
                continue;
            }

            try {
                Storage::disk(
                    $disk
                )->delete($path);
            } catch (Throwable) {
                /*
                 * Cleanup failure must not reverse
                 * the successful application.
                 */
            }
        }
    }

    /**
     * Generate unique merchant code.
     */
    private function makeCode(
        string $name
    ): string {
        $base = strtoupper(
            (string) str($name)
                ->slug('-')
        );

        $base =
            substr(
                $base,
                0,
                20
            ) ?: 'STORE';

        $code = $base;

        $counter = 1;

        while (
            Merchant::query()
                ->where(
                    'code',
                    $code
                )
                ->exists()
        ) {
            $code =
                $base .
                '-' .
                $counter++;
        }

        return $code;
    }
}