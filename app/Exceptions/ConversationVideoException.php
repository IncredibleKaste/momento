<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ConversationVideoException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Video processing failed.');
    }
}
