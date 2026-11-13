<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Dispatch\Models\TransferCorridor;
use Modules\Dispatch\Models\TransferRun;
use Modules\Dispatch\Services\TransferAnalyticsService;
use Modules\Dispatch\Services\TransferPlanningService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TransferPlanningController extends Controller
{
    public function __construct(
        private readonly TransferPlanningService $planning,
        private readonly TransferAnalyticsService $analytics,
    ) {
    }

    public function demand(Request $request)
    {
        $scope = $this->getBranchScope($request->user());
        $data = $this->planning->demand(
            $request->filled('origin_branch_id') ? (int) $request->input('origin_branch_id') : null,
            $request->input('service_type'),
            $request->input('priority'),
            $scope,
        );

        return ApiResponse::success($data, 'Transfer demand board');
    }

    public function recommend(Request $request)
    {
        $scope = $this->getBranchScope($request->user());
        $data = $this->planning->recommend(
            $request->filled('origin_branch_id') ? (int) $request->input('origin_branch_id') : null,
            $request->input('service_type'),
            $scope,
        );

        return ApiResponse::success($data, 'Fleet plan and recommended TR runs');
    }

    public function storeRun(Request $request)
    {
        try {
            $data = $this->planning->createRun($request->all(), $request->user()?->id, $this->getBranchScope($request->user()));
            return ApiResponse::success($data, 'TR run created', 201);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }
    }

    public function acceptPlan(Request $request)
    {
        $items = $request->input('runs', $request->input('keys', []));
        if ($items !== [] && array_is_list($items) && is_string($items[0] ?? null)) {
            $items = array_map(fn ($key) => ['recommendation_key' => $key], $items);
        }
        if (! is_array($items) || $items === []) {
            return ApiResponse::error('Select at least one recommended run to accept.', 422);
        }
        try {
            $data = $this->planning->createRuns($items, $request->user()?->id, $this->getBranchScope($request->user()));
            $msg = count($data['created']).' TR run(s) created';
            if ($data['errors'] !== []) {
                $msg .= '; some were skipped';
            }
            return ApiResponse::success($data, $msg);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }
    }

    public function runs(Request $request)
    {
        $scope = $this->getBranchScope($request->user());
        $data = $this->planning->listRuns(
            $request->input('status', 'active'),
            $request->filled('origin_branch_id') ? (int) $request->input('origin_branch_id') : null,
            $scope,
        );

        return ApiResponse::success(['runs' => $data], 'TR runs');
    }

    public function showRun(Request $request, TransferRun $run)
    {
        $this->assertCanSee($request, $run);
        return ApiResponse::success($this->planning->serializeRun($run), 'TR run');
    }

    public function dispatchRun(Request $request, TransferRun $run)
    {
        $this->assertCanSee($request, $run);
        try {
            $data = $this->planning->dispatchRun($run, $request->all(), $request->user()?->id);
            return ApiResponse::success($data, 'TR run dispatched');
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }
    }

    public function cancelRun(Request $request, TransferRun $run)
    {
        $this->assertCanSee($request, $run);
        try {
            $data = $this->planning->cancelRun($run, $request->input('reason'), $request->user()?->id);
            return ApiResponse::success($data, 'TR run cancelled');
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }
    }

    public function continueRun(Request $request, TransferRun $run)
    {
        $this->assertCanSee($request, $run);
        try {
            $data = $this->planning->continueRun($run, $request->user()?->id);
            return ApiResponse::success($data, 'TR run continued to the next stop');
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }
    }

    public function analytics(Request $request)
    {
        $data = $this->analytics->report(
            $request->input('from'),
            $request->input('to'),
            $this->getBranchScope($request->user()),
        );

        return ApiResponse::success($data, 'Transfer analytics');
    }

    public function analyticsExport(Request $request): StreamedResponse
    {
        $data = $this->analytics->report(
            $request->input('from'),
            $request->input('to'),
            $this->getBranchScope($request->user()),
        );
        $csv = $this->analytics->toCsv($data);
        $filename = 'transfer-analytics-'.$data['from'].'-to-'.$data['to'].'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function corridors()
    {
        $rows = TransferCorridor::query()->with('stops')->where('is_active', true)->orderBy('priority')->orderBy('code')->get();
        return ApiResponse::success(['corridors' => $rows], 'Transfer corridors');
    }

    private function assertCanSee(Request $request, TransferRun $run): void
    {
        $scope = $this->getBranchScope($request->user());
        if ($scope === 0) {
            return;
        }
        $ids = $run->stops()->pluck('branch_id')->push($run->origin_branch_id)->unique()->all();
        abort_unless(in_array($scope, $ids, true), 403, 'This TR run is outside your branch.');
    }

    private function getBranchScope($user): int
    {
        if (! $user) {
            return 0;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return 0;
        }
        if (method_exists($user, 'hasRole') && $user->hasRole('main_admin')) {
            return 0;
        }
        $branchId = (int) ($user->branch_id ?? 0);
        abort_if($branchId === 0, 403, 'Your account is not linked to a branch, so it has no transfers.');

        return $branchId;
    }
}
