<?php

use App\Models\Hardware\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an opc source from typed fields', function () {
    $admin = User::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.access-sources.store'), [
        'name' => 'Main OPC Server',
        'identifier' => 'main-opc-server',
        'type' => 'opcua',
        'endpoint' => '',
        'enabled' => '1',
        'opc_endpoint' => 'opc.tcp://10.0.0.5:4840',
        'opc_namespace' => '3',
        'opc_publishing_interval_ms' => '750',
        'opc_sampling_interval_ms' => '750',
        'opc_nodes_text' => "Reader01.Open\nReader01.Feedback",
        'metadata_json' => '{"site":"HQ"}',
    ]);

    $response->assertRedirect(route('admin.access-sources.index'));

    $source = Source::query()->where('identifier', 'main-opc-server')->firstOrFail();

    expect($source->type)->toBe('opcua');
    expect($source->endpoint)->toBe('opc.tcp://10.0.0.5:4840');
    expect(data_get($source->config, 'opc_ua.namespace'))->toBe(3);
    expect(data_get($source->config, 'opc_ua.nodes'))->toBe(['Reader01.Open', 'Reader01.Feedback']);
    expect(data_get($source->metadata, 'site'))->toBe('HQ');
});
