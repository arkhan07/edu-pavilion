<?php

namespace App\Services\Espay;

use RuntimeException;

/**
 * Error dengan format response code SNAP: HTTP Code + Service Code + Case Code (contoh 4042412).
 */
class EspayException extends RuntimeException
{
    public int $httpCode;
    public string $responseCode;

    public function __construct(int $httpCode, string $serviceCode, string $caseCode, string $message)
    {
        parent::__construct($message);

        $this->httpCode = $httpCode;
        $this->responseCode = $httpCode . $serviceCode . $caseCode;
    }
}
