<?php

declare(strict_types=1);

namespace OTGH\AccessControl\OpcAdapter\OpcUa;

use Closure;
use TechDock\OpcUa\Client\ClientBuilder;
use TechDock\OpcUa\Client\MonitoredItem;
use TechDock\OpcUa\Core\Types\NodeId;

class OpcUaMonitorService
{
    /**
     * @param  array<int,string>  $allowedNodeNames
     * @param  Closure(array{name:string,node_id:string,raw:mixed,display:string,timestamp:string}):void  $onValueChanged
     */
    public function monitor(
        string $endpoint,
        int $namespace,
        array $allowedNodeNames,
        float $publishingIntervalMs,
        float $samplingIntervalMs,
        Closure $onValueChanged,
        ?Closure $onTick = null,
    ): void {
        $client = ClientBuilder::create()
            ->endpoint($endpoint)
            ->withAnonymousAuth()
            ->withAutoDiscovery()
            ->build();

        try {
            $nodesToMonitor = $this->discoverNodes($client, $namespace, $allowedNodeNames);

            if ($nodesToMonitor === []) {
                return;
            }

            $subscription = $client->session->createSubscription(
                publishingInterval: $publishingIntervalMs,
            );

            $items = [];

            foreach ($nodesToMonitor as $name => $nodeId) {
                $item = MonitoredItem::forValue(
                    nodeId: $nodeId,
                    samplingInterval: $samplingIntervalMs,
                );

                $item->setNotificationCallback(function (MonitoredItem $item, $value) use ($name, $nodeId, $onValueChanged): void {
                    $raw = $value->value->value ?? null;

                    $onValueChanged([
                        'name' => $name,
                        'node_id' => $nodeId->toString(),
                        'raw' => $raw,
                        'display' => $this->displayValue($raw),
                        'timestamp' => now()->toIso8601String(),
                    ]);
                });

                $items[] = $item;
            }

            $subscription->createMonitoredItems($items);

            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(false);
            }

            while (true) {
                if ($onTick instanceof Closure) {
                    $onTick();
                }

                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                $client->session->publish();
                usleep(100000);
            }
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param  array<int,string>  $allowedNodeNames
     * @return array<string,NodeId>
     */
    public function discoverNodes(mixed $client, int $namespace, array $allowedNodeNames = []): array
    {
        $objectsFolder = NodeId::numeric(0, 85);
        $references = $client->browser->browse($objectsFolder);
        $nodesToMonitor = [];
        $allowedLookup = $this->normalizeAllowedNodeNames($allowedNodeNames);

        foreach ($references as $reference) {
            $name = (string) ($reference->displayName->text ?? '');

            if ($name === '') {
                continue;
            }

            if ($allowedLookup !== [] && ! isset($allowedLookup[strtolower($name)])) {
                continue;
            }

            $nodeId = $reference->nodeId ?? null;

            if ($nodeId instanceof NodeId) {
                $nodesToMonitor[$name] = $nodeId;

                continue;
            }

            $nodesToMonitor[$name] = NodeId::string($namespace, $name);
        }

        return $nodesToMonitor;
    }

    private function displayValue(mixed $raw): string
    {
        return match ($raw) {
            1, '1', true => 'true',
            0, '0', false => 'false',
            default => (string) $raw,
        };
    }

    /**
     * @param  array<int,string>  $allowedNodeNames
     * @return array<string,true>
     */
    private function normalizeAllowedNodeNames(array $allowedNodeNames): array
    {
        $lookup = [];

        foreach ($allowedNodeNames as $name) {
            $normalized = strtolower(trim($name));

            if ($normalized === '') {
                continue;
            }

            $lookup[$normalized] = true;
        }

        return $lookup;
    }
}
