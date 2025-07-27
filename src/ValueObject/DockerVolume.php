<?php

declare(strict_types=1);

namespace DockerBackup\ValueObject;

use InvalidArgumentException;

final readonly class DockerVolume
{
    public function __construct(
        public string $name,
        public string $driver = 'local',
        public string $mountpoint = '',
        public array $options = [],
        public array $labels = []
    ) {
        if (empty($this->name)) {
            throw new InvalidArgumentException('Volume name cannot be empty');
        }

        if (!$this->isValidVolumeName($this->name)) {
            throw new InvalidArgumentException(
                sprintf('Invalid volume name "%s". Volume names must be valid Docker volume names.', $this->name)
            );
        }
    }

    public static function fromArray(array $data): self
    {
        // Gestisce le labels che possono essere string o array
        $labels = $data['Labels'] ?? [];
        if (is_string($labels)) {
            // Se è una stringa, convertiamo in array associativo
            $labels = self::parseLabelsString($labels);
        } elseif (!is_array($labels)) {
            $labels = [];
        }

        // Gestisce le options che possono essere null o array
        $options = $data['Options'] ?? [];
        if (!is_array($options)) {
            $options = [];
        }

        return new self(
            name: $data['Name'] ?? '',
            driver: $data['Driver'] ?? 'local',
            mountpoint: $data['Mountpoint'] ?? '',
            options: $options,
            labels: $labels
        );
    }

    /**
     * Converte una stringa di labels in formato Docker in array associativo
     * Esempio: "key1=value1,key2=value2" -> ["key1" => "value1", "key2" => "value2"]
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
                // Label senza valore
                $labels[trim($pair)] = '';
            }
        }

        return $labels;
    }

    public function toArray(): array
    {
        return [
            'Name' => $this->name,
            'Driver' => $this->driver,
            'Mountpoint' => $this->mountpoint,
            'Options' => $this->options,
            'Labels' => $this->labels,
        ];
    }

    private function isValidVolumeName(string $name): bool
    {
        // Docker volume names: alphanumeric, hyphens, underscores, periods, no spaces
        return preg_match('/^[a-zA-Z0-9._-]+$/', $name) === 1;
    }
}