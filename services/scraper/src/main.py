"""
Shopee Scraper Microservice — FastAPI Application.

Provides a REST API that Affentra (Laravel) calls to scrape
product data from Shopee's Affiliate Portal using user cookies.
"""
import asyncio
import logging
import time
from contextlib import asynccontextmanager
from datetime import datetime, timezone

from fastapi import FastAPI, HTTPException, Header, Query, Body
from pydantic import BaseModel, Field
from typing import Any

from .config import settings
from . import scraper as scraper_module
from .scraper import scraper

# --- Logging ---
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("main")


# ======================================================
# Rate Limiter (Token Bucket)
# ======================================================


class RateLimiter:
    """Simple in-memory token bucket rate limiter."""

    def __init__(self, max_requests: int = 10, window_seconds: int = 60):
        self.max_requests = max_requests
        self.window_seconds = window_seconds
        self._requests: list[float] = []
        self._lock = asyncio.Lock()

    async def is_allowed(self) -> bool:
        async with self._lock:
            now = time.monotonic()
            self._requests = [t for t in self._requests if now - t < self.window_seconds]
            if len(self._requests) >= self.max_requests:
                return False
            self._requests.append(now)
            return True

    @property
    def remaining(self) -> int:
        now = time.monotonic()
        active = [t for t in self._requests if now - t < self.window_seconds]
        return max(0, self.max_requests - len(active))

    @property
    def retry_after(self) -> int:
        if not self._requests:
            return 0
        oldest = min(self._requests)
        return max(1, int(self.window_seconds - (time.monotonic() - oldest)))


rate_limiter = RateLimiter(
    max_requests=settings.rate_limit_requests,
    window_seconds=settings.rate_limit_window,
)


# ======================================================
# Pydantic Models
# ======================================================


class ProductData(BaseModel):
    item_id: str
    shop_id: str = ""
    item_name: str | None = None
    price_min: int | None = None
    price_max: int | None = None
    image_url: str | None = None
    images: list[str] = []
    sales: int | None = None
    rating_star: float | None = None
    commission_rate: float | None = None
    source: str | None = None
    refreshed_cookie: str | None = None


class ApiResponse(BaseModel):
    ok: bool
    data: ProductData | None = None
    message: str | None = None
    fetched_at: str | None = None
    error_code: str | None = None
    retry_after_seconds: int | None = None


class SearchResponse(BaseModel):
    ok: bool
    data: list[ProductData] = []
    message: str | None = None
    fetched_at: str | None = None
    error_code: str | None = None


class ScrapeRequest(BaseModel):
    """Request body containing cookies for affiliate portal access."""
    item_id: str
    shop_id: str | None = None
    cookies: str  # Raw cookie string from user browser


class SearchRequest(BaseModel):
    """Request body for product search."""
    keyword: str
    cookies: str
    page_limit: int = 20


class ProxyRequest(BaseModel):
    """
    Generic proxy request — passes HTTP call through Camoufox (Firefox TLS fingerprint).
    `cookies` is the raw Cookie: header string from PlatformConnection.cookie_raw.
    Only *.shopee.vn targets are allowed (SSRF guard enforced in scraper).
    """
    url: str
    method: str = "GET"         # GET | POST
    headers: dict[str, Any] = Field(default_factory=dict)
    params: dict[str, Any] = Field(default_factory=dict)
    body: dict[str, Any] | None = None
    cookies: str = ""           # Raw cookie header string
    timeout_ms: int | None = None


class ProxyResponse(BaseModel):
    status: int
    headers: dict[str, str] = Field(default_factory=dict)
    json: dict | list | None = None
    text: str | None = None
    content_type: str = ""
    ok: bool
    error: str | None = None
    error_type: str | None = None
    url_final: str | None = None
    text_truncated: bool = False
    blocked_hint: bool = False
    # Cookie rotation fields
    refreshed_cookie: str | None = None
    cookie_rotatable: bool = False
    cookie_hash: str | None = None


# ======================================================
# App Lifecycle
# ======================================================


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("=== Shopee Scraper Service starting ===")
    await scraper.start()
    logger.info("=== Browser ready, accepting requests ===")
    yield
    logger.info("=== Shutting down browser ===")
    await scraper.stop()
    logger.info("=== Service stopped ===")


app = FastAPI(
    title="Shopee Affiliate Scraper Service",
    description="Internal microservice — scrapes Shopee Affiliate Portal with user cookies",
    version="2.0.0",
    lifespan=lifespan,
)


# ======================================================
# Auth
# ======================================================


def verify_token(x_internal_token: str | None = Header(None)):
    if settings.api_secret_key == "dev-secret-key":
        return
    if x_internal_token != settings.api_secret_key:
        raise HTTPException(status_code=401, detail="Unauthorized")


# ======================================================
# Endpoints
# ======================================================


@app.get("/health")
async def health_check():
    return {
        "status": "healthy",
        "service": "shopee-affiliate-scraper",
        "browser_active": scraper_module._browser is not None,
        "rate_limit_remaining": rate_limiter.remaining,
    }


@app.post("/api/v1/shopee/product", response_model=ApiResponse)
async def scrape_product(
    body: ScrapeRequest,
    x_internal_token: str | None = Header(None),
):
    """
    Scrape a product from Shopee Affiliate Portal using cookies.

    Called by: Affentra Laravel (ProductScraperService.php)
    Cookies are passed from user's PlatformConnection.
    """
    verify_token(x_internal_token)

    if not body.item_id.isdigit():
        return ApiResponse(ok=False, message="item_id must be numeric", error_code="INVALID_INPUT")

    if not body.cookies or len(body.cookies) < 50:
        return ApiResponse(ok=False, message="Valid cookies are required", error_code="MISSING_COOKIES")

    if not await rate_limiter.is_allowed():
        return ApiResponse(
            ok=False,
            message=f"Rate limit exceeded. Try again in {rate_limiter.retry_after}s.",
            error_code="RATE_LIMITED",
            retry_after_seconds=rate_limiter.retry_after,
        )

    now = datetime.now(timezone.utc).isoformat()
    logger.info(f"Scrape request: item_id={body.item_id}, shop_id={body.shop_id or 'N/A'}")

    data = await scraper.scrape_product(
        item_id=body.item_id,
        cookies=body.cookies,
        shop_id=body.shop_id,
    )

    if data is None:
        return ApiResponse(
            ok=False,
            message="Could not retrieve product data. Cookies may be expired.",
            error_code="SCRAPE_FAILED",
            fetched_at=now,
        )

    return ApiResponse(
        ok=True,
        data=ProductData(**data),
        message="Scraped successfully",
        fetched_at=now,
    )


@app.post("/api/v1/shopee/search", response_model=SearchResponse)
async def search_products(
    body: SearchRequest,
    x_internal_token: str | None = Header(None),
):
    """
    Search for products on Shopee Affiliate Portal.

    Uses the user's cookies to access the affiliate search page.
    """
    verify_token(x_internal_token)

    if not body.keyword or len(body.keyword.strip()) < 2:
        return SearchResponse(ok=False, message="Keyword must be at least 2 characters", error_code="INVALID_INPUT")

    if not body.cookies or len(body.cookies) < 50:
        return SearchResponse(ok=False, message="Valid cookies are required", error_code="MISSING_COOKIES")

    if not await rate_limiter.is_allowed():
        return SearchResponse(
            ok=False,
            message=f"Rate limit exceeded. Try again in {rate_limiter.retry_after}s.",
            error_code="RATE_LIMITED",
        )

    now = datetime.now(timezone.utc).isoformat()
    logger.info(f"Search request: keyword='{body.keyword}'")

    results = await scraper.search_products(
        keyword=body.keyword,
        cookies=body.cookies,
        page_limit=body.page_limit,
    )

    return SearchResponse(
        ok=True,
        data=[ProductData(**r) for r in results],
        message=f"Found {len(results)} products",
        fetched_at=now,
    )


@app.post("/api/v1/shopee/proxy", response_model=ProxyResponse)
async def proxy_request(
    body: ProxyRequest,
    x_internal_token: str | None = Header(None),
):
    """
    Generic HTTP proxy through Camoufox (Firefox TLS fingerprint).

    Called by: Affentra Laravel (ShopeeIntegration.php::proxyToPythonScraper)
    Only *.shopee.vn targets are allowed — SSRF guard enforced in scraper.
    Cookies passed as raw header string (never logged).

    Returns structured response with status, json/text, error_type, and blocked_hint.
    """
    verify_token(x_internal_token)

    if not body.url:
        return ProxyResponse(
            status=400,
            ok=False,
            error="url is required",
            error_type="unknown",
        )

    if not await rate_limiter.is_allowed():
        return ProxyResponse(
            status=429,
            ok=False,
            error=f"Rate limit exceeded. Try again in {rate_limiter.retry_after}s.",
            error_type="rate_limited",
        )

    logger.info(f"Proxy request: method={body.method} url={body.url}")

    result = await scraper.proxy_request(
        url=body.url,
        method=body.method,
        headers=body.headers,
        params=body.params,
        body=body.body,
        cookie_raw=body.cookies,
        timeout_ms=body.timeout_ms,
    )

    return ProxyResponse(**result.to_dict())
