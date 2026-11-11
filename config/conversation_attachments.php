<?php

return [
    'max_files' => 2,
    'image_max_bytes' => 20_000_000,
    'video_max_bytes' => 25_000_000,
    'video_max_duration_seconds' => 60,
    'video_max_output_bytes' => 25_000_000,
    'video_decoder_executable' => env('MOMENTO_CONVERSATION_VIDEO_DECODER_EXECUTABLE'),
    'video_decoder_timeout_seconds' => 130,
    'image_max_output_bytes' => 20_000_000,
    'max_active_operations' => 4,
    'max_active_per_sender' => 1,
    'max_retained_bytes_per_sender' => 1_000_000_000,
    'staging_recovery_after_seconds' => 300,
    'queued_recovery_after_seconds' => 1800,
    'processing_recovery_after_seconds' => 360,
    'cleanup_retry_seconds' => 60,
];
