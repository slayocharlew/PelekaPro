<?php

namespace App\Exceptions;

use RuntimeException;

class DeliveryWorkflowException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 409)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
