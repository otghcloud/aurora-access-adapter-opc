<?php

namespace OTGH\AccessControl\OpcAdapter\Console\Commands;

use App\Models\Hardware\Source;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OTGH\AccessControl\OpcAdapter\Jobs\MonitorOpcSourceJob;

#[Signature('app:sync-opc-monitors {--json : Output machine-readable JSON} {--dry-run : Report actions without dispatching jobs}')]
#[Description('Ensure enabled OPC source monitors are running as long-lived queue jobs')]
class SyncOpcMonitors extends Command
{
    public function handle(): int
    {
        $jsonOutput = (bool) $this->option('json');
        $dryRun = (bool) $this->option('dry-run');
        $sources = Source::query()
            ->where('enabled', true)
            ->whereIn('type', ['opc', 'opcua', 'opc_ua'])
            ->orderBy('id')
            ->get();

        $results = [];
        $dispatched = 0;

        foreach ($sources as $source) {
            $heartbeatKey = MonitorOpcSourceJob::heartbeatCacheKey((int) $source->id);
            $isRunning = Cache::has($heartbeatKey);
            $action = 'running';

            if (! $isRunning) {
                $action = $dryRun ? 'would_dispatch' : 'dispatch';

                if (! $dryRun) {
                    MonitorOpcSourceJob::dispatch((int) $source->id);
                    $dispatched++;
                }
            }

            $row = [
                'source_id' => $source->id,
                'source_identifier' => $source->identifier,
                'type' => $source->type,
                'endpoint' => $source->endpoint,
                'status' => $isRunning ? 'running' : 'not_running',
                'action' => $action,
            ];

            $results[] = $row;

            if (! $jsonOutput) {
                $this->line(sprintf(
                    '[%s] %s (%s) action=%s',
                    strtoupper($row['status']),
                    $source->name ?: $source->identifier,
                    $source->identifier,
                    $action,
                ));
            }
        }

        $payload = [
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            'dry_run' => $dryRun,
            'sources_checked' => count($results),
            'jobs_dispatched' => $dispatched,
            'results' => $results,
        ];

        Log::info('opc.monitor.sync.completed', [
            'dry_run' => $dryRun,
            'sources_checked' => count($results),
            'jobs_dispatched' => $dispatched,
        ]);

        if ($jsonOutput) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->newLine();
            $this->info(sprintf('OPC monitor sync complete. sources=%d dispatched=%d dry_run=%s', count($results), $dispatched, $dryRun ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }
}
