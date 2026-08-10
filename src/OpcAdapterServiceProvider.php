<?php

declare(strict_types=1);

namespace OTGH\AccessControl\OpcAdapter;

use App\Models\Hardware\Source;
use App\Services\AccessControl\AccessControlCapabilityRegistry;
use App\Services\AccessControl\AccessControlConfigurationRegistry;
use App\Services\AccessControl\DiagnosticsNavigationRegistry;
use App\Services\AccessControl\HealthCheckRegistry;
use App\Services\AccessControl\SourceConnectionTesterRegistry;
use App\Services\Supervisor\SupervisorProgramRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use OTGH\AccessControl\OpcAdapter\Console\Commands\MonitorOpcSource;
use OTGH\AccessControl\OpcAdapter\Console\Commands\OpcInputDiagnostics;
use OTGH\AccessControl\OpcAdapter\Console\Commands\OpcTest;
use OTGH\AccessControl\OpcAdapter\Console\Commands\SyncOpcMonitors;
use OTGH\AccessControl\OpcAdapter\Services\OpcInputDiagnosticsService;

class OpcAdapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OpcInputActionDispatcher::class);
        $this->app->singleton(OpcUa\OpcUaMonitorService::class);
        $this->app->singleton(OpcUa\OpcUaSourceConfigResolver::class);
        $this->app->singleton(OpcInputDiagnosticsService::class);
        $this->app->singleton(OpcSourceConnectionTester::class);

        $this->app->afterResolving(AccessControlCapabilityRegistry::class, function (AccessControlCapabilityRegistry $registry): void {
            $registry->registerBindingAdapterType('opcua', 'OPCUA', ['opc', 'opc_ua']);
            $registry->registerSourceType('opcua', 'OPCUA', ['opc', 'opc_ua']);
        });

        $this->app->afterResolving(SourceConnectionTesterRegistry::class, function (SourceConnectionTesterRegistry $registry): void {
            $registry->register($this->app->make(OpcSourceConnectionTester::class));
        });

        $this->app->afterResolving(AccessControlConfigurationRegistry::class, function (AccessControlConfigurationRegistry $registry): void {
            $registry->registerField(
                key: 'opc_monitor_max_runtime_seconds',
                label: 'Monitor Max Runtime (Seconds)',
                type: 'integer',
                description: 'Worker recycle threshold for OPC monitor jobs.',
                section: 'opc',
                sectionLabel: 'OPC Adapter',
                package: 'otghcloud/aurora-access-adapter-opc',
                default: 900,
            );

            $registry->registerField(
                key: 'opc_ua.default_nodes',
                label: 'Default OPC Nodes',
                type: 'json',
                description: 'Fallback node list used by OPC monitor/test commands.',
                section: 'opc',
                sectionLabel: 'OPC Adapter',
                package: 'otghcloud/aurora-access-adapter-opc',
                default: [],
            );
        });

        $this->app->afterResolving(DiagnosticsNavigationRegistry::class, function (DiagnosticsNavigationRegistry $registry): void {
            $registry->register('admin.opc-diagnostics', 'OPC', 40);
        });

        $this->app->afterResolving(SupervisorProgramRegistry::class, function (SupervisorProgramRegistry $registry): void {
            $registry->register(function (string $phpBinary, string $workingDir): array {
                if (! Schema::hasTable('sources')) {
                    return [];
                }

                $hasOpcSources = Source::query()
                    ->where('enabled', true)
                    ->where(function ($query): void {
                        $query->where('type', 'opc')
                            ->orWhere('type', 'opcua')
                            ->orWhere('type', 'opc_ua');
                    })
                    ->exists();

                if (! $hasOpcSources) {
                    return [];
                }

                return [<<<CONF
[program:access-control-opc-monitor-queue]
command={$phpBinary} {$workingDir}/artisan queue:work redis-opc-monitor --queue=access-opc-monitor --sleep=1 --tries=1 --timeout=0 --max-time=0
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
user=www-data
directory={$workingDir}
redirect_stderr=true
stdout_logfile={$workingDir}/storage/logs/supervisor-opc-monitor-queue.log
stopwaitsecs=3600
CONF];
            });
        });

        $this->app->afterResolving(HealthCheckRegistry::class, function (HealthCheckRegistry $registry): void {
            $registry->register(function ($service, ?string $readerIdentifier = null): array {
                $matches = function_exists('shell_exec')
                    ? shell_exec('pgrep -fa "queue:work redis-opc-monitor --queue=access-opc-monitor" 2>/dev/null')
                    : null;

                if (! is_string($matches) || trim($matches) === '') {
                    return [[
                        'name' => 'OPC monitor worker process match',
                        'status' => 'WARN',
                        'details' => 'No access-opc-monitor queue worker found via pgrep',
                    ]];
                }

                $count = count(array_filter(array_map('trim', explode("\n", trim($matches)))));

                return [[
                    'name' => 'OPC monitor worker process match',
                    'status' => 'PASS',
                    'details' => sprintf('pgrep matches=%d', $count),
                ]];
            });
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'opc-adapter');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MonitorOpcSource::class,
                OpcInputDiagnostics::class,
                OpcTest::class,
                SyncOpcMonitors::class,
            ]);
        }
    }
}
