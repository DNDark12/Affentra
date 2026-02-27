<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Helpers\ApiResponse;
use App\Http\Requests\Integrations\UploadPortalExportRequest;
use App\Models\PlatformConnection;
use App\Services\Integration\PortalExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PortalExportController extends Controller
{
    public function upload(UploadPortalExportRequest $request, PlatformConnection $connection): JsonResponse
    {
        // Validate user owns connection or is owner
        if ($connection->user_id !== $request->user()->id && ! $request->user()->isOwner()) {
            return ApiResponse::error('Unauthorized', [], 403);
        }

        // Validate method is portal_export
        if ($connection->method !== 'portal_export') {
            return ApiResponse::error('This connection does not support portal export.', [], 422);
        }

        $type = $request->validated('type');
        $file = $request->file('file');

        // Logic for handling file upload will go to PortalExportService
        $service = app(PortalExportService::class);
        $service->process($connection, $type, $file);
        
        return ApiResponse::success(null, ucfirst($type) . ' file uploaded successfully (processing pending).');
    }
}
