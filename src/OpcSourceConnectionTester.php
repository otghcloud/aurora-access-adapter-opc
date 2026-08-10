<?php

declare(strict_types=1);

namespace OTGH\AccessControl\OpcAdapter;

use App\Models\Hardware\Source;
use App\Services\AccessControl\SourceConnectionTesterInterface;
use OTGH\AccessControl\OpcAdapter\OpcUa\OpcUaSourceConfigResolver;
use TechDock\OpcUa\Client\ClientBuilder;
use TechDock\OpcUa\Core\Types\NodeId;

class OpcSourceConnectionTester implements SourceConnectionTesterInterface
{
    public function __construct(private readonly OpcUaSourceConfigResolver $sourceConfigResolver) {}

    /**
     * @return array<int,string>
     */
    public function supportedSourceTypes(): array
    {
        return ['opcua', 'opc', 'opc_ua'];
    }

    public function test(Source $source): string
    {
        $resolved = $this->sourceConfigResolver->resolveFromSource($source);

        $client = ClientBuilder::create()
            ->endpoint($resolved['endpoint'])
            ->withAnonymousAuth()
            ->withAutoDiscovery()
            ->build();

        try {
            $objects = NodeId::numeric(0, 85);
            $references = $client->browser->browse($objects);
            $count = 0;
            foreach ($references as $_reference) {
                $count++;
            }
        } finally {
            $client->disconnect();
        }

        return sprintf('OPC source test passed: endpoint reachable, discovered %d top-level nodes.', $count);
    }
}
