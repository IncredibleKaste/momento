<?php

return [
    'decoder_executable' => env('MOMENTO_PHOTO_DECODER_EXECUTABLE'),
    'max_input_bytes' => 20_000_000,
    'max_input_pixels' => 50_000_000,
    'max_output_bytes' => 20_000_000,
    'max_processed_bytes_per_user' => 5_000_000_000,
    'max_outstanding_operations' => 4,
    'max_staging_bytes' => 100_000_000,
    'max_caption_characters' => 1000,
    'max_location_characters' => 100,
    'decoder_timeout_seconds' => 130,
    'processing_recovery_after_seconds' => 180,
];
