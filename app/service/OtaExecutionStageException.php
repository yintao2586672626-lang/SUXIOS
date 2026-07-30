<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use Throwable;

final class OtaExecutionStageException extends RuntimeException
{
    public function __construct(
        private readonly string $stage,
        private readonly string $safeMessage,
        private readonly int $httpStatus,
        ?Throwable $previous = null
    ) {
        parent::__construct('Manual OTA execution failed at stage: ' . $stage, $httpStatus, $previous);
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
