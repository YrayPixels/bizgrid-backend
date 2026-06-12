<?php

namespace App\Exceptions;

use RuntimeException;

class StorefrontAiUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'Storefront AI is unavailable. Configure OPENAI_API_KEY and try again.',
    ) {
        parent::__construct($message);
    }
}
