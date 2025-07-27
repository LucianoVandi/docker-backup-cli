<?php

declare(strict_types=1);

namespace DockerBackup\ValueObject;

use InvalidArgumentException;

final readonly class DockerImage
{
    public function __construct(
        public string $id,
        public array $repoTags = [],
        public int $size = 0,
        public int $created = 0,
        public array $labels = []
    ) {
        if (empty($this->id)) {
            throw new InvalidArgumentException('Image ID cannot be empty');
        }

        if (!$this->isValidImageId($this->id)) {
            throw new InvalidArgumentException(
                sprintf('Invalid image ID "%s". Must be a valid Docker image ID or hash.', $this->id)
            );
        }
    }

    public static function fromArray(array $data): self
    {
        // Gestisce RepoTags che possono essere null o array
        $repoTags = $data['RepoTags'] ?? [];
        if (!is_array($repoTags)) {
            $repoTags = [];
        }

        // Gestisce Labels che possono essere string, null o array
        $labels = $data['Labels'] ?? [];
        if (is_string($labels)) {
            $labels = self::parseLabelsString($labels);
        } elseif (!is_array($labels)) {
            $labels = [];
        }

        return new self(
            id: $data['Id'] ?? $data['ID'] ?? '',  // Docker può usare 'Id' o 'ID'
            repoTags: $repoTags,
            size: (int) ($data['Size'] ?? 0),
            created: (int) ($data['Created'] ?? 0),
            labels: $labels
        );
    }

    /**
     * Converte una stringa di labels in formato Docker in array associativo
     */
    private static function parseLabelsString(string $labelsString): array
    {
        if (empty($labelsString)) {
            return [];
        }

        $labels = [];
        $pairs = explode(',', $labelsString);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $labels[trim($key)] = trim($value);
            } else {
                $labels[trim($pair)] = '';
            }
        }

        return $labels;
    }

    public function toArray(): array
    {
        return [
            'Id' => $this->id,
            'RepoTags' => $this->repoTags,
            'Size' => $this->size,
            'Created' => $this->created,
            'Labels' => $this->labels,
        ];
    }

    public function getFirstTag(): ?string
    {
        return $this->repoTags[0] ?? null;
    }

    public function getShortId(): string
    {
        return substr($this->id, 0, 12);
    }

    public function getFormattedSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $this->size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $size, $units[$unitIndex]);
    }

    private function isValidImageId(string $id): bool
    {
        // Docker image ID: sha256: followed by 64 hex characters, or just 64 hex characters, or short form
        return preg_match('/^(sha256:)?[a-f0-9]{12,64}$/i', $id) === 1;
    }
}