<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Alerts\AddIncidentCommentRequest;
use App\Http\Requests\Alerts\UpdateAllAlertTemplatesRequest;
use App\Http\Requests\Alerts\ResolveIncidentRequest;
use App\Http\Requests\Alerts\StoreAlertRuleRequest;
use App\Http\Requests\Alerts\TestTelegramConfigRequest;
use App\Http\Requests\Alerts\ToggleAlertRuleRequest;
use App\Http\Requests\Alerts\UpdateAlertRuleRequest;
use App\Http\Requests\Alerts\UpdateAlertTemplateRequest;
use App\Http\Requests\Alerts\UpdateTelegramConfigRequest;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Services\Alert\AlertService;
use App\Services\Alert\AlertTemplateService;
use App\Services\Alert\TelegramConfigService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AlertController extends Controller
{
    public function __construct(
        private readonly AlertService $alertService,
        private readonly AlertTemplateService $alertTemplateService,
        private readonly TelegramConfigService $telegramConfigService,
    ) {}

    public function index(Request $request): Response
    {
        $payload = $this->alertService->indexData($request->user(), [
            'status' => $request->query('status'),
        ]);

        return Inertia::render('Alerts/Index', [
            'incidents' => $payload['incidents'],
            'summary' => $payload['summary'],
            'filters' => $payload['filters'],
        ]);
    }

    public function rules(Request $request): Response
    {
        $payload = $this->alertService->rulesData($request->user(), [
            'channel' => $request->query('channel'),
        ]);

        return Inertia::render('Alerts/Rules', $payload);
    }

    public function telegramConfig(Request $request): Response
    {
        $payload = $this->alertService->telegramConfigData($request->user());

        return Inertia::render('Alerts/TelegramConfig', $payload);
    }

    public function templates(Request $request): Response
    {
        $payload = $this->alertTemplateService->pageData($request->user());

        return Inertia::render('Alerts/Templates', $payload);
    }

    public function showIncident(Request $request, AlertIncident $incident): Response
    {
        $this->alertService->markIncidentSeen($request->user(), $incident);
        $payload = $this->alertService->incidentDetail($request->user(), $incident);

        return Inertia::render('Alerts/IncidentDetail', [
            'incident' => $payload['incident'],
            'rule' => $payload['rule'],
            'timeline' => $payload['timeline'],
        ]);
    }

    public function evaluate(Request $request): JsonResponse
    {
        $result = $this->alertService->evaluateActiveRules($request->user());

        return ApiResponse::success($result, 'Đã đánh giá quy tắc cảnh báo.');
    }

    public function storeRule(StoreAlertRuleRequest $request): JsonResponse
    {
        try {
            $rule = $this->alertService->createRule($request->user(), $request->validated());

            return ApiResponse::created([
                'id' => (int) $rule->id,
            ], 'Đã tạo quy tắc cảnh báo.');
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }
    }

    public function updateRule(UpdateAlertRuleRequest $request, AlertRule $alertRule): JsonResponse
    {
        try {
            $rule = $this->alertService->updateRule($request->user(), $alertRule, $request->validated());

            return ApiResponse::success([
                'id' => (int) $rule->id,
            ], 'Đã cập nhật quy tắc cảnh báo.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }
    }

    public function destroyRule(Request $request, AlertRule $alertRule): JsonResponse
    {
        try {
            $this->alertService->deleteRule($request->user(), $alertRule);

            return ApiResponse::success(null, 'Đã xóa quy tắc cảnh báo.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        }
    }

    public function toggleRule(ToggleAlertRuleRequest $request, AlertRule $alertRule): JsonResponse
    {
        try {
            $rule = $this->alertService->toggleRule(
                $request->user(),
                $alertRule,
                $request->validated()['is_active'] ?? null,
            );

            return ApiResponse::success([
                'id' => (int) $rule->id,
                'is_active' => (bool) $rule->is_active,
            ], 'Đã cập nhật trạng thái rule.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        } catch (AuthorizationException) {
            return ApiResponse::forbidden('Forbidden');
        }
    }

    public function markSeen(Request $request, AlertIncident $incident): JsonResponse
    {
        try {
            $updated = $this->alertService->markIncidentSeen($request->user(), $incident);

            return ApiResponse::success([
                'id' => (int) $updated->id,
                'seen_at' => $updated->seen_at?->toIso8601String(),
            ], 'Đã đánh dấu đã xem.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        }
    }

    public function resolve(ResolveIncidentRequest $request, AlertIncident $incident): JsonResponse
    {
        try {
            $comment = trim((string) ($request->validated()['comment'] ?? ''));
            if ($comment !== '') {
                $updated = $this->alertService->resolveIncidentWithComment(
                    $request->user(),
                    $incident,
                    $comment,
                );
            } else {
                $updated = $this->alertService->resolveIncident(
                    $request->user(),
                    $incident,
                );
            }

            return ApiResponse::success([
                'id' => (int) $updated->id,
                'resolved_at' => $updated->resolved_at?->toIso8601String(),
            ], 'Đã đánh dấu đã xử lý.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        }
    }

    public function addComment(AddIncidentCommentRequest $request, AlertIncident $incident): JsonResponse
    {
        try {
            $updated = $this->alertService->addIncidentComment(
                $request->user(),
                $incident,
                (string) $request->validated()['comment'],
            );

            return ApiResponse::success([
                'id' => (int) $updated->id,
                'context' => $updated->context ?? [],
            ], 'Đã lưu ghi chú xử lý.');
        } catch (NotFoundHttpException) {
            return ApiResponse::notFound('Not found.');
        }
    }

    public function getTelegramConfig(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'telegram' => $this->telegramConfigService->status($request->user()),
        ]);
    }

    public function updateTelegramConfig(UpdateTelegramConfigRequest $request): JsonResponse
    {
        try {
            $config = $this->telegramConfigService->update($request->user(), $request->validated());
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::success([
            'id' => (int) $config->id,
            'telegram' => $this->telegramConfigService->status($request->user()),
        ], 'Đã lưu cấu hình Telegram.');
    }

    public function testTelegramConfig(TestTelegramConfigRequest $request): JsonResponse
    {
        $result = $this->telegramConfigService->test($request->user(), $request->validated());

        if (! $result['ok']) {
            return ApiResponse::error($result['message'], [
                'telegram' => $this->telegramConfigService->status($request->user()),
                'error_code' => $result['error_code'] ?? null,
            ], 422);
        }

        return ApiResponse::success([
            'telegram' => $this->telegramConfigService->status($request->user()),
            'error_code' => $result['error_code'] ?? null,
        ], $result['message']);
    }

    public function deleteTelegramConfig(Request $request): JsonResponse
    {
        $this->telegramConfigService->delete($request->user());

        return ApiResponse::success([
            'telegram' => $this->telegramConfigService->status($request->user()),
        ], 'Đã xóa cấu hình Telegram.');
    }

    public function getTemplates(Request $request): JsonResponse
    {
        return ApiResponse::success($this->alertTemplateService->pageData($request->user()));
    }

    public function updateTemplate(UpdateAlertTemplateRequest $request, string $metric): JsonResponse
    {
        try {
            $this->alertTemplateService->saveMetric(
                $request->user(),
                $metric,
                (string) $request->validated()['in_app_template'],
                (string) $request->validated()['telegram_template'],
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::success([
            'templates' => $this->alertTemplateService->templatesByMetric($request->user()),
        ], 'Đã lưu template cho chỉ số.');
    }

    public function updateTemplates(UpdateAllAlertTemplatesRequest $request): JsonResponse
    {
        try {
            $this->alertTemplateService->saveAll(
                $request->user(),
                $request->validated()['templates'],
            );
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::success([
            'templates' => $this->alertTemplateService->templatesByMetric($request->user()),
        ], 'Đã lưu toàn bộ template.');
    }

    public function restoreTemplate(Request $request, string $metric): JsonResponse
    {
        try {
            $this->alertTemplateService->restoreMetric($request->user(), $metric);
        } catch (\InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), [], 422);
        }

        return ApiResponse::success([
            'templates' => $this->alertTemplateService->templatesByMetric($request->user()),
        ], 'Đã khôi phục template mặc định.');
    }
}
