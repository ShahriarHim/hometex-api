<?php

return [
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    'delete_records_older_than_days' => 365,

    'default_log_name' => 'default',

    // Use sanctum guard so causedBy() resolves IMS staff correctly
    'default_auth_driver' => 'sanctum',

    'subject_returns_soft_deleted_models' => true,

    'activity_model' => \Spatie\Activitylog\Models\Activity::class,

    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
