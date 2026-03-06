<?php

declare(strict_types=1);

namespace App\Services\Alert;

use App\Jobs\Alert\SendTelegramAlertJob;
use App\Models\AffiliateBilling;
use App\Models\AffiliatePayout;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\Order;
use App\Models\PlatformConnection;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\AlertRuleContract;
use App\Services\Audit\AuditLogger;
use App\Services\Scope\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AlertService
{
    /**
     * @var array<string, array{label:string, unit:string, description:string}>
     */
    private const METRIC_DEFINITIONS = [
        'sync_failed_24h' => [
            'label' => 'Đồng bộ lỗi (24h)',
            'unit' => 'count',
            'description' => 'Số lượt đồng bộ thất bại trong 24 giờ gần nhất.',
        ],
        'sync_warning_24h' => [
            'label' => 'Đồng bộ cảnh báo (24h)',
            'unit' => 'count',
            'description' => 'Số lượt đồng bộ completed_with_warnings trong 24 giờ.',
        ],
        'unattributed_orders_24h' => [
            'label' => 'Đơn chưa gán link (24h)',
            'unit' => 'count',
            'description' => 'Số đơn hàng chưa map được tracking_link trong 24 giờ.',
        ],
        'unpaid_balance' => [
            'label' => 'Số dư chờ thanh toán',
            'unit' => 'currency',
            'description' => 'Tổng net_amount ở trạng thái pending/processing.',
        ],
        'pending_payouts' => [
            'label' => 'Payout chờ xử lý',
            'unit' => 'count',
            'description' => 'Số payout chờ xử lý theo trạng thái pending/processing.',
        ],
    ];

    /**
     * @var list<string>
     */
    private const OPERATOR_OPTIONS = AlertRuleContract::OPERATORS;

    /**
     * @var list<string>
     */
    private const CHANNEL_OPTIONS = AlertRuleContract::CHANNELS;

    public function __construct(
        private readonly ScopeResolver $scopeResolver,
        private readonly AuditLogger $auditLogger,
        private readonly AlertTemplateService $alertTemplateService,
        private readonly TelegramConfigService $telegramConfigService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   incidents: LengthAwarePaginator,
     *   summary: array{
     *      open_count:int,
     *      unseen_count:int,
     *      today_count:int,
     *      active_rules_count:int,
     *      created_incidents:int,
     *      resolved_incidents:int,
     *      evaluated_at:string
     *   },
     *   filters: array<string,mixed>
     * }
     */
    public function indexData(User $actor, array $filters = []): array
    {
        $evaluation = $this->evaluateActiveRules($actor);

        $incidents = AlertIncident::query()
            ->with('rule:id,name,metric,operator,threshold,channel')
            ->where('user_id', $actor->id)
            ->when(
                ($filters['status'] ?? null) === 'open',
                static fn (Builder $query): Builder => $query->whereNull('resolved_at'),
            )
            ->when(
                ($filters['status'] ?? null) === 'resolved',
                static fn (Builder $query): Builder => $query->whereNotNull('resolved_at'),
            )
            ->when(
                ($filters['status'] ?? null) === 'unseen',
                static fn (Builder $query): Builder => $query->whereNull('seen_at')->whereNull('resolved_at'),
            )
            ->latest('id')
            ->paginate(20)
            ->through(fn (AlertIncident $incident): array => $this->mapIncident($incident));

        $summary = [
            'open_count' => AlertIncident::query()
                ->where('user_id', $actor->id)
                ->whereNull('resolved_at')
                ->count(),
            'unseen_count' => AlertIncident::query()
                ->where('user_id', $actor->id)
                ->whereNull('seen_at')
                ->whereNull('resolved_at')
                ->count(),
            'today_count' => AlertIncident::query()
                ->where('user_id', $actor->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
            'active_rules_count' => AlertRule::query()
                ->where('user_id', $actor->id)
                ->where('is_active', true)
                ->count(),
            'created_incidents' => $evaluation['created_incidents'],
            'resolved_incidents' => $evaluation['resolved_incidents'],
            'evaluated_at' => $evaluation['evaluated_at'],
        ];

        return [
            'incidents' => $incidents,
            'summary' => $summary,
            'filters' => [
                'status' => $filters['status'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *   rules:list<array<string,mixed>>,
     *   metric_options:list<array<string,string>>,
     *   operator_options:list<string>,
     *   channel_options:list<string>,
     *   summary:array{active:int,total:int,open_incidents:int},
     *   telegram:array{enabled:bool,has_chat_id:bool},
     *   filters:array{channel:?string}
     * }
     */
    public function rulesData(User $actor, array $filters = []): array
    {
        $this->assertCanManageRules($actor);
        $this->evaluateActiveRules($actor);

        $channel = trim((string) ($filters['channel'] ?? ''));
        $rulesQuery = AlertRule::query()
            ->where('user_id', $actor->id)
            ->when(
                in_array($channel, ['in_app', 'telegram', 'both'], true),
                static fn (Builder $query): Builder => $query->where('channel', $channel),
            )
            ->latest('id');

        $rules = $rulesQuery
            ->get()
            ->map(fn (AlertRule $rule): array => $this->mapRule($rule))
            ->values()
            ->all();

        return [
            'rules' => $rules,
            'metric_options' => $this->metricOptions(),
            'operator_options' => self::OPERATOR_OPTIONS,
            'channel_options' => self::CHANNEL_OPTIONS,
            'summary' => [
                'active' => AlertRule::query()->where('user_id', $actor->id)->where('is_active', true)->count(),
                'total' => AlertRule::query()->where('user_id', $actor->id)->count(),
                'open_incidents' => AlertIncident::query()->where('user_id', $actor->id)->whereNull('resolved_at')->count(),
            ],
            'telegram' => $this->telegramConfigService->status($actor),
            'filters' => [
                'channel' => in_array($channel, ['in_app', 'telegram', 'both'], true) ? $channel : null,
            ],
        ];
    }

    /**
     * @return array{
     *   telegram:array<string,mixed>,
     *   summary:array{active:int,total:int,open_incidents:int}
     * }
     */
    public function telegramConfigData(User $actor): array
    {
        $this->assertCanManageRules($actor);

        return [
            'telegram' => $this->telegramConfigService->status($actor),
            'summary' => [
                'active' => AlertRule::query()->where('user_id', $actor->id)->where('is_active', true)->count(),
                'total' => AlertRule::query()->where('user_id', $actor->id)->count(),
                'open_incidents' => AlertIncident::query()->where('user_id', $actor->id)->whereNull('resolved_at')->count(),
            ],
        ];
    }

    /**
     * @return array{incident:array<string,mixed>, rule:array<string,mixed>|null, timeline:list<array<string,mixed>>}
     */
    public function incidentDetail(User $actor, AlertIncident $incident): array
    {
        $this->assertIncidentVisible($actor, $incident);

        $incident->loadMissing('rule');
        $timeline = AlertIncident::query()
            ->where('alert_rule_id', $incident->alert_rule_id)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AlertIncident $row): array => $this->mapIncident($row))
            ->values()
            ->all();

        return [
            'incident' => $this->mapIncident($incident),
            'rule' => $incident->rule ? $this->mapRule($incident->rule) : null,
            'timeline' => $timeline,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createRule(User $actor, array $validated): AlertRule
    {
        $this->assertCanManageRules($actor);

        $metric = (string) $validated['metric'];
        $operator = (string) $validated['operator'];
        $channel = (string) ($validated['channel'] ?? 'in_app');

        $this->assertMetricSupported($metric);
        $this->assertOperatorSupported($operator);
        $this->assertChannelSupported($channel);

        $rule = AlertRule::query()->create([
            'user_id' => $actor->id,
            'name' => trim((string) $validated['name']),
            'metric' => $metric,
            'operator' => $operator,
            'threshold' => (float) $validated['threshold'],
            'channel' => $channel,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $this->auditLogger->log(
            actor: $actor,
            action: 'alert.rule.created',
            target: $rule,
            newState: $rule->toArray(),
        );

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function updateRule(User $actor, AlertRule $rule, array $validated): AlertRule
    {
        $this->assertCanManageRules($actor);
        $this->assertRuleVisible($actor, $rule);

        $metric = array_key_exists('metric', $validated) ? (string) $validated['metric'] : $rule->metric;
        $operator = array_key_exists('operator', $validated) ? (string) $validated['operator'] : $rule->operator;
        $channel = array_key_exists('channel', $validated) ? (string) $validated['channel'] : $rule->channel;
        $this->assertMetricSupported($metric);
        $this->assertOperatorSupported($operator);
        $this->assertChannelSupported($channel);

        $previous = $rule->toArray();
        $rule->update([
            'name' => trim((string) ($validated['name'] ?? $rule->name)),
            'metric' => $metric,
            'operator' => $operator,
            'threshold' => array_key_exists('threshold', $validated) ? (float) $validated['threshold'] : $rule->threshold,
            'channel' => $channel,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $rule->is_active,
        ]);

        $this->auditLogger->log(
            actor: $actor,
            action: 'alert.rule.updated',
            target: $rule,
            previousState: $previous,
            newState: $rule->toArray(),
        );

        return $rule;
    }

    public function deleteRule(User $actor, AlertRule $rule): void
    {
        $this->assertCanManageRules($actor);
        $this->assertRuleVisible($actor, $rule);
        $previous = $rule->toArray();
        $rule->delete();

        $this->auditLogger->log(
            actor: $actor,
            action: 'alert.rule.deleted',
            target: AlertRule::class,
            targetId: $previous['id'] ?? null,
            previousState: $previous,
        );
    }

    public function toggleRule(User $actor, AlertRule $rule, ?bool $active = null): AlertRule
    {
        $this->assertCanManageRules($actor);
        $this->assertRuleVisible($actor, $rule);

        $previous = $rule->toArray();
        $rule->update([
            'is_active' => $active ?? ! $rule->is_active,
        ]);

        $this->auditLogger->log(
            actor: $actor,
            action: 'alert.rule.toggled',
            target: $rule,
            previousState: $previous,
            newState: $rule->toArray(),
        );

        return $rule;
    }

    public function markIncidentSeen(User $actor, AlertIncident $incident): AlertIncident
    {
        $this->assertIncidentVisible($actor, $incident);
        if ($incident->seen_at === null) {
            $incident->update(['seen_at' => now()]);
        }

        return $incident->fresh('rule') ?? $incident;
    }

    public function resolveIncident(User $actor, AlertIncident $incident): AlertIncident
    {
        return $this->resolveIncidentWithComment($actor, $incident, null);
    }

    public function resolveIncidentWithComment(User $actor, AlertIncident $incident, ?string $comment): AlertIncident
    {
        $this->assertIncidentVisible($actor, $incident);
        $context = is_array($incident->context) ? $incident->context : [];
        $context['manual_resolve_lock'] = true;

        if ($comment !== null && trim($comment) !== '') {
            $context = $this->appendIncidentComment($context, $actor, trim($comment));
        }

        $incident->update([
            'seen_at' => $incident->seen_at ?? now(),
            'resolved_at' => now(),
            'context' => $context,
        ]);

        return $incident->fresh('rule') ?? $incident;
    }

    public function addIncidentComment(User $actor, AlertIncident $incident, string $comment): AlertIncident
    {
        $this->assertIncidentVisible($actor, $incident);

        $context = is_array($incident->context) ? $incident->context : [];
        $context = $this->appendIncidentComment($context, $actor, trim($comment));

        $incident->update([
            'context' => $context,
            'seen_at' => $incident->seen_at ?? now(),
        ]);

        return $incident->fresh('rule') ?? $incident;
    }

    /**
     * @return array{created_incidents:int,resolved_incidents:int,evaluated_at:string}
     */
    public function evaluateActiveRules(User $actor): array
    {
        $rules = AlertRule::query()
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->get();

        $created = 0;
        $resolved = 0;

        foreach ($rules as $rule) {
            $value = $this->computeMetricValue($actor, $rule->metric);
            $isTriggered = $this->compareMetric($value, $rule->operator, (float) $rule->threshold);

            $latestIncident = AlertIncident::query()
                ->where('alert_rule_id', $rule->id)
                ->latest('id')
                ->first();

            $openIncident = AlertIncident::query()
                ->where('alert_rule_id', $rule->id)
                ->whereNull('resolved_at')
                ->latest('id')
                ->first();

            if ($isTriggered) {
                $templateVariables = $this->buildTemplateVariables($rule, $value);
                $templates = $this->alertTemplateService->resolveTemplates((int) $rule->user_id, (string) $rule->metric);
                $payload = [
                    'triggered_value' => $value,
                    'message' => $this->alertTemplateService->render((string) $templates['in_app_template'], $templateVariables),
                    'context' => [
                        'metric' => $rule->metric,
                        'operator' => $rule->operator,
                        'threshold' => (float) $rule->threshold,
                        'metric_label' => $this->alertTemplateService->metricLabel((string) $rule->metric),
                        'template_variables' => $templateVariables,
                        'computed_at' => now()->toIso8601String(),
                    ],
                ];

                if ($openIncident === null) {
                    if ($this->isManualResolveLocked($latestIncident)) {
                        continue;
                    }

                    $incident = AlertIncident::query()->create([
                        'alert_rule_id' => $rule->id,
                        'user_id' => $rule->user_id,
                        ...$payload,
                    ]);
                    $created++;
                    $this->dispatchNotifications($rule, $incident);
                } else {
                    $openIncident->update($payload);
                }

                $rule->update(['last_triggered_at' => now()]);
            } else {
                if ($openIncident !== null) {
                    $openIncident->update(['resolved_at' => now()]);
                    $resolved++;
                }

                if ($this->isManualResolveLocked($latestIncident)) {
                    $latestContext = is_array($latestIncident->context) ? $latestIncident->context : [];
                    $latestContext['manual_resolve_lock'] = false;
                    $latestIncident->update([
                        'context' => $latestContext,
                    ]);
                }
            }
        }

        return [
            'created_incidents' => $created,
            'resolved_incidents' => $resolved,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value:string,label:string,unit:string,description:string}>
     */
    private function metricOptions(): array
    {
        $options = [];
        foreach (self::METRIC_DEFINITIONS as $value => $config) {
            $options[] = [
                'value' => $value,
                'label' => $config['label'],
                'unit' => $config['unit'],
                'description' => $config['description'],
            ];
        }

        return $options;
    }

    private function assertCanManageRules(User $actor): void
    {
        if ($actor->isPartner()) {
            throw new AuthorizationException('Forbidden');
        }
    }

    private function assertRuleVisible(User $actor, AlertRule $rule): void
    {
        if ((int) $rule->user_id !== (int) $actor->id) {
            throw new NotFoundHttpException('Not found.');
        }
    }

    private function assertIncidentVisible(User $actor, AlertIncident $incident): void
    {
        if ((int) $incident->user_id !== (int) $actor->id) {
            throw new NotFoundHttpException('Not found.');
        }
    }

    private function assertMetricSupported(string $metric): void
    {
        if (! array_key_exists($metric, self::METRIC_DEFINITIONS)) {
            throw new \InvalidArgumentException('Unsupported metric.');
        }
    }

    private function assertOperatorSupported(string $operator): void
    {
        if (! in_array($operator, self::OPERATOR_OPTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported operator.');
        }
    }

    private function assertChannelSupported(string $channel): void
    {
        if (! in_array($channel, self::CHANNEL_OPTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported channel.');
        }
    }

    private function buildIncidentMessage(AlertRule $rule, float $value): string
    {
        $metricLabel = $this->alertTemplateService->metricLabel((string) $rule->metric);

        return sprintf(
            '%s: %s = %s (điều kiện %s %s)',
            $rule->name,
            $metricLabel,
            $this->formatMetricValue($value, $rule->metric),
            $rule->operator,
            $this->formatMetricValue((float) $rule->threshold, $rule->metric),
        );
    }

    private function formatMetricValue(float $value, string $metric): string
    {
        $unit = $this->alertTemplateService->metricUnit($metric);
        if ($unit === 'currency') {
            return number_format($value, 0, '.', ',') . ' đ';
        }

        if (fmod($value, 1.0) === 0.0) {
            return number_format($value, 0, '.', ',');
        }

        return number_format($value, 2, '.', ',');
    }

    private function compareMetric(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '==' => abs($value - $threshold) < 0.0001,
            '!=' => abs($value - $threshold) >= 0.0001,
            default => false,
        };
    }

    private function computeMetricValue(User $actor, string $metric): float
    {
        $scopeUserIds = $this->scopeResolver->resolveVisibleUserIds($actor);
        $from = now()->subDay();

        return match ($metric) {
            'sync_failed_24h' => (float) $this->scopedQuery(SyncRun::query(), $scopeUserIds)
                ->where('started_at', '>=', $from)
                ->where('status', 'LIKE', 'failed%')
                ->count(),
            'sync_warning_24h' => (float) $this->scopedQuery(SyncRun::query(), $scopeUserIds)
                ->where('started_at', '>=', $from)
                ->where('status', 'completed_with_warnings')
                ->count(),
            'unattributed_orders_24h' => (float) $this->scopedQuery(Order::query(), $scopeUserIds)
                ->where('ordered_at', '>=', $from)
                ->whereNull('tracking_link_id')
                ->count(),
            'unpaid_balance' => (float) $this->scopedQuery(AffiliateBilling::query(), $scopeUserIds)
                ->where(static function (Builder $query): void {
                    $query->whereRaw('LOWER(status) = ?', ['pending'])
                        ->orWhereRaw('LOWER(status) = ?', ['processing']);
                })
                ->sum('net_amount'),
            'pending_payouts' => (float) $this->scopedQuery(AffiliatePayout::query(), $scopeUserIds)
                ->where(static function (Builder $query): void {
                    $query->whereRaw('LOWER(status) = ?', ['pending'])
                        ->orWhereRaw('LOWER(status) = ?', ['processing'])
                        ->orWhereRaw('LOWER(status) = ?', ['queued']);
                })
                ->count(),
            default => 0.0,
        };
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @param  list<int>|null  $scopeUserIds
     * @return Builder<TModel>
     */
    private function scopedQuery(Builder $query, ?array $scopeUserIds): Builder
    {
        if ($scopeUserIds === null) {
            return $query;
        }

        return $query->whereIn('user_id', $scopeUserIds);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapRule(AlertRule $rule): array
    {
        $metricLabel = self::METRIC_DEFINITIONS[$rule->metric]['label'] ?? $rule->metric;

        return [
            'id' => (int) $rule->id,
            'name' => $this->sanitizeDisplayText((string) $rule->name, (string) $metricLabel),
            'metric' => (string) $rule->metric,
            'metric_label' => $metricLabel,
            'operator' => (string) $rule->operator,
            'threshold' => (float) $rule->threshold,
            'channel' => (string) $rule->channel,
            'is_active' => (bool) $rule->is_active,
            'last_triggered_at' => $rule->last_triggered_at?->toIso8601String(),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mapIncident(AlertIncident $incident): array
    {
        $ruleMetric = $incident->relationLoaded('rule') && $incident->rule !== null
            ? (string) $incident->rule->metric
            : (string) ($incident->context['metric'] ?? '');
        $metricLabel = self::METRIC_DEFINITIONS[$ruleMetric]['label'] ?? $ruleMetric;
        $messageFallback = $metricLabel !== '' ? '[' . $metricLabel . ']' : (string) $incident->message;

        return [
            'id' => (int) $incident->id,
            'alert_rule_id' => (int) $incident->alert_rule_id,
            'triggered_value' => (float) $incident->triggered_value,
            'message' => $this->sanitizeDisplayText((string) $incident->message, $messageFallback),
            'context' => $incident->context ?? [],
            'seen_at' => $incident->seen_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
            'rule' => $incident->relationLoaded('rule') && $incident->rule !== null
                ? [
                    'id' => (int) $incident->rule->id,
                    'name' => $this->sanitizeDisplayText(
                        (string) $incident->rule->name,
                        (string) (self::METRIC_DEFINITIONS[$incident->rule->metric]['label'] ?? $incident->rule->metric),
                    ),
                    'metric' => (string) $incident->rule->metric,
                    'metric_label' => self::METRIC_DEFINITIONS[$incident->rule->metric]['label'] ?? $incident->rule->metric,
                    'operator' => (string) $incident->rule->operator,
                    'threshold' => (float) $incident->rule->threshold,
                    'channel' => (string) $incident->rule->channel,
                ]
                : null,
        ];
    }

    private function dispatchNotifications(AlertRule $rule, AlertIncident $incident): void
    {
        if (! in_array($rule->channel, ['telegram', 'both'], true)) {
            return;
        }

        $config = $this->telegramConfigService->findEnabledByUserId((int) $rule->user_id);
        if ($config === null) {
            return;
        }

        SendTelegramAlertJob::dispatch((int) $incident->id);
    }

    /**
     * @return array<string,string>
     */
    private function buildTemplateVariables(AlertRule $rule, float $triggeredValue): array
    {
        $connectionName = PlatformConnection::query()
            ->where('user_id', $rule->user_id)
            ->orderByDesc('updated_at')
            ->value('label');

        return [
            'metric_label' => $this->alertTemplateService->metricLabel((string) $rule->metric),
            'triggered_value' => $this->formatMetricValue($triggeredValue, (string) $rule->metric),
            'operator' => (string) $rule->operator,
            'threshold' => $this->formatMetricValue((float) $rule->threshold, (string) $rule->metric),
            'detected_at' => now()->format('d/m/Y H:i'),
            'connection_name' => (string) ($connectionName ?: 'N/A'),
        ];
    }

    private function isManualResolveLocked(?AlertIncident $incident): bool
    {
        if ($incident === null || $incident->resolved_at === null || ! is_array($incident->context)) {
            return false;
        }

        return (bool) ($incident->context['manual_resolve_lock'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function appendIncidentComment(array $context, User $actor, string $comment): array
    {
        $comments = is_array($context['comments'] ?? null) ? $context['comments'] : [];
        $comments[] = [
            'id' => (string) str()->uuid(),
            'comment' => $comment,
            'created_at' => now()->toIso8601String(),
            'user_id' => (int) $actor->id,
            'user_name' => (string) $actor->name,
        ];

        $context['comments'] = $comments;

        return $context;
    }

    private function sanitizeDisplayText(string $text, string $fallback = ''): string
    {
        $value = trim($text);
        if ($value === '') {
            return $fallback;
        }

        if (! mb_check_encoding($value, 'UTF-8') || $this->looksMojibake($value)) {
            return $fallback !== '' ? $fallback : $value;
        }

        return $value;
    }

    private function looksMojibake(string $text): bool
    {
        return str_contains($text, 'Ã')
            || str_contains($text, 'á»')
            || str_contains($text, 'â€')
            || str_contains($text, '�');
    }
}
