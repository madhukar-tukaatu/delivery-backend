<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
            'type' => [
                'nullable',
                'string',
                'in:missing,damaged,mismatch,other',
            ],
        ];
    }

    public function rejectionType(): string
    {
        return (string) ($this->validated('type') ?? 'other');
    }
}
