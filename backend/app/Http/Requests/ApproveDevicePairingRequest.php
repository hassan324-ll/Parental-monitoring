<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveDevicePairingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Possession of a valid one-use invitation and affirmative device consent are checked separately.
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:32', 'regex:/^[a-fA-F0-9]{32}$/D'],
            'approved' => ['required', 'accepted'],
            'device_name' => ['required', 'string', 'min:2', 'max:100'],
            'android_version' => ['nullable', 'string', 'max:40'],
            'credential_hash' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'device_token' => ['prohibited'],
            'device_id' => ['prohibited'],
            'family_id' => ['prohibited'],
            'child_id' => ['prohibited'],
        ];
    }
}
