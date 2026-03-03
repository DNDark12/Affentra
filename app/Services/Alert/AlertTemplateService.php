<?php

declare(strict_types=1);

namespace App\Services\Alert;

use App\Models\AlertMessageTemplate;
use App\Models\User;
use App\Support\AlertRuleContract;

class AlertTemplateService
{
    /**
     * @var array<string, array{
     *   label:string,
     *   unit:string,
     *   description:string,
     *   default_in_app:string,
     *   default_telegram:string
     * }>
     */
    private const METRIC_DEFINITIONS = [
        'sync_failed_24h' => [
            'label' => 'Đồng bộ lỗi (24h)',
            'unit' => 'count',
            'description' => 'Số lượt đồng bộ thất bại trong 24 giờ gần nhất.',
            'default_in_app' => '[{metric_label}] Có {triggered_value} lỗi đồng bộ trong 24h, vượt ngưỡng {operator} {threshold} lúc {detected_at}. Kết nối: {connection_name}.',
            'default_telegram' => "🚨 Affentra Alert\n{metric_label}\nGiá trị: {triggered_value}\nNgưỡng: {operator} {threshold}\nKết nối: {connection_name}\nThời điểm: {detected_at}",
        ],
        'sync_warning_24h' => [
            'label' => 'Đồng bộ cảnh báo (24h)',
            'unit' => 'count',
            'description' => 'Số lượt đồng bộ completed_with_warnings trong 24 giờ.',
            'default_in_app' => '[{metric_label}] Hệ thống ghi nhận {triggered_value} cảnh báo trong 24h (ngưỡng {operator} {threshold}) vào {detected_at}. Kết nối: {connection_name}.',
            'default_telegram' => "⚠️ Affentra Alert\n{metric_label}\nGiá trị: {triggered_value}\nNgưỡng: {operator} {threshold}\nKết nối: {connection_name}\nThời điểm: {detected_at}",
        ],
        'unattributed_orders_24h' => [
            'label' => 'Đơn chưa gán link (24h)',
            'unit' => 'count',
            'description' => 'Số đơn hàng chưa map được tracking_link trong 24 giờ.',
            'default_in_app' => '[{metric_label}] Có {triggered_value} đơn chưa gán link trong 24h, điều kiện {operator} {threshold} tại {detected_at}. Kết nối: {connection_name}.',
            'default_telegram' => "📦 Affentra Alert\n{metric_label}\nGiá trị: {triggered_value}\nNgưỡng: {operator} {threshold}\nKết nối: {connection_name}\nThời điểm: {detected_at}",
        ],
        'unpaid_balance' => [
            'label' => 'Số dư chờ thanh toán',
            'unit' => 'currency',
            'description' => 'Tổng net_amount ở trạng thái pending/processing.',
            'default_in_app' => '[{metric_label}] Số dư chờ thanh toán hiện là {triggered_value}, điều kiện {operator} {threshold}. Phát hiện lúc {detected_at}.',
            'default_telegram' => "💰 Affentra Alert\n{metric_label}\nGiá trị: {triggered_value}\nNgưỡng: {operator} {threshold}\nKết nối: {connection_name}\nThời điểm: {detected_at}",
        ],
        'pending_payouts' => [
            'label' => 'Payout chờ xử lý',
            'unit' => 'count',
            'description' => 'Số payout chờ xử lý theo trạng thái pending/processing.',
            'default_in_app' => '[{metric_label}] Có {triggered_value} payout đang chờ xử lý (điều kiện {operator} {threshold}) lúc {detected_at}. Kết nối: {connection_name}.',
            'default_telegram' => "🏦 Affentra Alert\n{metric_label}\nGiá trị: {triggered_value}\nNgưỡng: {operator} {threshold}\nKết nối: {connection_name}\nThời điểm: {detected_at}",
        ],
    ];

    /**
     * @return list<array{token:string,label:string,sample:string}>
     */
    public function templateVariables(): array
    {
        return [
            ['token' => '{metric_label}', 'label' => 'Tên chỉ số', 'sample' => 'Đồng bộ lỗi (24h)'],
            ['token' => '{triggered_value}', 'label' => 'Giá trị thực tế', 'sample' => '5'],
            ['token' => '{operator}', 'label' => 'Toán tử điều kiện', 'sample' => '>='],
            ['token' => '{threshold}', 'label' => 'Ngưỡng cảnh báo', 'sample' => '3'],
            ['token' => '{detected_at}', 'label' => 'Thời điểm phát hiện', 'sample' => now()->format('d/m/Y H:i')],
            ['token' => '{connection_name}', 'label' => 'Tên kết nối', 'sample' => 'Shopee Test Connection'],
        ];
    }

    /**
     * @return list<array{value:string,label:string,unit:string,description:string}>
     */
    public function metricOptions(): array
    {
        $options = [];
        foreach (AlertRuleContract::METRICS as $metric) {
            $definition = self::METRIC_DEFINITIONS[$metric];
            $options[] = [
                'value' => $metric,
                'label' => $definition['label'],
                'unit' => $definition['unit'],
                'description' => $definition['description'],
            ];
        }

        return $options;
    }

    /**
     * @return array{
     *   metric_options:list<array{value:string,label:string,unit:string,description:string}>,
     *   template_variables:list<array{token:string,label:string,sample:string}>,
     *   templates:array<string, array{
     *     in_app_template:string,
     *     telegram_template:string,
     *     is_custom:bool,
     *     updated_at:?string
     *   }>,
     *   active_metric:string
     * }
     */
    public function pageData(User $actor): array
    {
        $templates = $this->templatesByMetric($actor);

        return [
            'metric_options' => $this->metricOptions(),
            'template_variables' => $this->templateVariables(),
            'templates' => $templates,
            'active_metric' => AlertRuleContract::METRICS[0],
        ];
    }

    /**
     * @return array<string, array{
     *   in_app_template:string,
     *   telegram_template:string,
     *   is_custom:bool,
     *   updated_at:?string
     * }>
     */
    public function templatesByMetric(User $actor): array
    {
        $rows = AlertMessageTemplate::query()
            ->where('user_id', $actor->id)
            ->whereIn('metric', AlertRuleContract::METRICS)
            ->get()
            ->keyBy('metric');

        $payload = [];
        foreach (AlertRuleContract::METRICS as $metric) {
            /** @var AlertMessageTemplate|null $row */
            $row = $rows->get($metric);
            $defaults = $this->defaultTemplates($metric);
            $payload[$metric] = [
                'in_app_template' => $row?->in_app_template ?? $defaults['in_app_template'],
                'telegram_template' => $row?->telegram_template ?? $defaults['telegram_template'],
                'is_custom' => $row !== null,
                'updated_at' => $row?->updated_at?->toIso8601String(),
            ];
        }

        return $payload;
    }

    public function saveMetric(User $actor, string $metric, string $inAppTemplate, string $telegramTemplate): AlertMessageTemplate
    {
        $this->assertMetricSupported($metric);

        return AlertMessageTemplate::query()->updateOrCreate(
            [
                'user_id' => $actor->id,
                'metric' => $metric,
            ],
            [
                'in_app_template' => trim($inAppTemplate),
                'telegram_template' => trim($telegramTemplate),
            ],
        );
    }

    /**
     * @param  list<array{
     *   metric:string,
     *   in_app_template:string,
     *   telegram_template:string
     * }>  $templates
     */
    public function saveAll(User $actor, array $templates): void
    {
        foreach ($templates as $template) {
            $this->saveMetric(
                $actor,
                (string) $template['metric'],
                (string) $template['in_app_template'],
                (string) $template['telegram_template'],
            );
        }
    }

    public function restoreMetric(User $actor, string $metric): void
    {
        $this->assertMetricSupported($metric);

        AlertMessageTemplate::query()
            ->where('user_id', $actor->id)
            ->where('metric', $metric)
            ->delete();
    }

    /**
     * @return array{in_app_template:string,telegram_template:string}
     */
    public function resolveTemplates(int $userId, string $metric): array
    {
        $this->assertMetricSupported($metric);

        $row = AlertMessageTemplate::query()
            ->where('user_id', $userId)
            ->where('metric', $metric)
            ->first();

        if ($row !== null) {
            return [
                'in_app_template' => (string) $row->in_app_template,
                'telegram_template' => (string) $row->telegram_template,
            ];
        }

        return $this->defaultTemplates($metric);
    }

    public function metricLabel(string $metric): string
    {
        return self::METRIC_DEFINITIONS[$metric]['label'] ?? $metric;
    }

    public function metricUnit(string $metric): string
    {
        return self::METRIC_DEFINITIONS[$metric]['unit'] ?? 'count';
    }

    /**
     * @param  array<string,string>  $variables
     */
    public function render(string $template, array $variables): string
    {
        $replacePairs = [];
        foreach ($variables as $key => $value) {
            $replacePairs['{' . $key . '}'] = $value;
        }

        return strtr($template, $replacePairs);
    }

    /**
     * @return array{in_app_template:string,telegram_template:string}
     */
    public function defaultTemplates(string $metric): array
    {
        $this->assertMetricSupported($metric);

        return [
            'in_app_template' => self::METRIC_DEFINITIONS[$metric]['default_in_app'],
            'telegram_template' => self::METRIC_DEFINITIONS[$metric]['default_telegram'],
        ];
    }

    private function assertMetricSupported(string $metric): void
    {
        if (! in_array($metric, AlertRuleContract::METRICS, true)) {
            throw new \InvalidArgumentException('Unsupported metric.');
        }
    }
}

