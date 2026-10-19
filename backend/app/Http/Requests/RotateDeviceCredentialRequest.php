<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RotateDeviceCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The device middleware and locked service re-check device authentication.
        return true;
    }

    public function rules(): array
    {
        return [
            'next_credential_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'device_id' => ['prohibited'],
            'family_id' => ['prohibited'],
            'child_id' => ['prohibited'],
        ];
    }
}
