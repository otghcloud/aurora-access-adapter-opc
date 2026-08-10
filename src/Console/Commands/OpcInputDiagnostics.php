<?php

namespace OTGH\AccessControl\OpcAdapter\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OTGH\AccessControl\OpcAdapter\Services\OpcInputDiagnosticsService;

#[Signature('app:opc-input-diagnostics {--json : Output machine-readable JSON}')]
#[Description('Show OPC input binding diagnostics including monitor heartbeat and dispatch telemetry')]
class OpcInputDiagnostics extends Command
{
    public function __construct(private readonly OpcInputDiagnosticsService $diagnosticsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->diagnosticsService->buildPayload();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('OPC Input Diagnostics @ '.$payload['generated_at']);
        $this->line('opc_worker_processes='.(string) ($payload['opc_worker_processes'] ?? 'n/a'));
        $this->line('opc_monitor_command_processes='.(string) ($payload['opc_monitor_command_processes'] ?? 'n/a'));
        $this->newLine();

        $this->info('Sources');
        foreach ($payload['sources'] as $source) {
            $this->line(sprintf(
                '- [%s] %s (%s) heartbeat=%s',
                $source['running'] ? 'running' : 'not_running',
                $source['identifier'],
                $source['endpoint'] ?? 'n/a',
                $source['heartbeat'] ?? 'none',
            ));
        }

        $this->newLine();
        $this->info('Input Bindings');
        foreach ($payload['bindings'] as $binding) {
            $this->line(sprintf(
                '- #%d %s source=%s channel=%s target=%s active=%s last_seen=%s last_dispatch=%s dispatch_count=%d',
                $binding['id'],
                $binding['action_key'],
                $binding['source_identifier'] ?? 'n/a',
                $binding['channel'] ?? 'n/a',
                $binding['target_label'] ?? ($binding['target_type'].'#'.$binding['target_id']),
                is_bool($binding['edge_active']) ? ($binding['edge_active'] ? 'true' : 'false') : 'unknown',
                is_array($binding['last_seen']) ? ($binding['last_seen']['seen_at'] ?? 'n/a') : 'n/a',
                $binding['last_dispatch_at'] ?? 'n/a',
                (int) $binding['dispatch_count'],
            ));
        }

        return self::SUCCESS;
    }
}
