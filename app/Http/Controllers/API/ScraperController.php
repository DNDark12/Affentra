<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\AI\ScrapeProductRequest;
use App\Services\Scraping\ProductScraperService;
use Illuminate\Http\JsonResponse;
use Exception;

class ScraperController extends Controller
{
    private ProductScraperService $scraper;

    public function __construct(ProductScraperService $scraper)
    {
        $this->scraper = $scraper;
    }

    /**
     * Scrape a product URL for AI Content Studio Autofill.
     * 
     * @param ScrapeProductRequest $request
     * @return JsonResponse
     */
    public function scrapeProduct(ScrapeProductRequest $request): JsonResponse
    {
        try {
            $result = $this->scraper->scrape($request->validated('url'));

            return response()->json([
                'ok' => true,
                'data' => $result['data'],
                'missing_fields' => $result['missing_fields'],
                'confidence' => $result['confidence'],
                'source' => $result['source']
            ]);
        } catch (Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage()
            ], 422); // Reusing 422 for Scraper Errors as per typical validation/processing error representation
        }
    }
}
