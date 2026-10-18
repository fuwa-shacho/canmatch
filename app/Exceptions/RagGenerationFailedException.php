<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class RagGenerationFailedException extends RuntimeException
{
    /**
     * @param string $reason no_evidence / rate_limit / request_failed / timeout / invalid_response /
     *                       unsupported_citation / unsupported_machine / unsupported_price / unsupported_combination
     */
    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}