<?php

declare(strict_types=1);

namespace App\Http\Resources\Clicks;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @mixin LengthAwarePaginator
 */
class ClickReportCollection extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $this->resource;

        $items = collect($paginator->items())
            ->map(fn ($item): array => (new ClickReportItemResource($item))->resolve($request))
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
        ];
    }
}
