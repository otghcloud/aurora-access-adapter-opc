<?php

namespace OTGH\AccessControl\OpcAdapter\Console\Commands;

use App\Models\Hardware\Source;
use App\Services\AccessControl\AccessControlSettingsRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OTGH\AccessControl\OpcAdapter\OpcInputActionDispatcher;
use OTGH\AccessControl\OpcAdapter\OpcUa\OpcUaMonitorService;
use OTGH\AccessControl\OpcAdapter\OpcUa\OpcUaSourceConfigResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Signature('app:monitor-opc-source
    {--source-id= : Access source id to monitor from database}
    {--source-identifier= : Access source identifier to monitor from database}
    {--endpoint= : OPC UA endpoint URL}
    {--namespace= : OPC UA namespace index for string NodeIds}
    {--node=* : Node display names to monitor (repeatable)}
    {--publishing-interval= : Subscription publishing interval in milliseconds}
    {--sampling-interval= : Monitored item sampling interval in milliseconds}
')]
#[Description('Monitor OPC UA nodes and stream value-change notifications')]
class MonitorOpcSource extends Command
{
    public function __construct(
        private readonly OpcUaMonitorService $monitorService,
        private readonly OpcUaSourceConfigResolver $sourceConfigResolver,
        private readonly OpcInputActionDispatcher $inputActionDispatcher,
        private readonly AccessControlSettingsRepository $settings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $resolved = $this->resolveMonitorConfig();
        $source = $resolved['source'];
        $endpoint = $resolved['endpoint'];
        $namespace = $resolved['namespace'];
        $publishingInterval = $resolved['publishing_interval_ms'];
        $samplingInterval = $resolved['sampling_interval_ms'];
        $requestedNodes = $resolved['nodes'];

        if ($endpoint === '') {
            $this->error('Missing endpoint. Provide --source-id/--source-identifier using DB source config or pass --endpoint.');

            return self::FAILURE;
        }

        $this->info('Starting OPC monitor...');
        $this->line('Endpoint: '.$endpoint);
        $this->line('Namespace: '.$namespace);
        $this->line('Publishing interval: '.$publishingInterval.' ms');
        $this->line('Sampling interval: '.$samplingInterval.' ms');

        if ($requestedNodes !== []) {
            $this->line('Requested nodes: '.implode(', ', $requestedNodes));
        }

        $this->line('Listening for notifications (Ctrl+C to stop).');

        $this->monitorService->monitor(
            endpoint: $endpoint,
            namespace: $namespace,
            allowedNodeNames: $requestedNodes,
            publishingIntervalMs: $publishingInterval,
            samplingIntervalMs: $samplingInterval,
            onValueChanged: function (array $event): void {
                $name = $event['name'];
                $display = $event['display'];
                $time = date('H:i:s');

                if ($source instanceof Source) {
                    $this->inputActionDispatcher->handleOpcValueChanged($source, $event);
                }

                $this->line(sprintf('  [%s] %-28s = %s', $time, $name, $display));
            },
        );

        return self::SUCCESS;
    }

    /**
     * @return array{source:?Source,endpoint:string,namespace:int,publishing_interval_ms:float,sampling_interval_ms:float,nodes:array<int,string>}
     */
    private function resolveMonitorConfig(): array
    {
        $sourceIdOption = $this->option('source-id');

        if (is_numeric($sourceIdOption)) {
            $resolved = $this->sourceConfigResolver->resolveById((int) $sourceIdOption);
            $optionNodes = $this->resolveOptionNodes();

            return [
                'source' => $resolved['source'],
                'endpoint' => $this->resolveOptionEndpoint($resolved['endpoint']),
                'namespace' => $this->resolveOptionInt('namespace', $resolved['namespace']),
                'publishing_interval_ms' => $this->resolveOptionFloat('publishing-interval', $resolved['publishing_interval_ms']),
                'sampling_interval_ms' => $this->resolveOptionFloat('sampling-interval', $resolved['sampling_interval_ms']),
                'nodes' => $optionNodes !== [] ? $optionNodes : $resolved['nodes'],
            ];
        }

        $sourceIdentifierOption = $this->option('source-identifier');

        if (is_string($sourceIdentifierOption) && trim($sourceIdentifierOption) !== '') {
            $source = Source::query()
                ->where('identifier', trim($sourceIdentifierOption))
                ->first();

            if ($source !== null) {
                $resolved = $this->sourceConfigResolver->resolveFromSource($source);
                $optionNodes = $this->resolveOptionNodes();

                return [
                    'source' => $source,
                    'endpoint' => $this->resolveOptionEndpoint($resolved['endpoint']),
                    'namespace' => $this->resolveOptionInt('namespace', $resolved['namespace']),
                    'publishing_interval_ms' => $this->resolveOptionFloat('publishing-interval', $resolved['publishing_interval_ms']),
                    'sampling_interval_ms' => $this->resolveOptionFloat('sampling-interval', $resolved['sampling_interval_ms']),
                    'nodes' => $optionNodes !== [] ? $optionNodes : $resolved['nodes'],
                ];
            }

            throw new \RuntimeException('No source found for identifier ['.trim($sourceIdentifierOption).'].');
        }

        $candidate = $this->option('endpoint');

        if (! is_string($candidate)) {
            $candidate = '';
        }

        $endpoint = trim($candidate);

        return [
            'source' => null,
            'endpoint' => $endpoint,
            'namespace' => max(0, (int) ($this->option('namespace') ?? 2)),
            'publishing_interval_ms' => max(50.0, (float) ($this->option('publishing-interval') ?? 1000)),
            'sampling_interval_ms' => max(50.0, (float) ($this->option('sampling-interval') ?? 1000)),
            'nodes' => $this->resolveRequestedNodes(),
        ];
    }

    public function execute(
        ?InputInterface $input = null,
        ?OutputInterface $output = null,
    ): int {
        try {
            return parent::execute($input, $output);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<int,string>
     */
    private function resolveRequestedNodes(): array
    {
        $optionNodes = $this->resolveOptionNodes();

        if ($optionNodes !== []) {
            return $optionNodes;
        }

        $configured = $this->settings->getArray('opc_ua.default_nodes', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $configured,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @return array<int,string>
     */
    private function resolveOptionNodes(): array
    {
        $optionNodes = $this->option('node');

        if (is_array($optionNodes) && $optionNodes !== []) {
            return array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $optionNodes,
            ), static fn (string $value): bool => $value !== ''));
        }

        return [];
    }

    private function resolveOptionEndpoint(string $fallback): string
    {
        $endpoint = $this->option('endpoint');

        if (! is_string($endpoint)) {
            return $fallback;
        }

        $trimmed = trim($endpoint);

        return $trimmed !== '' ? $trimmed : $fallback;
    }

    private function resolveOptionInt(string $optionName, int $fallback): int
    {
        $value = $this->option($optionName);

        if (! is_numeric($value)) {
            return $fallback;
        }

        return max(0, (int) $value);
    }

    private function resolveOptionFloat(string $optionName, float $fallback): float
    {
        $value = $this->option($optionName);

        if (! is_numeric($value)) {
            return $fallback;
        }

        return max(50.0, (float) $value);
    }
}
