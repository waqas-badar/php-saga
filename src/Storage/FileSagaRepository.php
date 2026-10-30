<?php

declare(strict_types=1);

namespace PhpSaga\Storage;

use InvalidArgumentException;
use PhpSaga\Contracts\SagaRepositoryInterface;
use PhpSaga\SagaContext;
use PhpSaga\SagaState;
use PhpSaga\SagaStatus;

/**
 * File-based repository for persisting saga states as JSON files on disk.
 * Ideal for local development, CLI applications, or environments without a relational database.
 */
class FileSagaRepository implements SagaRepositoryInterface
{
    public function __construct(
        private readonly string $storageDirectory
    ) {
        if (!is_dir($this->storageDirectory)) {
            if (!@mkdir($this->storageDirectory, 0777, true) && !is_dir($this->storageDirectory)) {
                throw new InvalidArgumentException(
                    "Unable to create storage directory: '{$this->storageDirectory}'"
                );
            }
        }
    }

    public function save(SagaState $state): void
    {
        $filePath = $this->getFilePath($state->sagaId);
        $tempPath = $filePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($state->jsonSerialize(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
            throw new InvalidArgumentException("Failed to write saga file: '{$tempPath}'");
        }

        rename($tempPath, $filePath);
    }

    public function find(string $sagaId): ?SagaState
    {
        $filePath = $this->getFilePath($sagaId);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return null;
            }

            return SagaState::fromArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    public function exists(string $sagaId): bool
    {
        return file_exists($this->getFilePath($sagaId));
    }

    public function updateStatus(
        string $sagaId,
        SagaStatus $status,
        ?string $currentStep = null,
        ?string $exitReason = null
    ): void {
        $state = $this->find($sagaId);
        if ($state !== null) {
            $this->save($state->withStatus($status, $currentStep, $exitReason));
        }
    }

    public function updateContext(string $sagaId, SagaContext $context): void
    {
        $state = $this->find($sagaId);
        if ($state !== null) {
            $this->save($state->withContext($context));
        }
    }

    public function delete(string $sagaId): void
    {
        $filePath = $this->getFilePath($sagaId);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Clears all saga files in the storage directory.
     */
    public function clear(): void
    {
        $files = glob(rtrim($this->storageDirectory, '/\\') . DIRECTORY_SEPARATOR . 'saga_*.json');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Returns total count of persisted saga files.
     */
    public function count(): int
    {
        $files = glob(rtrim($this->storageDirectory, '/\\') . DIRECTORY_SEPARATOR . 'saga_*.json');

        return is_array($files) ? count($files) : 0;
    }

    /**
     * Resolves a sanitized file path for the given saga ID.
     */
    private function getFilePath(string $sagaId): string
    {
        $safeId = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sagaId);

        return rtrim($this->storageDirectory, '/\\') . DIRECTORY_SEPARATOR . "saga_{$safeId}.json";
    }
}

