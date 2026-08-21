<?php
declare(strict_types=1);

namespace app\exception;

use RuntimeException;
use Throwable;

final class LlmDirectRequestException extends RuntimeException
{
    /** @param array<string,mixed> $receipt */
    public function __construct(
        string $message,
        int $code,
        private readonly array $receipt,
        ?Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string,mixed> */
    public function receipt(): array
    {
        return $this->receipt;
    }
}
