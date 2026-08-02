<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Modules\Merchant\Http\Requests\StoreIntegrationSubmissionRequest;
use Modules\Merchant\Services\StoreIntegrationApplicationService;

class StoreIntegrationApplicationController extends Controller
{
    public function submit(
        StoreIntegrationSubmissionRequest $request,
        string $applicationNumber,
        StoreIntegrationApplicationService $service
    ) {
        $result = $service->submit(
            $applicationNumber,
            $request->validated(),
            $request->file('documents', [])
        );

        return ApiResponse::success(
            [
                'application_number' => $result['merchant']->application_number,
                'merchant_application_id' => $result['merchant']->id,
                'status' => $result['merchant']->status,
                'verification_status' => $result['merchant']->verification_status,
                'integration_status' => $result['merchant']->integration_status,
                'submitted_at' => $result['merchant']->submitted_at,
                'created' => $result['created'],
            ],
            $result['created']
                ? 'Store integration application submitted successfully.'
                : 'Store integration application updated and resubmitted successfully.',
            $result['created'] ? 201 : 200
        );
    }
}
