<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

use Cbox\Billing\ApiException;
use Psr\Http\Message\ResponseInterface;

/**
 * A non-2xx response, built from the API's `{error, message}` body. Extends the generated
 * ApiException, so code that catches that keeps working.
 *
 * `getError()` is the body's `error` field verbatim: a sentence, or a code such as
 * `insufficient_scope` or `idempotency_conflict`. The exception message is the body's `message`,
 * falling back to `error`.
 */
class CboxBillingException extends ApiException
{
    /** @var array<string, mixed>|null */
    private ?array $payload;

    /**
     * @param  array<string, list<string>>  $headers
     */
    final public function __construct(
        private readonly int $status,
        array $headers,
        private readonly string $rawBody,
    ) {
        $decoded = json_decode($rawBody, true);
        $this->payload = is_array($decoded) ? $decoded : null;

        $message = $this->stringField('message') ?? $this->stringField('error') ?? 'HTTP '.$status;

        parent::__construct($message, $status, $headers, $rawBody);
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $status = $response->getStatusCode();

        $class = match (true) {
            $status === 400 => BadRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 422 => ValidationException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class($status, $response->getHeaders(), (string) $response->getBody());
    }

    /** The HTTP status. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** The body's `error` field, verbatim. */
    public function getError(): ?string
    {
        return $this->stringField('error');
    }

    /** The body's `code` field, on refusals that name one. */
    public function getErrorCode(): ?string
    {
        return $this->stringField('code');
    }

    /** With `insufficient_scope`: the scope the operation needs. */
    public function getRequiredScope(): ?string
    {
        return $this->stringField('required_scope');
    }

    /** @return array<string, mixed>|null The parsed JSON body, or null when it was not JSON. */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    private function stringField(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
