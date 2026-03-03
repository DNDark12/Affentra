<?php

declare(strict_types=1);

namespace App\Jobs\Alert;

use App\Models\AlertIncident;
use App\Services\Alert\AlertTemplateService;
use App\Services\Alert\TelegramConfigService;
use App\Services\Alert\TelegramNotifierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTelegramAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly int $incidentId,
    ) {}

    public function handle(
        AlertTemplateService $alertTemplateService,
        TelegramConfigService $telegramConfigService,
        TelegramNotifierService $telegramNotifierService,
    ): void {
        $incident = AlertIncident::query()
            ->with('rule')
            ->find($this->incidentId);

        if ($incident === null || $incident->rule === null) {
            return;
        }

        $config = $telegramConfigService->findEnabledByUserId((int) $incident->user_id);
        if ($config === null) {
            return;
        }

        $targetChatId = trim((string) ($config->group_id ?: $config->default_chat_id));
        if ($targetChatId === '') {
            return;
        }

        $templates = $alertTemplateService->resolveTemplates((int) $incident->user_id, (string) $incident->rule->metric);
        $variables = (array) ($incident->context['template_variables'] ?? []);
        if ($variables === []) {
            $variables = [
                'metric_label' => $alertTemplateService->metricLabel((string) $incident->rule->metric),
                'triggered_value' => (string) $incident->triggered_value,
                'operator' => (string) $incident->rule->operator,
                'threshold' => (string) $incident->rule->threshold,
                'detected_at' => optional($incident->created_at)->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
                'connection_name' => 'N/A',
            ];
        }
        $text = $alertTemplateService->render((string) $templates['telegram_template'], $variables);
        $result = $telegramNotifierService->sendMessage(
            (string) $config->bot_token,
            $targetChatId,
            $text,
        );

        $config->last_test_at = now();
        $config->last_test_status = $result['ok'] ? 'success' : 'failed';
        $config->last_test_error = $result['ok'] ? null : mb_substr((string) ($result['error'] ?? 'Unknown error'), 0, 2000);
        if ($result['ok']) {
            $config->verified_at = now();
        }
        $config->save();

        if (! $result['ok']) {
            Log::warning('Telegram alert dispatch failed', [
                'incident_id' => $incident->id,
                'alert_rule_id' => $incident->alert_rule_id,
                'user_id' => $incident->user_id,
                'error' => $result['error'],
            ]);
        }
    }
}
