<?php

namespace OTGH\AccessControl\OpcAdapter\Http\Controllers\Admin\Health;

use App\Enums\AccessControl\AccessBindingActionKey;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OTGH\AccessControl\OpcAdapter\Services\OpcInputDiagnosticsService;

class OpcDiagnosticsController extends Controller
{
    public function __invoke(Request $request, OpcInputDiagnosticsService $diagnosticsService): View
    {
        $requestedAutoRefresh = (int) $request->integer('auto_refresh', 0);
        $allowedAutoRefreshIntervals = [0, 5, 10, 30];
        $autoRefreshSeconds = in_array($requestedAutoRefresh, $allowedAutoRefreshIntervals, true)
            ? $requestedAutoRefresh
            : 0;

        $selectedAction = AccessBindingActionKey::fromStored($request->input('action_key'));
        $diagnostics = $diagnosticsService->buildPayload();

        if ($selectedAction instanceof AccessBindingActionKey) {
            $diagnostics['bindings'] = array_values(array_filter(
                is_array($diagnostics['bindings'] ?? null) ? $diagnostics['bindings'] : [],
                static function (array $binding) use ($selectedAction): bool {
                    $bindingAction = AccessBindingActionKey::fromStored($binding['action_key_id'] ?? $binding['action_key'] ?? null);

                    return $bindingAction === $selectedAction;
                }
            ));
        }

        return view('opc-adapter::admin.health.opc-diagnostics', [
            'diagnostics' => $diagnostics,
            'autoRefreshSeconds' => $autoRefreshSeconds,
            'autoRefreshOptions' => $allowedAutoRefreshIntervals,
            'actionOptions' => AccessBindingActionKey::options('input'),
            'selectedActionKey' => $selectedAction?->value,
        ]);
    }
}
