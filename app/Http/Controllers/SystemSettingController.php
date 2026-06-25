<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemSettingController extends Controller
{
    /**
     * GET /settings
     * Returns all settings grouped by their group key.
     * Values are cast to their declared type.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => SystemSetting::allGrouped(),
        ]);
    }

    /**
     * PUT /settings
     * Bulk-update one or more settings.
     * Only existing keys are accepted — unknown keys are ignored.
     * Type validation is enforced per key.
     *
     * Body: { "settings": { "low_stock_threshold": 10, "site_name": "Hometex" } }
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings'   => 'required|array|min:1',
            'settings.*' => 'nullable',
        ]);

        $incoming = $request->input('settings');

        // Only update keys that actually exist in the DB
        $existing = SystemSetting::whereIn('key', array_keys($incoming))->get()->keyBy('key');

        if ($existing->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'No valid setting keys provided.'], 422);
        }

        $errors = [];
        $updates = [];

        foreach ($existing as $key => $row) {
            $value = $incoming[$key];

            // Type validation
            $err = $this->validateType($key, $value, $row->type);
            if ($err) {
                $errors[$key] = $err;
                continue;
            }

            $updates[] = ['key' => $key, 'value' => (string) $value, 'updated_at' => now()];
        }

        if (! empty($errors)) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        DB::transaction(function () use ($updates) {
            foreach ($updates as $update) {
                SystemSetting::where('key', $update['key'])->update([
                    'value'      => $update['value'],
                    'updated_at' => $update['updated_at'],
                ]);
            }
        });

        SystemSetting::flushCache();

        return response()->json(['status' => 'success', 'message' => 'Settings updated successfully.']);
    }

    private function validateType(string $key, mixed $value, string $type): ?string
    {
        return match ($type) {
            'integer' => (! is_numeric($value))
                ? "Setting '{$key}' must be an integer."
                : null,
            'boolean' => (! in_array($value, [0, 1, '0', '1', true, false], true))
                ? "Setting '{$key}' must be boolean (0 or 1)."
                : null,
            'json'    => (is_array($value) ? null : (json_validate((string) $value)
                ? null
                : "Setting '{$key}' must be valid JSON.")),
            default   => null,
        };
    }
}
