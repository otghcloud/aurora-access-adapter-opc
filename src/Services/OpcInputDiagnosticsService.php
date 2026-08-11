<?php

namespace OTGH\AccessControl\OpcAdapter\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use OTGH\AccessControl\Core\Enums\AccessControl\AccessBindingActionKey;
use OTGH\AccessControl\Core\Models\Access\Area;
use OTGH\AccessControl\Core\Models\Hardware\AdapterBinding;
use OTGH\AccessControl\Core\Models\Hardware\Lock;
use OTGH\AccessControl\Core\Models\Hardware\Reader;
use OTGH\AccessControl\Core\Models\Hardware\Sensor;
use OTGH\AccessControl\Core\Models\Hardware\Source;
use OTGH\AccessControl\Core\Services\AccessControl\AccessControlSettingsRepository;
use OTGH\AccessControl\OpcAdapter\Jobs\MonitorOpcSourceJob;
use OTGH\AccessControl\OpcAdapter\OpcInputActionDispatcher;
use OTGH\AccessControl\OpcAdapter\OpcUa\OpcUaSourceConfigResolver;
use TechDock\OpcUa\Client\ClientBuilder;
use TechDock\OpcUa\Core\Types\NodeId;
use Throwable;

class OpcInputDiagnosticsService
{
    public function __construct(
        private readonly OpcUaSourceConfigResolver $sourceConfigResolver,
        private readonly AccessControlSettingsRepository $settings,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildPayload(): array
    {
        $sources = Source::query()
            ->where('enabled', true)
            ->whereIn('type', ['opc', 'opcua', 'opc_ua'])
            ->orderBy('id')
            ->get();

        $discoveryBySourceId = [];

        $sourceRows = $sources->map(function (Source $source) use (&$discoveryBySourceId): array {
            $heartbeatKey = MonitorOpcSourceJob::heartbeatCacheKey((int) $source->id);
            $startedAtKey = MonitorOpcSourceJob::startedAtCacheKey((int) $source->id);
            $heartbeat = Cache::get($heartbeatKey);
            $startedAt = Cache::get($startedAtKey);
            $heartbeatAgeSeconds = $this->heartbeatAgeSeconds($heartbeat);
            $runningLive = is_int($heartbeatAgeSeconds) ? $heartbeatAgeSeconds <= 15 : false;
            $recycleProjection = $this->recycleProjection($startedAt, Cache::has($heartbeatKey));
            $discovery = $this->discoverOpcNodes($source);
            $discoveryBySourceId[(int) $source->id] = $discovery;

            return [
                'id' => (int) $source->id,
                'name' => $source->name,
                'identifier' => $source->identifier,
                'type' => $source->type,
                'endpoint' => $source->endpoint,
                'running' => Cache::has($heartbeatKey),
                'running_live' => $runningLive,
                'heartbeat' => $heartbeat,
                'heartbeat_age_seconds' => $heartbeatAgeSeconds,
                'started_at' => $startedAt,
                'recycle_runtime_seconds' => $this->settings->getInt('opc_monitor_max_runtime_seconds', 900),
                'next_recycle_at' => $recycleProjection['next_recycle_at'],
                'seconds_until_recycle' => $recycleProjection['seconds_until_recycle'],
                'discovered_nodes_count' => (int) ($discovery['count'] ?? 0),
                'discovery_error' => $discovery['error'] ?? null,
            ];
        })->values()->all();

        $bindings = AdapterBinding::query()
            ->with('source')
            ->where('direction', 'input')
            ->whereIn('adapter_type', ['opc', 'opcua', 'opc_ua'])
            ->orderBy('id')
            ->get();

        $bindingRows = $bindings->map(function (AdapterBinding $binding) use ($discoveryBySourceId): array {
            $lastSeen = Cache::get(OpcInputActionDispatcher::lastSeenCacheKey((int) $binding->id));
            $sourceId = (int) ($binding->source_id ?? 0);
            $channel = trim((string) ($binding->channel ?? ''));
            $normalizedChannel = strtolower($channel);
            $discovery = $discoveryBySourceId[$sourceId] ?? null;
            $channelDiscovered = null;

            if ($channel !== '' && is_array($discovery) && ($discovery['ok'] ?? false) === true) {
                $channelDiscovered = isset($discovery['lookup'][$normalizedChannel]);
            }

            $actionKey = AccessBindingActionKey::fromStored($binding->action_key);
            $edgeActive = Cache::get(OpcInputActionDispatcher::edgeStateCacheKey((int) $binding->id));
            $lastSeenActive = is_array($lastSeen) ? ($lastSeen['active'] ?? null) : null;
            $effectiveActive = is_bool($lastSeenActive)
                ? $lastSeenActive
                : (is_bool($edgeActive) ? $edgeActive : null);
            $stateSource = is_bool($lastSeenActive)
                ? 'live'
                : (is_bool($edgeActive) ? 'edge' : null);

            return [
                'id' => (int) $binding->id,
                'enabled' => (bool) $binding->enabled,
                'action_key' => $actionKey?->key() ?? (string) $binding->action_key,
                'action_key_id' => $actionKey?->value,
                'action_key_label' => $actionKey?->label(),
                'adapter_type' => $binding->adapter_type,
                'channel' => $channel,
                'signal_reversed' => (bool) $binding->signal_reversed,
                'source_id' => $sourceId,
                'source_identifier' => $binding->source?->identifier,
                'target_type' => $binding->target_type,
                'target_id' => (int) $binding->target_id,
                'target_label' => $this->resolveTargetLabel($binding->target_type, (int) $binding->target_id),
                'edge_active' => $edgeActive,
                'effective_active' => $effectiveActive,
                'state_source' => $stateSource,
                'last_seen' => is_array($lastSeen) ? $lastSeen : null,
                'last_dispatch_at' => Cache::get(OpcInputActionDispatcher::lastDispatchAtCacheKey((int) $binding->id)),
                'dispatch_count' => (int) Cache::get(OpcInputActionDispatcher::dispatchCountCacheKey((int) $binding->id), 0),
                'channel_discovered' => $channelDiscovered,
                'channel_discovery_error' => is_array($discovery) ? ($discovery['error'] ?? null) : null,
            ];
        })->values()->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'opc_worker_processes' => $this->countProcesses('queue:work redis-opc-monitor --queue=access-opc-monitor'),
            'opc_monitor_command_processes' => $this->countProcesses('artisan app:monitor-opc-source'),
            'active_monitors' => collect($sourceRows)->filter(fn (array $row): bool => (bool) ($row['running_live'] ?? false))->count(),
            'sources' => $sourceRows,
            'bindings' => $bindingRows,
        ];
    }

    /**
     * @return array{ok:bool,count:int,lookup:array<string,true>,error:?string}
     */
    private function discoverOpcNodes(Source $source): array
    {
        $cacheKey = 'access_control:opc_discovery:source:'.$source->id;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return [
                'ok' => (bool) ($cached['ok'] ?? false),
                'count' => (int) ($cached['count'] ?? 0),
                'lookup' => is_array($cached['lookup'] ?? null) ? $cached['lookup'] : [],
                'error' => is_string($cached['error'] ?? null) ? $cached['error'] : null,
            ];
        }

        try {
            $resolved = $this->sourceConfigResolver->resolveFromSource($source);

            $client = ClientBuilder::create()
                ->endpoint($resolved['endpoint'])
                ->withAnonymousAuth()
                ->withAutoDiscovery()
                ->build();

            try {
                $references = $client->browser->browse(NodeId::numeric(0, 85));
                $lookup = [];

                foreach ($references as $reference) {
                    $name = strtolower(trim((string) ($reference->displayName->text ?? '')));

                    if ($name === '') {
                        continue;
                    }

                    $lookup[$name] = true;
                }
            } finally {
                $client->disconnect();
            }

            $payload = [
                'ok' => true,
                'count' => count($lookup),
                'lookup' => $lookup,
                'error' => null,
            ];

            Cache::put($cacheKey, $payload, now()->addSeconds(30));

            return $payload;
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'count' => 0,
                'lookup' => [],
                'error' => $e->getMessage(),
            ];

            Cache::put($cacheKey, $payload, now()->addSeconds(15));

            return $payload;
        }
    }

    private function heartbeatAgeSeconds(mixed $heartbeat): ?int
    {
        if (! is_string($heartbeat) || trim($heartbeat) === '') {
            return null;
        }

        try {
            return abs(Carbon::parse($heartbeat)->diffInSeconds(now()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{next_recycle_at:?string,seconds_until_recycle:?int}
     */
    private function recycleProjection(mixed $startedAt, bool $running): array
    {
        if (! $running) {
            return [
                'next_recycle_at' => null,
                'seconds_until_recycle' => null,
            ];
        }

        if (! is_string($startedAt) || trim($startedAt) === '') {
            return [
                'next_recycle_at' => null,
                'seconds_until_recycle' => null,
            ];
        }

        try {
            $startedAtTs = Carbon::parse($startedAt);
            $maxRuntimeSeconds = max(60, $this->settings->getInt('opc_monitor_max_runtime_seconds', 900));
            $nextRecycleAt = $startedAtTs->copy()->addSeconds($maxRuntimeSeconds);

            return [
                'next_recycle_at' => $nextRecycleAt->toIso8601String(),
                'seconds_until_recycle' => (int) now()->diffInSeconds($nextRecycleAt, false),
            ];
        } catch (Throwable) {
            return [
                'next_recycle_at' => null,
                'seconds_until_recycle' => null,
            ];
        }
    }

    private function resolveTargetLabel(string $targetType, int $targetId): ?string
    {
        return match ($targetType) {
            'reader' => $this->readerLabel($targetId),
            'lock' => $this->lockLabel($targetId),
            'area' => $this->roomLabel($targetId),
            'sensor' => $this->sensorLabel($targetId),
            default => null,
        };
    }

    private function sensorLabel(int $sensorId): ?string
    {
        $sensor = Sensor::query()->find($sensorId);

        if (! $sensor instanceof Sensor) {
            return null;
        }

        return $sensor->name.' ('.$sensor->identifier.')';
    }

    private function readerLabel(int $readerId): ?string
    {
        $reader = Reader::query()->find($readerId);

        if (! $reader instanceof Reader) {
            return null;
        }

        return $reader->name.' ('.$reader->identifier.')';
    }

    private function lockLabel(int $lockId): ?string
    {
        $lock = Lock::query()->find($lockId);

        if (! $lock instanceof Lock) {
            return null;
        }

        return $lock->name.' ('.$lock->identifier.')';
    }

    private function roomLabel(int $roomId): ?string
    {
        $area = Area::query()->find($roomId);

        if (! $area instanceof Area) {
            return null;
        }

        return $area->name.' ('.$area->identifier.')';
    }

    private function countProcesses(string $needle): ?int
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $cmd = "ps -ww -eo args | grep -F '".str_replace("'", "'\\''", $needle)."' | grep -v grep | wc -l";
        $out = shell_exec($cmd);

        if (! is_string($out)) {
            return null;
        }

        $count = (int) trim($out);

        return max(0, $count);
    }
}
