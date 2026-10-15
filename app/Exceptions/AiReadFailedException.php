<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class AiReadFailedException extends RuntimeException
{
    /**
     * @param string $reason 失敗の種類(rate_limit / model_not_found / request_rejected / server_error / timeout / invalid_response)
     * @param int|null $status AIが返したHTTPステータス(接続できなかったときは null)
     */
    public function __construct(
        public readonly string $reason,
        string $message = '',
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}