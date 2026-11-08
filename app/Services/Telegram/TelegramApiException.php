<?php

namespace App\Services\Telegram;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly bool $retryable = false,
        public readonly ?array $raw = null,
    ) {
        parent::__construct($message);
    }
}
