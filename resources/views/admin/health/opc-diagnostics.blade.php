@extends('layouts.admin')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">OPC Diagnostics</h1>
            <p class="text-muted mb-0">Live monitor heartbeat and per-binding ExitRequest/EmergencyExitRequest telemetry.</p>
        </div>
        <form method="GET" action="{{ route('admin.opc-diagnostics') }}" class="d-flex flex-wrap align-items-center gap-2">
            <label for="auto_refresh" class="form-label mb-0 small text-muted">Auto-refresh</label>
            <select name="auto_refresh" id="auto_refresh" class="form-select form-select-sm" style="min-width: 170px;">
                @foreach ($autoRefreshOptions as $interval)
                    <option value="{{ $interval }}" @selected($autoRefreshSeconds === $interval)>
                        {{ $interval === 0 ? 'Off' : 'Every '.$interval.'s' }}
                    </option>
                @endforeach
            </select>
            <label for="action_key" class="form-label mb-0 small text-muted">Action</label>
            <select name="action_key" id="action_key" class="form-select form-select-sm" style="min-width: 220px;">
                <option value="">All Input Actions</option>
                @foreach (($actionOptions ?? []) as $option)
                    <option value="{{ $option['value'] }}" @selected((int) request('action_key', (string) ($selectedActionKey ?? '')) === (int) $option['value'])>
                        {{ $option['key'] }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Refresh</button>
        </form>
    </div>

    <div class="row g-3 mb-4">
        @php
            $sourceWithNextRecycle = collect($diagnostics['sources'] ?? [])
                ->filter(fn (array $source): bool => is_int($source['seconds_until_recycle'] ?? null))
                ->sortBy('seconds_until_recycle')
                ->first();

            $nextRecycleInSeconds = is_array($sourceWithNextRecycle) && is_int($sourceWithNextRecycle['seconds_until_recycle'] ?? null)
                ? $sourceWithNextRecycle['seconds_until_recycle']
                : null;

            $nextRecycleCountdown = 'n/a';
            $nextRecycleBadgeClass = 'secondary';
            $nextRecycleBadgeLabel = 'n/a';

            if (is_int($nextRecycleInSeconds)) {
                $remaining = max(0, $nextRecycleInSeconds);
                $minutes = intdiv($remaining, 60);
                $seconds = $remaining % 60;
                $nextRecycleCountdown = sprintf('%02d:%02d', $minutes, $seconds);

                if ($nextRecycleInSeconds <= 0) {
                    $nextRecycleBadgeClass = 'danger';
                    $nextRecycleBadgeLabel = 'due now';
                } elseif ($nextRecycleInSeconds <= 30) {
                    $nextRecycleBadgeClass = 'warning';
                    $nextRecycleBadgeLabel = '< 30s';
                } elseif ($nextRecycleInSeconds <= 120) {
                    $nextRecycleBadgeClass = 'info';
                    $nextRecycleBadgeLabel = '< 2m';
                } else {
                    $nextRecycleBadgeClass = 'success';
                    $nextRecycleBadgeLabel = 'stable';
                }
            }
        @endphp

        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Generated</p>
                    <p class="mb-0 fw-semibold">{{ \Illuminate\Support\Carbon::parse($diagnostics['generated_at'])->format('d/m/Y H:i:s') }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">OPC Queue Workers</p>
                    <p class="display-6 mb-0">{{ $diagnostics['opc_worker_processes'] ?? 'n/a' }}</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Active Monitors</p>
                    <p class="display-6 mb-0">{{ $diagnostics['active_monitors'] ?? 'n/a' }}</p>
                    <p class="small text-muted mb-0">Live heartbeat (queue jobs)</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <p class="text-muted mb-1">Input Bindings</p>
                    <p class="display-6 mb-0">{{ count($diagnostics['bindings']) }}</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                        <p class="text-muted mb-0">Next Recycle</p>
                        <span class="badge text-bg-{{ $nextRecycleBadgeClass }}">{{ $nextRecycleBadgeLabel }}</span>
                    </div>
                    <p class="display-6 mb-0">{{ $nextRecycleCountdown }}</p>
                    @if (is_array($sourceWithNextRecycle))
                        <p class="small text-muted mb-0">
                            {{ $sourceWithNextRecycle['identifier'] ?? 'n/a' }}
                            @if (is_string($sourceWithNextRecycle['next_recycle_at'] ?? null) && $sourceWithNextRecycle['next_recycle_at'] !== '')
                                | {{ $sourceWithNextRecycle['next_recycle_at'] }}
                            @endif
                        </p>
                    @else
                        <p class="small text-muted mb-0">No live recycle estimate available.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">OPC Sources</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th>Endpoint</th>
                        <th>Discovered Nodes</th>
                        <th>Monitor Status</th>
                        <th>Heartbeat</th>
                        <th>Next Recycle (est.)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($diagnostics['sources'] as $source)
                        <tr>
                            <td>{{ $source['name'] }} <span class="text-muted">({{ $source['identifier'] }})</span></td>
                            <td><code>{{ $source['endpoint'] }}</code></td>
                            <td>
                                @if (is_string($source['discovery_error'] ?? null) && $source['discovery_error'] !== '')
                                    <span class="badge text-bg-danger">error</span>
                                    <div class="small text-muted">{{ $source['discovery_error'] }}</div>
                                @else
                                    <span class="badge text-bg-info">{{ $source['discovered_nodes_count'] ?? 0 }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge text-bg-{{ ($source['running_live'] ?? false) ? 'success' : (($source['running'] ?? false) ? 'warning' : 'danger') }}">
                                    {{ ($source['running_live'] ?? false) ? 'running' : (($source['running'] ?? false) ? 'stale heartbeat' : 'not running') }}
                                </span>
                            </td>
                            <td>
                                <div>{{ $source['heartbeat'] ?? 'n/a' }}</div>
                                <div class="small text-muted">age={{ is_int($source['heartbeat_age_seconds'] ?? null) ? ($source['heartbeat_age_seconds'].'s') : 'n/a' }}</div>
                            </td>
                            <td>
                                @if (is_string($source['next_recycle_at'] ?? null) && $source['next_recycle_at'] !== '')
                                    @php
                                        $secondsUntilRecycle = is_int($source['seconds_until_recycle'] ?? null) ? $source['seconds_until_recycle'] : null;
                                        $maxRuntimeSeconds = is_int($source['recycle_runtime_seconds'] ?? null) ? max(1, $source['recycle_runtime_seconds']) : null;

                                        $recycleBadgeClass = 'secondary';
                                        $recycleLabel = 'unknown';

                                        if (is_int($secondsUntilRecycle)) {
                                            if ($secondsUntilRecycle <= 0) {
                                                $recycleBadgeClass = 'danger';
                                                $recycleLabel = 'recycle due now';
                                            } elseif ($secondsUntilRecycle <= 30) {
                                                $recycleBadgeClass = 'warning';
                                                $recycleLabel = 'within 30s';
                                            } elseif ($secondsUntilRecycle <= 120) {
                                                $recycleBadgeClass = 'info';
                                                $recycleLabel = 'within 2m';
                                            } else {
                                                $recycleBadgeClass = 'success';
                                                $recycleLabel = 'stable';
                                            }
                                        }

                                        $countdownText = 'n/a';
                                        if (is_int($secondsUntilRecycle)) {
                                            $remaining = max(0, $secondsUntilRecycle);
                                            $minutes = intdiv($remaining, 60);
                                            $seconds = $remaining % 60;
                                            $countdownText = sprintf('%02d:%02d', $minutes, $seconds);
                                        }

                                        $progressPercent = null;
                                        if (is_int($secondsUntilRecycle) && is_int($maxRuntimeSeconds)) {
                                            $elapsed = max(0, $maxRuntimeSeconds - max(0, $secondsUntilRecycle));
                                            $progressPercent = min(100, max(0, (int) round(($elapsed / $maxRuntimeSeconds) * 100)));
                                        }
                                    @endphp

                                    <div class="mb-1">
                                        <span class="badge text-bg-{{ $recycleBadgeClass }}">{{ $recycleLabel }}</span>
                                    </div>
                                    <div>{{ $source['next_recycle_at'] }}</div>
                                    <div class="small text-muted">
                                        T-{{ $countdownText }}
                                        | max={{ is_int($maxRuntimeSeconds ?? null) ? ($maxRuntimeSeconds.'s') : 'n/a' }}
                                    </div>
                                    @if (is_int($progressPercent))
                                        <div class="progress mt-1" role="progressbar" aria-label="Recycle runtime progress" aria-valuenow="{{ $progressPercent }}" aria-valuemin="0" aria-valuemax="100" style="height: 6px;">
                                            <div class="progress-bar bg-{{ $recycleBadgeClass === 'danger' ? 'danger' : ($recycleBadgeClass === 'warning' ? 'warning' : 'primary') }}" style="width: {{ $progressPercent }}%"></div>
                                        </div>
                                    @endif
                                @else
                                    <span class="text-muted">n/a</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No enabled OPC sources found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Input Bindings Telemetry</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Binding</th>
                        <th>Source / Channel</th>
                        <th>Target</th>
                        <th>State</th>
                        <th>Channel Discovery</th>
                        <th>Last Seen</th>
                        <th>Last Dispatch</th>
                        <th>Dispatch Count</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($diagnostics['bindings'] as $binding)
                        <tr>
                            <td>
                                <div>#{{ $binding['id'] }} <code>{{ $binding['action_key'] }}</code></div>
                                <div class="small text-muted">{{ strtoupper($binding['adapter_type']) }} | {{ $binding['enabled'] ? 'enabled' : 'disabled' }}</div>
                            </td>
                            <td>
                                <div>{{ $binding['source_identifier'] ?? 'n/a' }}</div>
                                <div><code>{{ $binding['channel'] ?? 'n/a' }}</code></div>
                            </td>
                            <td>{{ $binding['target_label'] ?? ($binding['target_type'].'#'.$binding['target_id']) }}</td>
                            <td>
                                @if (is_bool($binding['effective_active'] ?? null))
                                    <span class="badge text-bg-{{ $binding['effective_active'] ? 'success' : 'secondary' }}">{{ $binding['effective_active'] ? 'HIGH' : 'LOW' }}</span>
                                @else
                                    <span class="badge text-bg-warning">unknown</span>
                                @endif
                                <div class="small text-muted">reversed={{ $binding['signal_reversed'] ? 'yes' : 'no' }} source={{ $binding['state_source'] ?? 'n/a' }}</div>
                            </td>
                            <td>
                                @if (is_bool($binding['channel_discovered']))
                                    <span class="badge text-bg-{{ $binding['channel_discovered'] ? 'success' : 'danger' }}">
                                        {{ $binding['channel_discovered'] ? 'found' : 'missing' }}
                                    </span>
                                @elseif (is_string($binding['channel_discovery_error'] ?? null) && $binding['channel_discovery_error'] !== '')
                                    <span class="badge text-bg-danger">error</span>
                                    <div class="small text-muted">{{ $binding['channel_discovery_error'] }}</div>
                                @else
                                    <span class="badge text-bg-secondary">n/a</span>
                                @endif
                            </td>
                            <td>
                                @if (is_array($binding['last_seen']))
                                    <div>{{ $binding['last_seen']['seen_at'] ?? 'n/a' }}</div>
                                    <div class="small text-muted">node={{ $binding['last_seen']['node_name'] ?? 'n/a' }} raw={{ is_scalar($binding['last_seen']['raw'] ?? null) ? (string) $binding['last_seen']['raw'] : 'n/a' }}</div>
                                @else
                                    n/a
                                @endif
                            </td>
                            <td>{{ $binding['last_dispatch_at'] ?? 'n/a' }}</td>
                            <td>{{ $binding['dispatch_count'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No OPC input bindings found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($autoRefreshSeconds > 0)
        <script>
            window.setTimeout(function () {
                window.location.reload();
            }, {{ $autoRefreshSeconds * 1000 }});
        </script>
    @endif
@endsection
