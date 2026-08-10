<?php

declare(strict_types=1);

namespace OTGH\AccessControl\OpcAdapter\OpcUa;

use OTGH\AccessControl\Core\Models\Hardware\Source;
use RuntimeException;

class OpcUaSourceConfigResolver
{
    /**
     * @return array{source:Source,endpoint:string,namespace:int,publishing_interval_ms:float,sampling_interval_ms:float,nodes:array<int,string>}
     */
    public function resolveById(int $sourceId): array
    {
        $source = Source::query()->find($sourceId);

        if (! $source instanceof Source) {
            throw new RuntimeException('OPC source not found for id ['.$sourceId.'].');
        }

        return $this->resolveFromSource($source);
    }

    /**
     * @return array{source:Source,endpoint:string,namespace:int,publishing_interval_ms:float,sampling_interval_ms:float,nodes:array<int,string>}
     */
    public function resolveFromSource(Source $source): array
    {
        $sourceType = strtolower(trim((string) $source->type));

        if (! in_array($sourceType, ['opc', 'opcua', 'opc_ua'], true)) {
            throw new RuntimeException('Source ['.$source->identifier.'] is not an OPC source type.');
        }

        if (! $source->enabled) {
            throw new RuntimeException('Source ['.$source->identifier.'] is disabled.');
        }

        $config = is_array($source->config) ? $source->config : [];
        $endpoint = $this->normalizedString($source->endpoint)
            ?? $this->normalizedString(data_get($config, 'opc_ua.endpoint'))
            ?? $this->normalizedString(data_get($config, 'endpoint'))
            ?? '';

        if ($endpoint === '') {
            throw new RuntimeException('Source ['.$source->identifier.'] has no OPC endpoint configured.');
        }

        $namespace = max(0, (int) (data_get($config, 'opc_ua.namespace') ?? data_get($config, 'namespace', 2)));
        $publishingInterval = max(50.0, (float) (data_get($config, 'opc_ua.publishing_interval_ms') ?? data_get($config, 'publishing_interval_ms', 1000)));
        $samplingInterval = max(50.0, (float) (data_get($config, 'opc_ua.sampling_interval_ms') ?? data_get($config, 'sampling_interval_ms', 1000)));
        $nodes = $this->resolveNodes($config);

        return [
            'source' => $source,
            'endpoint' => $endpoint,
            'namespace' => $namespace,
            'publishing_interval_ms' => $publishingInterval,
            'sampling_interval_ms' => $samplingInterval,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<int,string>
     */
    private function resolveNodes(array $config): array
    {
        $opcNodes = data_get($config, 'opc_ua.nodes');

        if (! is_array($opcNodes)) {
            $opcNodes = data_get($config, 'nodes');
        }

        if (! is_array($opcNodes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $value): string => trim((string) $value),
            $opcNodes,
        ), fn (string $value): bool => $value !== ''));
    }

    private function normalizedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
