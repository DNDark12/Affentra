"""
Shopee Affiliate Scraper — Core scraping logic using Camoufox + Playwright.

This module scrapes product data from Shopee's Affiliate Portal
(affiliate.shopee.vn) using user-provided cookies, bypassing the
public Shopee site which blocks datacenter IPs.

Approach:
  1. Load affiliate.shopee.vn with user cookies in Camoufox
  2. Navigate to product offer page → parse rendered SPA
  3. OR call affiliate API directly with cookies + intercepted anti-bot headers
  4. OR proxy individual HTTP requests via context.request (no page rendering)
"""
import asyncio
import hashlib
import ipaddress
import json
import logging
import re
import socket
import uuid
from dataclasses import dataclass, field, asdict
from enum import Enum
from typing import Any
from urllib.parse import urlparse

from bs4 import BeautifulSoup

def parse_raw_cookies(raw_cookies: str, domain: str) -> list:
    """
    Parse a raw cookie string into a list of dictionaries as expected by Playwright.
    """
    if not raw_cookies:
        return []

    cookies = []
    for item in raw_cookies.split(';'):
        if '=' in item:
            name, value = item.strip().split('=', 1)
            cookies.append({
                "name": name.strip(),
                "value": value.strip(),
                "domain": domain,
                "path": "/"
            })
    return cookies

from camoufox.async_api import AsyncCamoufox
from playwright.async_api import Page, BrowserContext

from .config import settings

logger = logging.getLogger("scraper")

# Module-level browser reference (kept alive during app lifespan)
_browser = None


# ==============================================================
# SSRF Protection: URL Validation
# ==============================================================

ALLOWED_HOSTS = frozenset({"shopee.vn", "affiliate.shopee.vn"})
ALLOWED_HOST_SUFFIX = ".shopee.vn"


def validate_proxy_url(url: str) -> str:
    """
    Validate a URL for proxy forwarding.
    - HTTPS only
    - Domain allowlist: shopee.vn, *.shopee.vn
    - DNS pre-resolve: reject private/loopback/reserved IPs
    Raises ValueError on validation failure.
    """
    parsed = urlparse(url)

    # 1. Scheme allowlist
    if parsed.scheme != "https":
        raise ValueError(f"Only HTTPS allowed, got: {parsed.scheme}")

    # 2. Host must be present
    host = parsed.hostname
    if not host:
        raise ValueError("Missing hostname")

    # 3. Domain allowlist (exact or suffix match)
    host_lower = host.lower()
    if host_lower not in ALLOWED_HOSTS:
        # Must end with ".shopee.vn" with a dot boundary
        # This prevents "evilshopee.vn" from passing
        if not host_lower.endswith(ALLOWED_HOST_SUFFIX):
            raise ValueError(f"Host not in allowlist: {host}")
        # Verify there's a dot boundary (not just suffix match like "evilshopee.vn")
        prefix_len = len(host_lower) - len(ALLOWED_HOST_SUFFIX)
        if prefix_len <= 0:
            raise ValueError(f"Host not in allowlist: {host}")
        # The suffix check with endswith already ensures the dot is included
        # since ALLOWED_HOST_SUFFIX starts with "."

    # 4. DNS resolve → reject private IPs
    try:
        resolved = socket.getaddrinfo(host, None, socket.AF_UNSPEC)
        for family, _, _, _, sockaddr in resolved:
            ip = ipaddress.ip_address(sockaddr[0])
            if ip.is_private or ip.is_loopback or ip.is_reserved or ip.is_link_local:
                raise ValueError(f"Resolved to private/reserved IP: {ip}")
    except socket.gaierror as e:
        raise ValueError(f"DNS resolution failed for {host}: {e}")

    return url


# ==============================================================
# Header Normalizer
# ==============================================================

# Hop-by-hop headers that must be stripped for proxy forwarding
HOP_BY_HOP_HEADERS = frozenset({
    "host", "connection", "keep-alive", "proxy-authenticate",
    "proxy-authorization", "te", "trailers", "transfer-encoding",
    "upgrade", "content-length",
})

# Playwright manages compression — strip to avoid double-decompression
STRIP_ENCODING = frozenset({"accept-encoding"})


def normalize_headers(raw: dict[str, object] | None) -> dict[str, str]:
    """
    Normalize request headers for proxy forwarding.
    - Strip hop-by-hop headers
    - Strip accept-encoding (Playwright handles)
    - Coerce non-string values to string
    - Drop empty/null keys
    - Pass-through everything else as provided by Laravel
    """
    result: dict[str, str] = {}
    if not isinstance(raw, dict):
        return result

    for key, value in raw.items():
        if key is None:
            continue
        key_str = str(key).strip()
        if not key_str:
            continue

        key_lower = key_str.lower()
        if key_lower in HOP_BY_HOP_HEADERS:
            continue
        if key_lower in STRIP_ENCODING:
            continue

        if value is None:
            continue
        result[key_str] = str(value)
    return result


def normalize_params(raw: dict[str, object] | None) -> dict[str, str]:
    """
    Normalize query params to strings for Playwright APIRequestContext.
    Drops null/empty keys and null values.
    """
    result: dict[str, str] = {}
    if not isinstance(raw, dict):
        return result

    for key, value in raw.items():
        if key is None or value is None:
            continue
        key_str = str(key).strip()
        if not key_str:
            continue
        result[key_str] = str(value)
    return result


# ==============================================================
# Shopee Anti-Bot: Static Headers & Cookie Classification
# ==============================================================

# Cookies Shopee dùng để validate session và fingerprint.
# Tất cả endpoints của cùng 1 user dùng chung 1 bộ cookie này.
ESSENTIAL_COOKIE_NAMES: frozenset[str] = frozenset({
    # Core auth — ít thay đổi
    "SPC_F",          # Device fingerprint token (permanent)
    "SPC_CLIENTID",   # Client identifier
    "SPC_U",          # User ID (plain text)
    "SPC_EC",         # Encrypted session credential
    "SPC_ST",         # Short-term session token
    # Token refresh pair — rotate theo thời gian
    "SPC_T_ID",
    "SPC_T_IV",
    "SPC_R_T_ID",
    "SPC_R_T_IV",
    "SPC_SI",         # Session identifier
    # CSRF
    "csrftoken",
    # Fingerprint / anti-bot validation
    "ds",                    # Device session hash
    "SC_DFP",               # Device fingerprint
    "shopee_webUnique_ccd", # Linked to af-ac-enc-sz-token header
    # Misc session
    "language",
    "shopee_token",
})

# Headers tĩnh — giống nhau cho mọi request đến affiliate.shopee.vn
SHOPEE_STATIC_HEADERS: dict[str, str] = {
    "accept": "application/json, text/plain, */*",
    "accept-language": "vi-VN,vi;q=0.9,fr-FR;q=0.8,fr;q=0.7,en-US;q=0.6,en;q=0.5",
    "affiliate-program-type": "1",
    "priority": "u=1, i",
    "sec-ch-ua": '"Not:A-Brand";v="99", "Google Chrome";v="145", "Chromium";v="145"',
    "sec-ch-ua-mobile": "?0",
    "sec-ch-ua-platform": '"macOS"',
    "sec-fetch-dest": "empty",
    "sec-fetch-mode": "cors",
    "sec-fetch-site": "same-origin",
    "user-agent": (
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/145.0.0.0 Safari/537.36"
    ),
    "x-sz-sdk-version": "1.12.21",
}

# Referer mặc định theo loại endpoint
_REFERER_MAP: dict[str, str] = {
    "dashboard":    "https://affiliate.shopee.vn/dashboard",
    "campaign":     "https://affiliate.shopee.vn/campaign/campaign_list",
    "report":       "https://affiliate.shopee.vn/report/conversion_report",
    "click_report": "https://affiliate.shopee.vn/report/click_report",
    "billing":      "https://affiliate.shopee.vn/payment/billing",
    "payout":       "https://affiliate.shopee.vn/payment/payout_record",
    "invoice":      "https://affiliate.shopee.vn/payment/service_fee_invoice",
    "brand_offer":  "https://affiliate.shopee.vn/offer/brand_offer",
    "shop/list":    "https://affiliate.shopee.vn/offer/brand_offer",  # API get shop list -> referer brand_offer
    "product_offer":"https://affiliate.shopee.vn/offer/product_offer",
    "offer":        "https://affiliate.shopee.vn/offer/product_offer", # Fallback offer
}


def _infer_referer(url: str, caller_headers: dict | None = None) -> str:
    """Chọn Referer phù hợp dựa vào path của URL request hoặc caller header."""
    # Ưu tiên lấy Referer do client truyền
    if caller_headers:
        for k, v in caller_headers.items():
            if k.lower() == "referer":
                return str(v)

    # Đoán referer từ URL
    for key, referer in _REFERER_MAP.items():
        if key in url:
            return referer
    return "https://affiliate.shopee.vn/"


def build_shopee_headers(
    url: str,
    cookie_raw: str,
    extra: dict[str, str] | None = None,
) -> dict[str, str]:
    """
    Tạo headers đầy đủ cho một request đến affiliate.shopee.vn.

    Merges:
      1. Static headers (user-agent, sec-*, affiliate-program-type, ...)
      2. Caller extra headers (priority!)
      3. Referer tự động theo URL path or caller Referer
      4. Cookie header từ cookie_raw
    """
    headers = dict(SHOPEE_STATIC_HEADERS)

    # Áp dụng extra đè lên static (VD: errortoast, x-sz-sdk-version, x-sap-*)
    if extra:
        for k, v in extra.items():
            if k and v:
                headers[k.lower()] = str(v)

    # Tạo lại Referer (lấy từ extra nếu có, hoặc suy ra từ URL)
    headers["referer"] = _infer_referer(url, extra)

    # Shopee hay check thứ tự hash cookie. Khung lớn thì mới lọc để tránh 'Too Large Http Header'
    if cookie_raw:
        if len(cookie_raw) > 8000:
            essential = parse_cookie_string_to_essential(cookie_raw)
            if essential:
                headers["cookie"] = "; ".join(f"{k}={v}" for k, v in essential.items())
        else:
            headers["cookie"] = cookie_raw

    return headers


def parse_cookie_string_to_essential(raw: str) -> dict[str, str]:
    """
    Parse raw cookie string và chỉ giữ lại essential cookies.
    Trả về dict {name: value} (order-preserving).
    """
    result: dict[str, str] = {}
    if not raw:
        return result
    for segment in raw.split(";"):
        segment = segment.strip()
        if "=" not in segment:
            continue
        name, value = segment.split("=", 1)
        name = name.strip()
        if not name:
            continue
        if name in ESSENTIAL_COOKIE_NAMES or name.startswith("SPC_"):
            result[name] = value.strip()
    return result


def collect_refreshed_cookie(original: str, resp_headers: dict[str, str]) -> str | None:
    """
    Merge Set-Cookie headers từ response vào original cookie.
    Chỉ trả về chuỗi cookie mới nếu có thay đổi, None nếu không.
    Kết quả chỉ chứa essential cookies để tránh header quá lớn.
    """
    merged, _, changed = merge_set_cookies(original, resp_headers)
    if not changed:
        return None
    # Lọc chỉ giữ essential cookies trong output
    essential = parse_cookie_string_to_essential(merged)
    return "; ".join(f"{k}={v}" for k, v in essential.items()) if essential else None


# ==============================================================
# Response Classifier (Multi-Signal)
# ==============================================================

class ErrorType(str, Enum):
    AUTH_FAILURE = "auth_failure"
    BLOCKED_BOT = "blocked_bot"
    RATE_LIMITED = "rate_limited"
    NETWORK = "network"
    UNKNOWN = "unknown"


# ======================================================
# Cookie Merge Utilities
# ======================================================


def parse_cookie_pairs(raw: str) -> dict[str, str]:
    """
    Parse a raw cookie header string into {name: value} dict.
    Deduplicates by name (last value wins). Handles values containing '='.
    """
    pairs: dict[str, str] = {}
    if not raw:
        return pairs
    for segment in raw.split(";"):
        segment = segment.strip()
        if "=" not in segment:
            continue
        name, value = segment.split("=", 1)  # split only on first '='
        name = name.strip()
        value = value.strip()
        if name:
            pairs[name] = value
    return pairs


def merge_set_cookies(
    original_cookie: str, response_headers: dict[str, str]
) -> tuple[str, str, bool]:
    """
    Merge Set-Cookie headers from a response into the original cookie string.

    Returns:
        (merged_cookie_string, cookie_hash, has_changed)

    Handles:
    - Multiple Set-Cookie values (some HTTP layers join them with newlines)
    - Values containing '=' (e.g. base64 tokens)
    - Expires/Path/Domain attributes after first ';' are ignored
    """
    original_pairs = parse_cookie_pairs(original_cookie)
    original_hash = canonical_cookie_hash(original_pairs)

    # Collect all set-cookie headers (case-insensitive)
    set_cookie_values: list[str] = []
    for key, value in response_headers.items():
        if key.lower() == "set-cookie":
            # Some layers join multiple Set-Cookie into one string with newlines
            for line in value.split("\n"):
                line = line.strip()
                if line:
                    set_cookie_values.append(line)

    if not set_cookie_values:
        return original_cookie, original_hash, False

    merged = dict(original_pairs)  # copy

    for sc in set_cookie_values:
        # Extract name=value before any ';' (attributes like Expires, Path)
        name_value_part = sc.split(";", 1)[0].strip()
        if "=" not in name_value_part:
            continue
        name, value = name_value_part.split("=", 1)
        name = name.strip()
        value = value.strip()
        if name:
            merged[name] = value

    merged_hash = canonical_cookie_hash(merged)
    has_changed = merged_hash != original_hash

    # Rebuild as sorted cookie string for stable output
    merged_str = "; ".join(f"{k}={v}" for k, v in sorted(merged.items()))

    return merged_str, merged_hash, has_changed


def canonical_cookie_hash(pairs: dict[str, str]) -> str:
    """
    Compute a canonical hash of cookie pairs (order-insensitive).
    Returns first 12 chars of sha256 hex digest.
    """
    canonical = ";".join(f"{k}={v}" for k, v in sorted(pairs.items()))
    return hashlib.sha256(canonical.encode()).hexdigest()[:12]


@dataclass
class ProxyResult:
    status: int
    headers: dict[str, str] = field(default_factory=dict)
    json: dict | list | None = None
    text: str | None = None
    content_type: str = ""
    ok: bool = True
    error: str | None = None
    error_type: str | None = None
    url_final: str | None = None
    text_truncated: bool = False
    blocked_hint: bool = False
    # Cookie rotation fields
    refreshed_cookie: str | None = None
    cookie_rotatable: bool = False
    cookie_hash: str | None = None

    def to_dict(self) -> dict:
        return asdict(self)


# HTTP status codes indicating auth issues
HTTP_AUTH_STATUSES = {401, 403}

# Shopee API-level codes (JSON body "code" field)
API_BOT_CODES = {90309999, 90309998}
API_AUTH_CODES = {100003, 100004}


def classify_response(result: ProxyResult) -> ProxyResult:
    """
    Apply multi-signal classification to a proxy response.
    Separates HTTP status from API-level codes (CTO must-fix).
    """
    # Signal 1: HTTP 429 → rate limited
    if result.status == 429:
        result.error_type = ErrorType.RATE_LIMITED
        result.ok = False
        return result

    # Signal 2: Shopee API code in JSON body (bot detection)
    if result.json and isinstance(result.json, dict):
        api_code = result.json.get("code")
        if api_code is not None:
            try:
                api_code_int = int(api_code)
            except (ValueError, TypeError):
                api_code_int = None

            if api_code_int is not None:
                if api_code_int in API_BOT_CODES:
                    result.error_type = ErrorType.BLOCKED_BOT
                    result.blocked_hint = True
                    result.ok = False
                    return result
                if api_code_int in API_AUTH_CODES:
                    result.error_type = ErrorType.AUTH_FAILURE
                    result.ok = False
                    return result

    # Signal 3: HTML response with no JSON → challenge page
    if result.json is None and result.content_type and "text/html" in result.content_type:
        result.error_type = ErrorType.BLOCKED_BOT
        result.blocked_hint = True
        result.ok = False
        return result

    # Signal 4: HTTP 401/403 (separate from API codes)
    if result.status in HTTP_AUTH_STATUSES:
        if result.json and isinstance(result.json, dict) and result.json.get("msg"):
            # JSON with a message → likely auth failure
            result.error_type = ErrorType.AUTH_FAILURE
        else:
            # No JSON or empty msg → likely Cloudflare/bot block
            result.error_type = ErrorType.BLOCKED_BOT
            result.blocked_hint = True
        result.ok = False
        return result

    # Signal 5: 5xx → server/network error
    if result.status >= 500:
        result.error_type = ErrorType.NETWORK
        result.ok = False
        return result

    return result


class ShopeeAffiliateScraper:
    """
    Scrapes Shopee product data via the Affiliate Portal using user cookies.
    """

    def __init__(self):
        self._semaphore = asyncio.Semaphore(settings.max_browser_contexts)
        self.shopee_user_agent = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

    async def start(self):
        """Launch Camoufox browser (call once at app startup)."""
        global _browser
        logger.info("Starting Camoufox browser instance...")

        launch_options = {"headless": True}

        if settings.proxy_server:
            launch_options["proxy"] = {
                "server": settings.proxy_server,
            }
            if settings.proxy_username:
                launch_options["proxy"]["username"] = settings.proxy_username
                launch_options["proxy"]["password"] = settings.proxy_password

        _browser = await AsyncCamoufox(**launch_options).__aenter__()
        logger.info("Camoufox browser started successfully.")

    async def stop(self):
        """Shut down the browser (call at app shutdown)."""
        global _browser
        if _browser:
            logger.info("Stopping Camoufox browser...")
            try:
                await _browser.close()
            except Exception as e:
                logger.warning(f"Error closing browser: {e}")
            _browser = None
            logger.info("Camoufox browser stopped.")

    # ==============================================================
    # Product Detail: /offer/product_offer/{item_id}
    # ==============================================================

    async def scrape_product(
        self, item_id: str, cookies: str, shop_id: str | None = None
    ) -> dict[str, Any] | None:
        """
        Scrape a single product from the Affiliate Portal.

        Args:
            item_id: Shopee item ID
            cookies: Raw cookie string from user's browser session
            shop_id: Optional Shopee shop ID

        Returns:
            Dict with product data, or None if scraping failed.
        """
        global _browser
        if _browser is None:
            logger.error("Browser not initialized")
            return None

        url = f"https://affiliate.shopee.vn/offer/product_offer/{item_id}"
        ctx_id = uuid.uuid4().hex[:8]
        logger.info(f"[{ctx_id}] Scraping affiliate product: {url}")

        async with self._semaphore:
            context = None
            page = None
            try:
                context = await _browser.new_context()
                page = await context.new_page()

                # Inject user cookies before navigating
                await self._set_cookies(page, cookies)

                # Intercept API responses to capture product data + track cookie refreshes
                api_data: dict = {}
                refreshed_cookie: str | None = None

                async def handle_response(response):
                    """Capture affiliate API responses as they happen."""
                    nonlocal refreshed_cookie
                    try:
                        resp_url = response.url
                        # Track Set-Cookie for session refresh
                        rc = collect_refreshed_cookie(cookies, dict(response.headers))
                        if rc:
                            refreshed_cookie = rc

                        # Prioritize the product-specific endpoint
                        if "/api/v3/offer/product" in resp_url and "list" not in resp_url:
                            if response.status == 200:
                                body = await response.json()
                                api_data["product"] = body
                                logger.info(f"[{ctx_id}] Intercepted product API: {resp_url}")
                        elif "/api/v3/gql" in resp_url and "product" not in api_data:
                            if response.status == 200:
                                body = await response.json()
                                api_data["gql"] = body
                                logger.info(f"[{ctx_id}] Intercepted GQL API: {resp_url}")
                    except Exception as e:
                        logger.debug(f"[{ctx_id}] Failed to intercept response: {e}")

                page.on("response", handle_response)

                # Navigate to the affiliate product page    
                await page.goto(url, wait_until="domcontentloaded",
                                timeout=settings.page_load_timeout * 1000)

                # Wait for the SPA to render and API calls to complete
                await page.wait_for_timeout(5000)

                # === Strategy 1: Use intercepted product API data ===
                product_payload = api_data.get("product") or api_data.get("gql")
                if product_payload:
                    logger.info(f"[{ctx_id}] API response keys: {list(product_payload.keys()) if isinstance(product_payload, dict) else type(product_payload).__name__}")
                    # Log deeper structure for debugging
                    if isinstance(product_payload, dict) and "data" in product_payload:
                        inner = product_payload["data"]
                        if isinstance(inner, dict):
                            logger.info(f"[{ctx_id}] API data keys: {list(inner.keys())}")

                    data = self._parse_intercepted_api(
                        product_payload, item_id, shop_id
                    )
                    if data:
                        data["source"] = "api_intercept"
                        if refreshed_cookie:
                            data["refreshed_cookie"] = refreshed_cookie
                            logger.info(f"[{ctx_id}] Cookie refreshed during scrape")
                        item_name = data.get('item_name') or 'N/A'
                        logger.info(f"[{ctx_id}] Got product via API intercept: {str(item_name)[:50]}")
                        return data
                    else:
                        logger.warning(f"[{ctx_id}] API data intercepted but parser returned None")

                # === Strategy 2: Parse rendered DOM ===
                html = await page.content()

                # Check for login redirect
                if self._is_login_page(html):
                    logger.warning(f"[{ctx_id}] Cookies expired — affiliate portal redirected to login for {item_id}")
                    return None

                data = self._parse_affiliate_page(html, item_id, shop_id)
                if data:
                    data["source"] = "dom_parse"
                    item_name = data.get('item_name') or 'N/A'
                    logger.info(f"[{ctx_id}] Got product via DOM parse: {str(item_name)[:50]}")
                    return data

                logger.warning(f"[{ctx_id}] Could not extract product data for {item_id}")
                return None

            except Exception as e:
                logger.error(f"[{ctx_id}] Scraping failed for {item_id}: {type(e).__name__}: {e}")
                return None
            finally:
                if context:
                    await context.close()

    # ==============================================================
    # Product Search: /api/v3/offer/product/list
    # ==============================================================

    async def search_products(
        self, keyword: str, cookies: str, page_limit: int = 20
    ) -> list[dict[str, Any]]:
        """
        Search for products via the Affiliate Portal API.

        Uses Camoufox to load the affiliate page first (letting JS generate
        anti-bot headers), then intercepts the search API call.

        Args:
            keyword: Search keyword
            cookies: Raw cookie string from user's browser session
            page_limit: Number of results to return

        Returns:
            List of product dicts.
        """
        global _browser
        if _browser is None:
            logger.error("Browser not initialized")
            return []

        ctx_id = uuid.uuid4().hex[:8]
        logger.info(f"[{ctx_id}] Searching products: keyword='{keyword}'")

        async with self._semaphore:
            context = None
            page = None
            try:
                context = await _browser.new_context()
                page = await context.new_page()
                await self._set_cookies(page, cookies)

                # Intercept search API response + track cookie refreshes
                search_results: dict = {}
                refreshed_cookie: str | None = None

                async def handle_response(response):
                    nonlocal refreshed_cookie
                    try:
                        resp_url = response.url
                        # Track Set-Cookie for session refresh
                        rc = collect_refreshed_cookie(cookies, dict(response.headers))
                        if rc:
                            refreshed_cookie = rc

                        if "api/v3" in resp_url:
                            logger.info(f"[{ctx_id}] Search API intercepted: {resp_url}")

                        if "/api/v3/offer/product/list" in resp_url:
                            if response.status == 200:
                                body = await response.json()
                                search_results["data"] = body
                                logger.info(f"[{ctx_id}] Captured search API response successfully")
                    except Exception as e:
                        logger.error(f"[{ctx_id}] Error handling search response: {e}")

                page.on("response", handle_response)

                import urllib.parse
                encoded_keyword = urllib.parse.quote(keyword)
                
                # Navigate to the search page — this triggers the API call
                search_url = (
                    f"https://affiliate.shopee.vn/offer/product_offer"
                    f"?keyword={encoded_keyword}&listType=0"
                )
                logger.info(f"[{ctx_id}] Navigating to search URL: {search_url}")
                await page.goto(search_url, wait_until="networkidle",
                                timeout=settings.page_load_timeout * 1000)

                # Wait for search results to load
                await page.wait_for_timeout(5000)

                if search_results.get("data"):
                    results = self._parse_search_results(search_results["data"])
                    if refreshed_cookie:
                        logger.info(f"[{ctx_id}] Cookie refreshed during search")
                        for r in results:
                            r["refreshed_cookie"] = refreshed_cookie
                    return results

                # Fallback: parse DOM for search results
                html = await page.content()
                if self._is_login_page(html):
                    logger.warning(f"[{ctx_id}] Cookies expired — search redirected to login")
                    return []

                logger.warning(f"[{ctx_id}] No search results captured via API or DOM matched")
                return []

            except Exception as e:
                logger.error(f"[{ctx_id}] Search failed: {type(e).__name__}: {e}")
                return []
            finally:
                if context:
                    await context.close()

    # ==============================================================
    # Generic HTTP Proxy: /api/v1/shopee/proxy
    # ==============================================================

    async def proxy_request(
        self,
        url: str,
        method: str,
        headers: dict[str, object] | None,
        params: dict[str, object] | None,
        body: dict | str | None,
        cookie_raw: str,
        timeout_ms: int | None = None,
        use_browser_fetch: bool = False,
        browser_url: str | None = None,
    ) -> ProxyResult:
        """
        Proxy HTTP request through Camoufox.
        Dùng cờ use_browser_fetch = True để giả lập Browser Evaluate Fetch.
        """
        global _browser
        if _browser is None:
            return ProxyResult(status=503, error="Browser not initialized", error_type=ErrorType.NETWORK, ok=False)

        try:
            validate_proxy_url(url)
        except ValueError as e:
            return ProxyResult(status=400, error=f"SSRF validation failed: {e}", error_type=ErrorType.UNKNOWN, ok=False)

        cookie_hash = hashlib.sha256(cookie_raw.encode()).hexdigest()[:12] if cookie_raw else "empty"
        ctx_id = uuid.uuid4().hex[:8]
        timeout_val = float(timeout_ms) if timeout_ms else float(getattr(settings, 'proxy_total_timeout', 30)) * 1000.0

        # Build full Shopee headers: static baseline + referer + essential cookies + caller extras
        caller_extra = normalize_headers(headers)
        caller_extra.pop("cookie", None)
        normalized = build_shopee_headers(url, cookie_raw, extra=caller_extra)

        # CRITICAL FIX for GQL/POST endpoints: WAF rejects missing/wrong content-type
        if method.upper() == "POST" and "content-type" not in normalized:
            normalized["content-type"] = "application/json; charset=UTF-8"
        elif "content-type" in normalized and "json" not in normalized["content-type"].lower() and method.upper() == "POST":
             normalized["content-type"] = "application/json; charset=UTF-8"

        # === DOM JS FETCH EXECUTION FOR ANTI-BOT STRICT API ===
        if use_browser_fetch and browser_url:
            logger.info(f"[{ctx_id}] Injecting JS Fetch on Context: {browser_url} for API {url}")
            context = None
            page = None
            try:
                # 1. Reset Context và thiết lập Cookie gốc
                cookie_list = parse_raw_cookies(cookie_raw, ".shopee.vn")
                context = await _browser.new_context(user_agent=self.shopee_user_agent)
                context.set_default_timeout(timeout_val)
                await context.add_cookies(cookie_list)
                
                # 2. Điều hướng vào Subpage bíệt danh của Shopee
                page = await context.new_page()
                await page.goto(browser_url, wait_until="networkidle", timeout=timeout_val)
                
                # 3. Compile JS Inject
                body_js = "undefined"
                if body is not None:
                    if isinstance(body, str):
                        body_js = json.dumps(body)
                    else:
                        body_js = json.dumps(body)

                js_fetch_code = f"""
                async () => {{
                    try {{
                        const queryUrl = new URL('{url}');
                        const params = {json.dumps(params or {})};
                        Object.keys(params).forEach(k => queryUrl.searchParams.append(k, params[k]));

                        const reqInit = {{
                            method: '{method}',
                            headers: {json.dumps(normalized or {})},
                            body: {body_js}
                        }};
                        
                        // Xóa các headers tĩnh dư thừa để trình duyệt tự điền tự nhiên nhât
                        ['User-Agent','user-agent','Cookie','cookie','Host','Origin','Referer'].forEach(h => delete reqInit.headers[h]);

                        const response = await fetch(queryUrl.toString(), reqInit);
                        const text = await response.text();
                        return {{ status: response.status, ok: response.ok, text: text, headers: Object.fromEntries(response.headers.entries()) }};
                    }} catch (err) {{ return {{ error: err.toString(), ok: false }}; }}
                }}
                """
                
                res = await page.evaluate(js_fetch_code)
                refreshed_raw = await collect_refreshed_cookie(context, cookie_raw)
                
                if res.get("error"): 
                    return ProxyResult(status=500, ok=False, error=res["error"], error_type=ErrorType.SCRIPT)

                html_text = res.get("text", "")
                is_json = "{" in html_text or "[" in html_text

                return ProxyResult(
                    status=res.get("status", 200), 
                    headers=res.get("headers", {}), 
                    json=json.loads(html_text) if is_json and html_text else None, 
                    text=html_text if not is_json else None, 
                    ok=res.get("ok", True), 
                    refreshed_cookie=refreshed_raw
                )
            except Exception as e:
                logger.error(f"[{ctx_id}] JS Fetch failed: {e}")
                return ProxyResult(status=500, ok=False, error=str(e), error_type=ErrorType.SCRIPT)
            finally:
                if page: await page.close()
                if context: await context.close()

        connect_timeout = float(getattr(settings, 'proxy_connect_timeout', 10)) * 1000.0

        async with self._semaphore:
            context = None
            page = None
            try:
                cookie_list = parse_raw_cookies(cookie_raw, ".shopee.vn")
                # Fallback to pure GET request if we only need raw HTML and JS isn't required
                # But actually here it's proxy_request, so we use Camoufox page.request
                context = await _browser.new_context(user_agent=self.shopee_user_agent)
                context.set_default_timeout(timeout_val)
                # Not using context.add_cookies here since we send it in header.
                
                req_ctx = context.request
                
                current_url = url
                current_method = method.upper()
                redirect_count = 0
                max_redirects = 5
                post_data = None
                
                while True:
                    if current_method == "POST":
                        # Cực kỳ quan trọng: Nếu caller (Laravel) gửi sang một string thì GIỮ NGUYÊN (ví dụ raw JSON có \n, khoảng trắng)
                        # vì WAF Shopee tính chữ ký hash nguyên bản chuỗi đó (x-sap-sec). Dùng json.dumps() sẽ thu gọn chuỗi
                        # dẫn đến Failed x-sap-sec Authentication (403 block).
                        if isinstance(body, str):
                            post_data = body
                        else:
                            post_data = json.dumps(body) if body else None

                        resp = await req_ctx.post(
                            current_url,
                            headers=normalized,
                            params=normalize_params(params),
                            data=post_data,
                            max_redirects=0,
                            timeout=timeout_val,
                        )
                    else:
                        resp = await req_ctx.get(
                            current_url,
                            headers=normalized,
                            params=normalize_params(params),
                            max_redirects=0,
                            timeout=timeout_val,
                        )

                    # Handle redirect hops
                    if resp.status in (301, 302, 303, 307, 308) and redirect_count < max_redirects:
                        location = resp.headers.get("location", "")
                        if not location:
                            logger.warning(f"[{ctx_id}] Redirect with no Location header at hop {hops}")
                            break

                        # Validate the redirect target before following — SSRF guard
                        try:
                            validate_proxy_url(location)
                        except ValueError as ssrf_e:
                            logger.error(f"[{ctx_id}] SSRF redirect blocked at hop {hops}: {ssrf_e}")
                            return ProxyResult(
                                status=403,
                                error=f"SSRF redirect blocked: {ssrf_e}",
                                error_type=ErrorType.BLOCKED_BOT,
                                ok=False,
                            )

                        hops += 1
                        current_url = location
                        # 303 See Other turns POST into GET
                        if resp.status == 303:
                            current_method = "GET"
                        logger.info(f"[{ctx_id}] Following redirect ({hops}/{max_redirects}) → {location}")
                        continue

                    # Final response (not a redirect, or max redirects reached)
                    break

                # Build ProxyResult
                resp_content_type = resp.headers.get("content-type", "")
                resp_headers = dict(resp.headers)
                resp_json = None
                resp_text = None
                resp_truncated = False
                parse_error = None

                try:
                    resp_json = await resp.json()
                except Exception:
                    parse_error = "json_parse_failed"
                    try:
                        raw_text = await resp.text()
                        if len(raw_text) > 2000:
                            resp_text = raw_text[:2000]
                            resp_truncated = True
                        else:
                            resp_text = raw_text
                    except Exception as te:
                        resp_text = f"[text read failed: {te}]"

                result = ProxyResult(
                    status=resp.status,
                    headers=resp_headers,
                    json=resp_json,
                    text=resp_text,
                    content_type=resp_content_type,
                    ok=resp.status < 400,
                    error=parse_error,
                    url_final=current_url if current_url != url else None,
                    text_truncated=resp_truncated,
                )

                # Apply multi-signal classifier
                result = classify_response(result)

                # Cookie rotation: merge Set-Cookie headers into original cookies
                if result.ok and cookie_raw:
                    try:
                        merged, merged_hash, changed = merge_set_cookies(
                            cookie_raw, resp_headers
                        )
                        result.cookie_hash = merged_hash
                        if changed:
                            result.refreshed_cookie = merged
                            result.cookie_rotatable = True
                            logger.info(
                                f"[{ctx_id}] cookie rotatable: "
                                f"hash={merged_hash} cookie_hash={cookie_hash}"
                            )
                    except Exception as ce:
                        logger.warning(
                            f"[{ctx_id}] cookie merge failed: {ce}"
                        )

                logger.info(
                    f"[{ctx_id}] proxy_request done: status={result.status} "
                    f"ok={result.ok} error_type={result.error_type} "
                    f"blocked_hint={result.blocked_hint}"
                )
                return result

            except Exception as e:
                logger.error(f"[{ctx_id}] proxy_request exception: {type(e).__name__}: {e}")
                return ProxyResult(
                    status=503,
                    error=f"{type(e).__name__}: {e}",
                    error_type=ErrorType.NETWORK,
                    ok=False,
                )
            finally:
                if context:
                    await context.close()

    # ==============================================================
    # Cookie Management
    # ==============================================================


    @staticmethod
    async def _set_cookies(page: Page, cookie_string: str):
        """
        Parse a raw cookie string and inject into the browser page.
        Keeps all auth + fingerprint cookies Shopee needs for session validation.
        Drops only pure analytics blobs to stay under header size limits.
        """
        unique_cookies = parse_cookie_string_to_essential(cookie_string)

        cookies = [
            {"name": name, "value": value, "domain": ".shopee.vn", "path": "/"}
            for name, value in unique_cookies.items()
        ]

        if cookies:
            context = page.context
            await context.add_cookies(cookies)
            logger.info(f"Injected {len(cookies)} essential cookies into browser context")

    # ==============================================================
    # Parsers
    # ==============================================================

    def _parse_intercepted_api(
        self, payload: dict, item_id: str, shop_id: str | None
    ) -> dict[str, Any] | None:
        """Parse product data from intercepted affiliate API response."""
        if not isinstance(payload, dict):
            logger.warning(f"Payload is not dict: {type(payload).__name__}")
            return None

        try:
            # Try multiple paths to find product data
            nodes = None
            product = None

            # Path 1: data.productOfferV2.nodes (GraphQL)
            try:
                gql_data = payload.get("data", {})
                if isinstance(gql_data, dict):
                    pov2 = gql_data.get("productOfferV2")
                    if isinstance(pov2, dict):
                        nodes = pov2.get("nodes", [])
            except (AttributeError, TypeError):
                pass

            # Path 2: data.product (REST - single product)
            if not nodes:
                try:
                    data_block = payload.get("data", {})
                    if isinstance(data_block, dict):
                        product = data_block.get("product") or data_block.get("item")
                        if isinstance(product, dict):
                            nodes = [product]
                        elif isinstance(product, list):
                            nodes = product
                except (AttributeError, TypeError):
                    pass

            # Path 3: data.list (REST - product list)
            if not nodes:
                try:
                    data_block = payload.get("data", {})
                    if isinstance(data_block, dict):
                        lst = data_block.get("list", [])
                        if isinstance(lst, list) and lst:
                            nodes = lst
                except (AttributeError, TypeError):
                    pass

            # Path 4: Top-level data IS the product
            if not nodes:
                data_block = payload.get("data")
                if isinstance(data_block, dict) and any(
                    k in data_block for k in ["itemId", "item_id", "productName", "product_name", "name"]
                ):
                    nodes = [data_block]

            if not nodes or not isinstance(nodes, list):
                logger.warning(f"No product nodes found in payload. Top keys: {list(payload.keys())}")
                data_block = payload.get("data")
                if isinstance(data_block, dict):
                    logger.warning(f"data sub-keys: {list(data_block.keys())}")
                return None

            # Find matching node by item_id
            node = None
            for n in nodes:
                if not isinstance(n, dict):
                    continue
                n_id = str(n.get("itemId", n.get("item_id", "")))
                if n_id == item_id:
                    node = n
                    break
            if node is None and nodes:
                first = nodes[0]
                if isinstance(first, dict):
                    node = first

            if not node:
                logger.warning("Found nodes but none matched item_id")
                return None

            # Look deeper if the node is an affiliate wrapper
            if "batch_item_for_item_card_full" in node and isinstance(node["batch_item_for_item_card_full"], dict):
                inner_node = node["batch_item_for_item_card_full"]
                if "item" in inner_node and isinstance(inner_node["item"], dict):
                    inner_node = inner_node["item"]
                node = inner_node
                
            logger.info(f"Product node keys: {list(node.keys())}")

            # Extract main image
            image_url = node.get("imageUrl", node.get("image_url", node.get("image")))

            # Extract full image array
            images_raw = node.get("images", node.get("image_list", node.get("image_urls", [])))
            images_full = []

            if isinstance(images_raw, str):
                try:
                    # sometimes stored as JSON string
                    parsed = json.loads(images_raw)
                    if isinstance(parsed, list):
                        images_raw = parsed
                    else:
                        images_raw = [images_raw]
                except:
                    images_raw = [images_raw]

            if isinstance(images_raw, list):
                for img in images_raw:
                    if not isinstance(img, str) or not img.strip():
                        continue
                    if not img.startswith('http'):
                        images_full.append(f"https://down-vn.img.susercontent.com/file/{img.strip()}")
                    else:
                        images_full.append(img.strip())

            # Ensure main image is included at the beginning
            if image_url:
                if not image_url.startswith('http'):
                    image_url = f"https://down-vn.img.susercontent.com/file/{image_url.strip()}"

                if image_url not in images_full:
                    images_full.insert(0, image_url)

            # Fallback if somehow still empty but we have an image
            if not images_full and image_url:
                images_full.append(image_url)

            return {
                "item_id": str(node.get("itemId", node.get("item_id", item_id))),
                "shop_id": str(node.get("shopId", node.get("shop_id", shop_id or ""))),
                "item_name": node.get("productName", node.get("product_name", node.get("name"))),
                "price_min": self._safe_int(node.get("priceMin", node.get("price_min", node.get("price")))),
                "price_max": self._safe_int(node.get("priceMax", node.get("price_max"))),
                "image_url": image_url,
                "images": images_full,
                "sales": self._safe_int(node.get("sales", node.get("sold", node.get("historical_sold")))),
                "rating_star": self._safe_float(node.get("ratingStar", node.get("rating_star", node.get("item_rating")))),
            }

        except Exception as e:
            logger.error(f"Error parsing intercepted API: {type(e).__name__}: {e}")
            return None

    def _parse_affiliate_page(
        self, html: str, item_id: str, shop_id: str | None
    ) -> dict[str, Any] | None:
        """Parse the rendered affiliate product page DOM."""
        soup = BeautifulSoup(html, "lxml")

        # Try to find embedded JSON data in script tags
        for script in soup.find_all("script"):
            text = script.string or ""
            if "productName" in text or "product_name" in text:
                try:
                    # Look for JSON objects in script content
                    json_match = re.search(r'\{[^{}]*"(?:productName|product_name)"[^{}]*\}', text)
                    if json_match:
                        data = json.loads(json_match.group())
                        return {
                            "item_id": item_id,
                            "shop_id": str(data.get("shopId", shop_id or "")),
                            "item_name": data.get("productName", data.get("product_name")),
                            "price_min": self._safe_int(data.get("priceMin", data.get("price_min"))),
                            "price_max": self._safe_int(data.get("priceMax", data.get("price_max"))),
                            "image_url": data.get("imageUrl", data.get("image_url")),
                            "sales": None,
                            "rating_star": None,
                        }
                except (json.JSONDecodeError, AttributeError):
                    continue

        # Fallback: OpenGraph meta tags
        og_title = self._get_meta(soup, "og:title")
        og_image = self._get_meta(soup, "og:image")
        og_desc = self._get_meta(soup, "og:description") or ""

        if og_title and "đăng nhập" not in og_title.lower():
            price = self._extract_price_from_text(og_desc)
            return {
                "item_id": item_id,
                "shop_id": shop_id or "",
                "item_name": og_title,
                "price_min": price,
                "price_max": price,
                "image_url": og_image,
                "sales": None,
                "rating_star": None,
            }

        return None

    def _parse_search_results(self, payload: dict) -> list[dict[str, Any]]:
        """Parse the affiliate search API response."""
        results = []
        items = payload.get("data", {}).get("list", [])
        if not isinstance(items, list):
            items = payload.get("data", {}).get("nodes", [])

        for item in items:
            if not isinstance(item, dict):
                continue
                
            basic = item.get("item_basic", {})
            if not basic and "batch_item_for_item_card_full" in item:
                bi_val = item["batch_item_for_item_card_full"]
                try:
                    basic = json.loads(bi_val) if isinstance(bi_val, str) else bi_val
                except Exception:
                    pass
            if not basic:
                basic = item

            res = {
                "item_id": str(item.get("itemId") or item.get("item_id") or basic.get("itemid") or basic.get("item_id") or ""),
                "shop_id": str(item.get("shopId") or item.get("shop_id") or basic.get("shopid") or basic.get("shop_id") or ""),
                "item_name": item.get("productName") or item.get("product_name") or basic.get("name"),
                "price_min": self._safe_int(item.get("priceMin") or item.get("price_min") or basic.get("price_min") or basic.get("price")),
                "price_max": self._safe_int(item.get("priceMax") or item.get("price_max") or basic.get("price_max")),
                "image_url": item.get("imageUrl") or item.get("image_url") or basic.get("image"),
                "sales": self._safe_int(item.get("sales") or item.get("sold") or basic.get("sold") or basic.get("historical_sold")),
                "rating_star": self._safe_float(
                    item.get("ratingStar") or item.get("rating_star") or (basic.get("item_rating") or {}).get("rating_star")
                ),
                "commission_rate": self._safe_float(item.get("commissionRate") or item.get("commission_rate")),
            }
            logger.info(f"Parsed search item: item_id={res['item_id']}, item_name={res['item_name']}, keys_in_item={list(item.keys())}")
            results.append(res)

        return results

    # ==============================================================
    # Utilities
    # ==============================================================

    @staticmethod
    def _is_login_page(html: str) -> bool:
        """Detect if redirected to login/CAPTCHA page."""
        indicators = [
            "buyer/login", "buyer/signup", "đăng nhập tài khoản",
            "captcha", "recaptcha", "cf-challenge",
        ]
        html_lower = html.lower()
        return any(i in html_lower for i in indicators)

    @staticmethod
    def _get_meta(soup: BeautifulSoup, prop: str) -> str | None:
        tag = soup.find("meta", property=prop) or soup.find("meta", attrs={"name": prop})
        return tag.get("content") if tag else None

    @staticmethod
    def _extract_price_from_text(text: str) -> int | None:
        if not text:
            return None
        cleaned = re.sub(r"[₫đ$,.\s]", "", text)
        match = re.search(r"\d+", cleaned)
        return int(match.group()) if match else None

    @staticmethod
    def _safe_int(value) -> int | None:
        if value is None:
            return None
        try:
            return int(float(str(value)))
        except (ValueError, TypeError):
            return None

    @staticmethod
    def _safe_float(value) -> float | None:
        if value is None:
            return None
        try:
            return round(float(value), 2)
        except (ValueError, TypeError):
            return None


# --- Singleton instance ---
scraper = ShopeeAffiliateScraper()
