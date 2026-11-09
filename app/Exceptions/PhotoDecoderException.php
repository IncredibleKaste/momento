<?php

namespace App\Exceptions;

use App\Enums\PhotoPostFailure;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class PhotoDecoderException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly PhotoPostFailure $failure)
    {
        parent::__construct($failure->value);
    }
}
