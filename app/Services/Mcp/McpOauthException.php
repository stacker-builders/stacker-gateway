<?php

namespace App\Services\Mcp;

use RuntimeException;

class McpOauthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toPayload(): array
    {
        return [
            'error' => $this->error,
            'error_description' => $this->getMessage(),
        ];
    }
}
