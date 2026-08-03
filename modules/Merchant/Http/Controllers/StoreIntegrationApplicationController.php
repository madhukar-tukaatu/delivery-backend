<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Modules\Merchant\Events\MerchantApplicationChanged;
use Modules\Merchant\Http\Requests\StoreIntegrationSubmissionRequest;
use Modules\Merchant\Services\StoreIntegrationApplicationService;

class StoreIntegrationApplicationController extends Controller
{
    public function submit(
        StoreIntegrationSubmissionRequest $request,
        string $applicationNumber,
        StoreIntegrationApplicationService $service
    ) {
        $data = $request->validated();

        $result = $service->submit(
            applicationNumber: $applicationNumber,
            data: $data,
            documents: $data['documents']
        );

        /*
         * StoreIntegrationApplicationService has already completed
         * its database transaction before returning.
         */
        $merchant = $result['merchant']->fresh([
            'defaultBranch',
            'defaultSubBranch',
            'suggestedBranch',
            'suggestedSubBranch',
        ]);

        event(
            new MerchantApplicationChanged(
                merchantId: $merchant->id,

                action: $result['created']
                    ? 'created'
                    : 'resubmitted',

                source: $merchant->application_source,
                status: $merchant->status
            )
        );

        return ApiResponse::success(
            [
                'application_number' =>
                    $merchant->application_number,

                'merchant_application_id' =>
                    $merchant->id,

                'status' =>
                    $merchant->status,

                'verification_status' =>
                    $merchant->verification_status,

                'integration_status' =>
                    $merchant->integration_status,

                'submitted_at' =>
                    $merchant->submitted_at,

                'created' =>
                    $result['created'],
            ],
            $result['created']
                ? 'Store integration application submitted successfully.'
                : 'Store integration application updated and resubmitted successfully.',
            $result['created'] ? 201 : 200
        );
    }
}