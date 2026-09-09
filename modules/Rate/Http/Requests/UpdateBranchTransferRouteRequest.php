<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Validation\Rule;

final class UpdateBranchTransferRouteRequest extends StoreBranchTransferRouteRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $route   = $this->route('transferRoute');
        $routeId = is_object($route) ? $route->id : $route;

        // Keep route_code optional (auto-generated if omitted), but unique-ignore self.
        $rules['route_code'] = [
            'nullable',
            'string',
            'max:100',
            Rule::unique('branch_transfer_routes', 'route_code')->ignore($routeId),
        ];

        return $rules;
    }
}
