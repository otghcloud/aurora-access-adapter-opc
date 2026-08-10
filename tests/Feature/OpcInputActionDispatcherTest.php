<?php

use App\Enums\AccessControl\AccessBindingActionKey;
use App\Jobs\ProcessReaderEvent;
use App\Models\Access\Area;
use App\Models\Hardware\AdapterBinding;
use App\Models\Hardware\Reader;
use App\Models\Hardware\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use OTGH\AccessControl\OpcAdapter\OpcInputActionDispatcher;

uses(RefreshDatabase::class);

it('dispatches unlock request on rising opc input edge', function () {
    Queue::fake();
    Cache::flush();

    $source = Source::create([
        'name' => 'ADAM OPC',
        'identifier' => 'adam-opc',
        'type' => 'opcua',
        'endpoint' => 'opc.tcp://10.0.0.10:4840',
        'enabled' => true,
        'config' => [
            'opc_ua' => [
                'endpoint' => 'opc.tcp://10.0.0.10:4840',
                'namespace' => 2,
                'nodes' => ['TestUserTagOne'],
            ],
        ],
        'metadata' => [],
    ]);

    $area = Area::create([
        'name' => 'Front Door Area',
        'identifier' => 'front-door-area',
        'metadata' => [],
    ]);

    $reader = Reader::create([
        'name' => 'Front Door Reader',
        'identifier' => 'front-door-reader',
        'area_id' => $area->id,
        'config' => [],
        'metadata' => [],
    ]);

    AdapterBinding::create([
        'source_id' => $source->id,
        'direction' => 'input',
        'adapter_type' => 'opcua',
        'target_type' => 'reader',
        'target_id' => $reader->id,
        'action_key' => AccessBindingActionKey::EXIT_REQUEST->value,
        'channel' => 'TestUserTagOne',
        'signal_reversed' => false,
        'enabled' => true,
        'config' => [],
        'metadata' => [],
    ]);

    $dispatcher = app(OpcInputActionDispatcher::class);

    // Prime edge state as inactive.
    $dispatcher->handleOpcValueChanged($source, [
        'name' => 'TestUserTagOne',
        'node_id' => 'ns=2;s=TestUserTagOne',
        'raw' => 0,
        'display' => 'false',
        'timestamp' => now()->toIso8601String(),
    ]);

    // Rising edge should dispatch unlock once.
    $dispatcher->handleOpcValueChanged($source, [
        'name' => 'TestUserTagOne',
        'node_id' => 'ns=2;s=TestUserTagOne',
        'raw' => 1,
        'display' => 'true',
        'timestamp' => now()->toIso8601String(),
    ]);

    // Held high should not re-dispatch.
    $dispatcher->handleOpcValueChanged($source, [
        'name' => 'TestUserTagOne',
        'node_id' => 'ns=2;s=TestUserTagOne',
        'raw' => 1,
        'display' => 'true',
        'timestamp' => now()->toIso8601String(),
    ]);

    Queue::assertPushed(ProcessReaderEvent::class, function (ProcessReaderEvent $job) use ($reader): bool {
        return $job->accessReader->id === $reader->id
            && $job->targetValue === 0
            && $job->allowAutoRelock === true
            && $job->eventSource === 'opc_input';
    });

    Queue::assertPushed(ProcessReaderEvent::class, 1);
});

it('dispatches emergency exit request without auto-relock on rising opc input edge', function () {
    Queue::fake();
    Cache::flush();

    $source = Source::create([
        'name' => 'ADAM OPC Emergency',
        'identifier' => 'adam-opc-emergency',
        'type' => 'opcua',
        'endpoint' => 'opc.tcp://10.0.0.11:4840',
        'enabled' => true,
        'config' => [
            'opc_ua' => [
                'endpoint' => 'opc.tcp://10.0.0.11:4840',
                'namespace' => 2,
                'nodes' => ['EmergencyExitTag'],
            ],
        ],
        'metadata' => [],
    ]);

    $area = Area::create([
        'name' => 'Emergency Area',
        'identifier' => 'emergency-area',
        'metadata' => [],
    ]);

    $reader = Reader::create([
        'name' => 'Emergency Reader',
        'identifier' => 'emergency-reader',
        'area_id' => $area->id,
        'config' => [],
        'metadata' => [],
    ]);

    AdapterBinding::create([
        'source_id' => $source->id,
        'direction' => 'input',
        'adapter_type' => 'opcua',
        'target_type' => 'reader',
        'target_id' => $reader->id,
        'action_key' => AccessBindingActionKey::EMERGENCY_EXIT_REQUEST->value,
        'channel' => 'EmergencyExitTag',
        'signal_reversed' => false,
        'enabled' => true,
        'config' => [],
        'metadata' => [],
    ]);

    $dispatcher = app(OpcInputActionDispatcher::class);

    $dispatcher->handleOpcValueChanged($source, [
        'name' => 'EmergencyExitTag',
        'node_id' => 'ns=2;s=EmergencyExitTag',
        'raw' => 0,
        'display' => 'false',
        'timestamp' => now()->toIso8601String(),
    ]);

    $dispatcher->handleOpcValueChanged($source, [
        'name' => 'EmergencyExitTag',
        'node_id' => 'ns=2;s=EmergencyExitTag',
        'raw' => 1,
        'display' => 'true',
        'timestamp' => now()->toIso8601String(),
    ]);

    Queue::assertPushed(ProcessReaderEvent::class, function (ProcessReaderEvent $job) use ($reader): bool {
        return $job->accessReader->id === $reader->id
            && $job->targetValue === 0
            && $job->allowAutoRelock === false
            && $job->eventSource === 'opc_input';
    });

    Queue::assertPushed(ProcessReaderEvent::class, 1);
});
