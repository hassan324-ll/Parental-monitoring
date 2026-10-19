<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateDeviceSharingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // Middleware and locked service reauthenticate the device.

    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean:strict'], 'disclosure_version' => ['required_if:enabled,true', 'nullable', 'in:sharing-v1'],
            'device_id' => ['prohibited'], 'family_id' => ['prohibited'], 'child_id' => ['prohibited'], 'version' => ['prohibited']];
    }
}
