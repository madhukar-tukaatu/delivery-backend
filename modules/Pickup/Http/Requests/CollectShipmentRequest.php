<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignPickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ];
    }
}