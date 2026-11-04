<?php

namespace App\Http\Requests;

use App\Models\QrCode;
use App\Rules\SafeRedirectUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQrCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $qrCode = $this->route('qr_code') ?? $this->route('qrCode');

        return $qrCode instanceof QrCode && $this->user()?->can('update', $qrCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $qrCode = $this->route('qr_code') ?? $this->route('qrCode');
        $id = $qrCode instanceof QrCode ? $qrCode->id : null;

        return [
            'title' => 'required|string|max:191',
            'code' => [
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[a-zA-Z0-9_-]+$/',
                Rule::unique('qr_codes', 'code')->ignore($id),
            ],
            'target_url' => ['required', 'string', new SafeRedirectUrl],
            'is_active' => 'required|boolean',
            'expires_at' => 'nullable|date',
            'styling' => 'nullable|array',
            'styling.darkColor' => 'nullable|string|max:10',
            'styling.lightColor' => 'nullable|string|max:10',
            'styling.transparentBg' => 'nullable|boolean',
            'styling.margin' => 'nullable|integer|min:0|max:50',
            'styling.errorCorrection' => 'nullable|string|in:L,M,Q,H',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'กรุณาระบุชื่อ QR Code',
            'code.required' => 'กรุณาระบุรหัสสั้น (Slug)',
            'code.unique' => 'รหัสสั้น (Slug) นี้มีผู้ใช้งานแล้ว',
            'code.regex' => 'รหัสสั้นต้องประกอบด้วยตัวอักษรภาษาอังกฤษ ตัวเลข ขีดกลาง (-) หรือขีดล่าง (_) เท่านั้น',
            'target_url.required' => 'กรุณาระบุ URL ปลายทาง',
        ];
    }
}
