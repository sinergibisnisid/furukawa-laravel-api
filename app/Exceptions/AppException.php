<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Domain-level error dengan HTTP status code.
 * Throw dari controller/service; global handler render lewat ApiResponse.
 */
class AppException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $status = 400,
        protected mixed $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): mixed
    {
        return $this->details;
    }

    public static function badRequest(string $message, mixed $details = null): self
    {
        return new self($message, 400, $details);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message, mixed $details = null): self
    {
        return new self($message, 409, $details);
    }

    public static function unprocessable(string $message, mixed $details = null): self
    {
        return new self($message, 422, $details);
    }

    public static function internal(string $message = 'Internal Server Error'): self
    {
        return new self($message, 500);
    }
}
