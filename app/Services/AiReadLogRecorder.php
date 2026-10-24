<?php

namespace App\Services;

use App\Models\AiReadLog;
use App\Models\VendingMachine;
use Illuminate\Support\Facades\DB;

class AiReadLogRecorder
{
    public function success(?int $userId, string $photoPath, array $read): AiReadLog
    {
        return AiReadLog::create([
            'user_id' => $userId,
            'photo_path' => $photoPath,
            'model' => $read['model'] ?? null,
            'prompt_version' => $read['prompt_version'] ?? null,
            'status' => 'success',
            'raw_response' => $read['raw'] ?? null,
            'predicted_products' => $read['result']['products'] ?? [],
            'gateway_log_id' => $read['gateway_log_id'] ?? null,
        ]);
    }

    public function failure(?int $userId, string $photoPath, string $reason): AiReadLog
    {
        return AiReadLog::create([
            'user_id' => $userId,
            'photo_path' => $photoPath,
            'model' => config('services.gemini.model'),
            'prompt_version' => config('services.gemini.prompt'),
            'status' => 'failed',
            'error_reason' => $reason,
        ]);
    }

    /** Link only the authenticated user's reading of the exact submitted photograph. */
    public function attach(int $logId, ?int $userId, VendingMachine $machine, array $confirmedRows): bool
    {
        return DB::transaction(function () use ($logId, $userId, $machine, $confirmedRows): bool {
            $log = AiReadLog::query()
                ->whereKey($logId)
                ->where('user_id', $userId)
                ->where('photo_path', $machine->photo_path)
                ->whereNull('vending_machine_id')
                ->lockForUpdate()
                ->first();

            if ($log === null) {
                return false;
            }

            $predicted = $log->predicted_products ?? [];
            $remaining = [];
            foreach ($predicted as $row) {
                $key = $this->rowKey($row);
                $remaining[$key] = ($remaining[$key] ?? 0) + 1;
            }

            $unchanged = 0;
            foreach ($confirmedRows as $row) {
                $key = $this->rowKey($row);
                if (($remaining[$key] ?? 0) > 0) {
                    $remaining[$key]--;
                    $unchanged++;
                }
            }
            $changes = max(count($predicted), count($confirmedRows)) - $unchanged;

            $log->update([
                'vending_machine_id' => $machine->id,
                'correction_count' => $log->status === 'success' ? $changes : null,
            ]);

            return true;
        });
    }

    private function rowKey(array $row): string
    {
        return json_encode([
            trim((string) ($row['name'] ?? '')),
            isset($row['price']) ? (int) $row['price'] : null,
            $row['temperature'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }
}
