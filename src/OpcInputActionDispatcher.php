<?php

declare(strict_types=1);

namespace OTGH\AccessControl\OpcAdapter;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OTGH\AccessControl\Core\Enums\AccessControl\AccessBindingActionKey;
use OTGH\AccessControl\Core\Jobs\ProcessReaderEvent;
use OTGH\AccessControl\Core\Models\Access\Area;
use OTGH\AccessControl\Core\Models\Access\Event;
use OTGH\AccessControl\Core\Models\Hardware\AdapterBinding;
use OTGH\AccessControl\Core\Models\Hardware\Lock;
use OTGH\AccessControl\Core\Models\Hardware\Reader;
use OTGH\AccessControl\Core\Models\Hardware\Sensor;
use OTGH\AccessControl\Core\Models\Hardware\Source;
use OTGH\AccessControl\Core\Services\AccessControlMqttPublisher;
use OTGH\AccessControl\Core\Support\SignalValueMapper;

class OpcInputActionDispatcher
{
    /**
     * @param  array{name:string,node_id:string,raw:mixed,display:string,timestamp:string}  $event
     */
    public function handleOpcValueChanged(Source $source, array $event): void
    {
        $bindings = AdapterBinding::query()
            ->where('source_id', $source->id)
            ->where('direction', 'input')
            ->where('enabled', true)
            ->whereIn('adapter_type', ['opc', 'opcua', 'opc_ua'])
            ->whereIn('action_key', AccessBindingActionKey::queryCandidatesFor([
                AccessBindingActionKey::EXIT_REQUEST,
                AccessBindingActionKey::EMERGENCY_EXIT_REQUEST,
                AccessBindingActionKey::SENSOR_STATE,
            ]))
            ->get();

        if ($bindings->isEmpty()) {
            return;
        }

        $eventName = $this->normalizeToken($event['name'] ?? null);
        $eventNodeId = $this->normalizeToken($event['node_id'] ?? null);

        foreach ($bindings as $binding) {
            $channel = $this->normalizeToken($binding->channel);

            if ($channel === null) {
                continue;
            }

            if ($channel !== $eventName && $channel !== $eventNodeId) {
                continue;
            }

            $isActive = SignalValueMapper::toCanonicalBool($event['raw'] ?? null, (bool) $binding->signal_reversed);

            Cache::put(self::lastSeenCacheKey((int) $binding->id), [
                'seen_at' => now()->toIso8601String(),
                'source_id' => (int) $source->id,
                'source_identifier' => $source->identifier,
                'binding_id' => (int) $binding->id,
                'channel' => $binding->channel,
                'node_name' => $event['name'] ?? null,
                'node_id' => $event['node_id'] ?? null,
                'raw' => $event['raw'] ?? null,
                'display' => $event['display'] ?? null,
                'active' => $isActive,
            ], now()->addDay());

            if ($isActive === null) {
                continue;
            }

            $resolvedAction = AccessBindingActionKey::fromStored($binding->action_key);

            if ($resolvedAction === AccessBindingActionKey::SENSOR_STATE) {
                $sensor = $this->resolveSensorForBinding($binding);
                if (! $sensor instanceof Sensor) {
                    Log::warning('opc.input.sensor_state.sensor_not_resolved', [
                        'binding_id' => $binding->id,
                        'target_type' => $binding->target_type,
                        'target_id' => $binding->target_id,
                        'source_id' => $source->id,
                        'channel' => $binding->channel,
                    ]);

                    continue;
                }

                $state = (bool) $isActive;
                if ((bool) $sensor->state !== $state) {
                    $sensor->forceFill(['state' => $state])->save();
                }

                app(AccessControlMqttPublisher::class)->publishSensorState($sensor->fresh());

                Log::info('opc.input.sensor_state.updated', [
                    'source_id' => $source->id,
                    'binding_id' => $binding->id,
                    'sensor_id' => $sensor->id,
                    'sensor_identifier' => $sensor->identifier,
                    'state' => $state,
                    'channel' => $binding->channel,
                    'node_name' => $event['name'] ?? null,
                    'node_id' => $event['node_id'] ?? null,
                    'raw' => $event['raw'] ?? null,
                ]);

                continue;
            }

            if (! $this->isRisingEdge($binding->id, $isActive)) {
                continue;
            }

            $reader = $this->resolveReaderForBinding($binding);

            if (! $reader instanceof Reader) {
                Log::warning('opc.input.unlock_request.reader_not_resolved', [
                    'binding_id' => $binding->id,
                    'target_type' => $binding->target_type,
                    'target_id' => $binding->target_id,
                    'source_id' => $source->id,
                    'channel' => $binding->channel,
                ]);

                continue;
            }

            $isEmergency = $resolvedAction === AccessBindingActionKey::EMERGENCY_EXIT_REQUEST;
            $status = $isEmergency ? 'emergency_exit_request_detected' : 'exit_request_detected';
            $reason = $isEmergency
                ? 'Emergency exit request input activated.'
                : 'Exit request input activated.';
            $eventName = $isEmergency ? 'emergency_exit_request_detected' : 'exit_request_detected';

            Event::create([
                'access_card_id' => null,
                'access_area_id' => $reader->area_id,
                'access_lock_id' => $reader->area?->primaryLock()?->id,
                'access_source_id' => $source->id,
                'user_id' => null,
                'card_number' => null,
                'origin_type' => 'reader',
                'origin_id' => $reader->id,
                'origin_label' => $reader->name,
                'granted' => true,
                'status' => $status,
                'reason' => $reason,
                'metadata' => [
                    'source' => 'opc_input',
                    'event' => $eventName,
                    'adapter_type' => (string) $binding->adapter_type,
                    'action_key' => $resolvedAction?->key() ?? (string) $binding->action_key,
                    'binding_id' => (int) $binding->id,
                    'source_id' => (int) $source->id,
                    'target_type' => (string) $binding->target_type,
                    'target_id' => (int) $binding->target_id,
                    'channel' => $binding->channel,
                    'node_name' => $event['name'] ?? null,
                    'node_id' => $event['node_id'] ?? null,
                    'raw' => $event['raw'] ?? null,
                    'signal_reversed' => (bool) $binding->signal_reversed,
                ],
                'ip_address' => null,
            ]);

            ProcessReaderEvent::dispatch(null, $reader, 0, ! $isEmergency, 'opc_input');

            Cache::put(self::lastDispatchAtCacheKey((int) $binding->id), now()->toIso8601String(), now()->addDay());
            Cache::increment(self::dispatchCountCacheKey((int) $binding->id));
            Cache::put(self::dispatchCountCacheKey((int) $binding->id), (int) Cache::get(self::dispatchCountCacheKey((int) $binding->id), 0), now()->addDays(7));

            Log::info('opc.input.request.dispatched', [
                'source_id' => $source->id,
                'source_identifier' => $source->identifier,
                'binding_id' => $binding->id,
                'reader_id' => $reader->id,
                'reader_identifier' => $reader->identifier,
                'action_key' => $resolvedAction?->key() ?? (string) $binding->action_key,
                'emergency' => $isEmergency,
                'channel' => $binding->channel,
                'node_name' => $event['name'] ?? null,
                'node_id' => $event['node_id'] ?? null,
                'raw' => $event['raw'] ?? null,
            ]);
        }
    }

    private function isRisingEdge(int $bindingId, bool $current): bool
    {
        $cacheKey = self::edgeStateCacheKey($bindingId);

        if (! Cache::has($cacheKey)) {
            Cache::put($cacheKey, $current, now()->addHours(6));

            return false;
        }

        $previous = (bool) Cache::get($cacheKey, false);
        Cache::put($cacheKey, $current, now()->addHours(6));

        return $current && ! $previous;
    }

    public static function edgeStateCacheKey(int $bindingId): string
    {
        return 'access_control:opc_input_binding:'.$bindingId.':active';
    }

    public static function lastSeenCacheKey(int $bindingId): string
    {
        return 'access_control:opc_input_binding:'.$bindingId.':last_seen';
    }

    public static function lastDispatchAtCacheKey(int $bindingId): string
    {
        return 'access_control:opc_input_binding:'.$bindingId.':last_dispatch_at';
    }

    public static function dispatchCountCacheKey(int $bindingId): string
    {
        return 'access_control:opc_input_binding:'.$bindingId.':dispatch_count';
    }

    private function resolveReaderForBinding(AdapterBinding $binding): ?Reader
    {
        return match ($binding->target_type) {
            'reader' => Reader::query()->find($binding->target_id),
            'lock' => $this->resolveReaderFromLock((int) $binding->target_id),
            'area' => $this->resolveReaderFromRoom((int) $binding->target_id),
            default => null,
        };
    }

    private function resolveSensorForBinding(AdapterBinding $binding): ?Sensor
    {
        if ($binding->target_type !== 'sensor') {
            return null;
        }

        return Sensor::query()->find($binding->target_id);
    }

    private function resolveReaderFromLock(int $lockId): ?Reader
    {
        $lock = Lock::query()->find($lockId);

        if (! $lock instanceof Lock) {
            return null;
        }

        return $this->resolveReaderFromRoom((int) $lock->area_id);
    }

    private function resolveReaderFromRoom(int $roomId): ?Reader
    {
        $area = Area::query()->find($roomId);

        if (! $area instanceof Area) {
            return null;
        }

        return $area->readers()->orderBy('id')->first();
    }

    private function normalizeToken(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
