<?php

namespace OTGH\AccessControl\OpcAdapter\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OTGH\AccessControl\Core\Services\AccessControl\AccessControlSettingsRepository;
use OTGH\AccessControl\OpcAdapter\OpcInputActionDispatcher;
use OTGH\AccessControl\OpcAdapter\OpcUa\OpcUaMonitorService;
use OTGH\AccessControl\OpcAdapter\OpcUa\OpcUaSourceConfigResolver;
use RuntimeException;
use Throwable;

class MonitorOpcSourceJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public int $sourceId)
    {
        $this->onConnection('redis-opc-monitor');
        $this->onQueue('access-opc-monitor');
    }

    public function handle(
        OpcUaSourceConfigResolver $configResolver,
        OpcUaMonitorService $monitorService,
        OpcInputActionDispatcher $inputActionDispatcher,
        AccessControlSettingsRepository $settings,
    ): void {
        $monitorConfig = $configResolver->resolveById($this->sourceId);
        $source = $monitorConfig['source'];
        $heartbeatKey = self::heartbeatCacheKey((int) $source->id);
        $startedAtKey = self::startedAtCacheKey((int) $source->id);
        $startedAt = now();
        $maxRuntimeSeconds = max(60, $settings->getInt('opc_monitor_max_runtime_seconds', 900));

        Log::info('opc.monitor.job.start', [
            'source_id' => $source->id,
            'source_identifier' => $source->identifier,
            'endpoint' => $monitorConfig['endpoint'],
            'namespace' => $monitorConfig['namespace'],
            'nodes_count' => count($monitorConfig['nodes']),
        ]);

        Cache::put($heartbeatKey, now()->toIso8601String(), now()->addMinutes(2));
        Cache::put($startedAtKey, $startedAt->toIso8601String(), now()->addSeconds($maxRuntimeSeconds + 120));

        try {
            $monitorService->monitor(
                endpoint: $monitorConfig['endpoint'],
                namespace: $monitorConfig['namespace'],
                allowedNodeNames: $monitorConfig['nodes'],
                publishingIntervalMs: $monitorConfig['publishing_interval_ms'],
                samplingIntervalMs: $monitorConfig['sampling_interval_ms'],
                onValueChanged: function (array $event) use ($source, $heartbeatKey, $inputActionDispatcher): void {
                    Cache::put($heartbeatKey, now()->toIso8601String(), now()->addMinutes(2));

                    $inputActionDispatcher->handleOpcValueChanged($source, $event);

                    Log::info('opc.monitor.value_changed', [
                        'source_id' => $source->id,
                        'source_identifier' => $source->identifier,
                        'node_name' => $event['name'] ?? null,
                        'node_id' => $event['node_id'] ?? null,
                        'raw' => $event['raw'] ?? null,
                        'display' => $event['display'] ?? null,
                    ]);
                },
                onTick: function () use ($heartbeatKey, $startedAt, $maxRuntimeSeconds): void {
                    Cache::put($heartbeatKey, now()->toIso8601String(), now()->addMinutes(2));

                    if ($startedAt->diffInSeconds(now()) >= $maxRuntimeSeconds) {
                        throw new RuntimeException('Recycling OPC monitor after maximum runtime.');
                    }
                },
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Recycling OPC monitor after maximum runtime.') {
                throw $exception;
            }

            self::dispatch((int) $source->id)
                ->delay(now()->addSecond());

            Log::info('opc.monitor.job.recycle', [
                'source_id' => $source->id,
                'source_identifier' => $source->identifier,
                'max_runtime_seconds' => $maxRuntimeSeconds,
                'replacement_dispatched' => true,
            ]);
        } finally {
            Cache::forget($heartbeatKey);
        }
    }

    public function failed(Throwable $exception): void
    {
        Cache::forget(self::heartbeatCacheKey($this->sourceId));

        Log::warning('opc.monitor.job.failed', [
            'source_id' => $this->sourceId,
            'error' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }

    public static function heartbeatCacheKey(int $sourceId): string
    {
        return 'access_control:opc_monitor:source:'.$sourceId.':heartbeat';
    }

    public static function startedAtCacheKey(int $sourceId): string
    {
        return 'access_control:opc_monitor:source:'.$sourceId.':started_at';
    }
}
