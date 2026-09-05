<?php

namespace App\Services\Updates;

use Illuminate\Support\Facades\Cache;

class ApplicationUpdateStatus
{
    private const KEY = 'visual-builder.application-update';

    /** @return array{status: string, message: string, version: string|null, percent: float|null, updated_at: string|null} */
    public function get(): array
    {
        return Cache::get(self::KEY, [
            'status' => 'idle',
            'message' => 'No update check has been run.',
            'version' => null,
            'percent' => null,
            'updated_at' => null,
        ]);
    }

    public function set(string $status, string $message, ?string $version = null, ?float $percent = null): void
    {
        Cache::forever(self::KEY, [
            'status' => $status,
            'message' => $message,
            'version' => $version,
            'percent' => $percent,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
