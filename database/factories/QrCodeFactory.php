<?php

namespace Database\Factories;

use App\Models\QrCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QrCode>
 */
class QrCodeFactory extends Factory
{
    protected $model = QrCode::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'code' => Str::lower(Str::random(8)),
            'target_url' => fake()->url(),
            'is_active' => true,
            'expires_at' => null,
            'scan_count' => 0,
            'styling' => [
                'darkColor' => '#000000',
                'lightColor' => '#ffffff',
                'transparentBg' => false,
                'margin' => 2,
                'errorCorrection' => 'H',
            ],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
