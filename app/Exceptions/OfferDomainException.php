<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

class OfferDomainException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

