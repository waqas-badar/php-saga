<?php

declare(strict_types=1);

namespace PhpSaga;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Serializable state container passed across saga steps.
 * Provides safe JSON encoding/decoding and access to workflow data.
 */
class SagaContext implements JsonSerializable
{
    private bool $isSuspended = false;
    private ?string $suspendReason = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data = []
    ) {}

    /**
     * Intentionally halts/suspends the saga after this step without triggering compensation.
     */
    public function suspend(?string $reason = null): self
    {
        $this->isSuspended = true;
        $this->suspendReason = $reason;

        return $this;
    }

    public function isSuspended(): bool
    {
        return $this->isSuspended;
    }

    public function getSuspendReason(): ?string
    {
        return $this->suspendReason;
    }

    public function unsuspend(): self
    {
        $this->isSuspended = false;
        $this->suspendReason = null;

        return $this;
    }

    /**
     * Retrieve a value from the context by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * Store a value in the context (mutates current instance and returns self).
     */
    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Check if a key exists in the context (even if the value is null).
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Remove a key from the context.
     */
    public function remove(string $key): self
    {
        unset($this->data[$key]);
        return $this;
    }

    /**
     * Retrieve all key-value pairs stored in the context.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Return a new immutable copy of the context with the specified key/value set.
     */
    public function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$key] = $value;
        return $clone;
    }

    /**
     * Return a new immutable copy of the context without the specified key.
     */
    public function without(string $key): self
    {
        $clone = clone $this;
        unset($clone->data[$key]);
        return $clone;
    }

    /**
     * Merge additional data into the current context.
     *
     * @param array<string, mixed>|SagaContext $data
     */
    public function merge(array|SagaContext $data): self
    {
        $incoming = $data instanceof self ? $data->all() : $data;
        $this->data = array_merge($this->data, $incoming);
        return $this;
    }

    /**
     * Return the total number of items stored in context.
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Factory method to create a context from an associative array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Factory method to deserialize context from a JSON string.
     *
     * @throws InvalidArgumentException If JSON is invalid or does not decode to an array.
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException("Failed to decode SagaContext JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('SagaContext JSON must decode to an associative array.');
        }

        return new self($decoded);
    }

    /**
     * Serialize context data to a JSON string.
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR | $flags);
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}

