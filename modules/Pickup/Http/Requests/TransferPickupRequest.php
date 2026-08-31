<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransferPickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'target_branch_id' => [
                'required',
                'integer',
                'min:1',
                'exists:branches,id',
            ],

            'target_sub_branch_id' => [
                'nullable',
                'integer',
                'min:1',
                'exists:sub_branches,id',
            ],

            'reason' => [
                'required',
                'string',
                'min:3',
                'max:1000',
            ],
        ];
    }
}