<?php

namespace App\Http\Requests;

use App\Rules\SafeRedirectUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreQrCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:191',
            'code' => 'nullable|string|min:3|max:64|regex:/^[a-zA-Z0-9_-]+$/|unique:qr_codes,code',
            'target_url' => ['required', 'string', new SafeRedirectUrl],
            'is_active' => 'nullable|boolean',
            'expires_at' => 'nullable|date|after:now',
            'styling' => 'nullable|array',
            'styling.darkColor' => 'nullable|string|max:10',
            'styling.lightColor' => 'nullable|string|max:10',
            'styling.transparentBg' => 'nullable|boolean',
            'styling.margin' => 'nullable|integer|min:0|max:50',
            'styling.errorCorrection' => 'nullable|string|in:L,M,Q,H',
            'styling.logoMediaId' => 'nullable|integer|exists:post_media,id',
            'styling.logoUrl' => 'nullable|string|max:4096',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'กรุณาระบุชื่อ QR Code',
            'title.max' => 'ชื่อ QR Code ต้องไม่เกิน 191 ตัวอักษร',
            'code.unique' => 'รหัสสั้น (Slug) นี้มีผู้ใช้งานแล้ว กรุณาระบุรหัสอื่น',
            'code.regex' => 'รหัสสั้นต้องประกอบด้วยตัวอักษรภาษาอังกฤษ ตัวเลข ขีดกลาง (-) หรือขีดล่าง (_) เท่านั้น',
            'code.min' => 'รหัสสั้นต้องมีความยาวอย่างน้อย 3 ตัวอักษร',
            'code.max' => 'รหัสสั้นต้องมีความยาวไม่เกิน 64 ตัวอักษร',
            'target_url.required' => 'กรุณาระบุ URL ปลายทาง',
            'expires_at.after' => 'วันหมดอายุต้องเป็นเวลาในอนาคต',
        ];
    }
}
