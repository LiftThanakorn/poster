<?php

namespace Database\Factories;

use App\Models\QrCode;
use App\Models\QrCodeScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrCodeScan>
 */
class QrCodeScanFactory extends Factory
{
    protected $model = QrCodeScan::class;

    public function definition(): array
    {
        return [
            'qr_code_id' => QrCode::factory(),
            'scanned_at' => now(),
            'ip_hash' => hash_hmac('sha256', fake()->ipv4(), 'test-key'),
            'user_agent' => fake()->userAgent(),
            'device_type' => fake()->randomElement(['mobile', 'tablet', 'desktop']),
            'platform' => fake()->randomElement(['iOS', 'Android', 'Windows', 'macOS']),
            'browser' => fake()->randomElement(['Safari', 'Chrome', 'LINE In-App', 'Facebook In-App']),
            'referrer' => fake()->optional()->url(),
        ];
    }
}
