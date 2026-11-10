<?php

namespace App\Enums;

enum PhotoPostFailure: string
{
    case InvalidPhoto = 'invalid_photo';
    case DecoderUnavailable = 'decoder_unavailable';
    case ProcessingFailed = 'processing_failed';
    case StorageUnavailable = 'storage_unavailable';
    case CapacityReached = 'capacity_reached';

    public function message(): string
    {
        return match ($this) {
            self::InvalidPhoto => 'This photo could not be processed. Choose a supported still image and try again.',
            self::DecoderUnavailable => 'Photo processing is temporarily unavailable. Try again later.',
            self::ProcessingFailed => 'The photo could not be published. Try again later.',
            self::StorageUnavailable => 'Photo storage is temporarily unavailable. Try again later.',
            self::CapacityReached => 'Photo processing is busy. Try again later.',
        };
    }
}
