"""
Shopee Affiliate Scraper — Core scraping logic using Camoufox + Playwright.

This module scrapes product data from Shopee's Affiliate Portal
(affiliate.shopee.vn) using user-provided cookies, bypassing the
public Shopee site which blocks datacenter IPs.

Approach:
  1. Load affiliate.shopee.vn with user cookies in Camoufox
  2. Navigate to product offer page → parse rendered SPA
  3. OR call affiliate API directly with cookies + intercepted anti-bot headers
"""
import asyncio
import json
import logging
import re
from typing import Any

from bs4 import BeautifulSoup
from camoufox.async_api import AsyncCamoufox
from playwright.async_api import Page

from .config import settings

logger = logging.getLogger("scraper")

# Module-level browser reference (kept alive during app lifespan)
_browser = None


class ShopeeAffiliateScraper:
    """
    Scrapes Shopee product data via the Affiliate Portal using user cookies.
    """

    def __init__(self):
        self._semaphore = asyncio.Semaphore(settings.max_browser_contexts)

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
        logger.info(f"Scraping affiliate product: {url}")

        async with self._semaphore:
            page = None
            try:
                page = await _browser.new_page()

                # Inject user cookies before navigating
                await self._set_cookies(page, cookies)

                # Intercept API responses to capture product data directly
                api_data = {}

                async def handle_response(response):
                    """Capture affiliate API responses as they happen."""
                    try:
                        resp_url = response.url
                        # Prioritize the product-specific endpoint
                        if "/api/v3/offer/product" in resp_url and "list" not in resp_url:
                            if response.status == 200:
                                body = await response.json()
                                api_data["product"] = body
                                logger.info(f"Intercepted product API: {resp_url}")
                        elif "/api/v3/gql" in resp_url and "product" not in api_data:
                            if response.status == 200:
                                body = await response.json()
                                api_data["gql"] = body
                                logger.info(f"Intercepted GQL API: {resp_url}")
                    except Exception as e:
                        logger.debug(f"Failed to intercept response: {e}")

                page.on("response", handle_response)

                # Navigate to the affiliate product page    
                await page.goto(url, wait_until="domcontentloaded",
                                timeout=settings.page_load_timeout * 1000)

                # Wait for the SPA to render and API calls to complete
                await page.wait_for_timeout(5000)

                # === Strategy 1: Use intercepted product API data ===
                product_payload = api_data.get("product") or api_data.get("gql")
                if product_payload:
                    logger.info(f"API response keys: {list(product_payload.keys()) if isinstance(product_payload, dict) else type(product_payload).__name__}")
                    # Log deeper structure for debugging
                    if isinstance(product_payload, dict) and "data" in product_payload:
                        inner = product_payload["data"]
                        if isinstance(inner, dict):
                            logger.info(f"API data keys: {list(inner.keys())}")

                    data = self._parse_intercepted_api(
                        product_payload, item_id, shop_id
                    )
                    if data:
                        data["source"] = "api_intercept"
                        logger.info(f"Got product via API intercept: {data.get('item_name', 'N/A')[:50]}")
                        return data
                    else:
                        logger.warning("API data intercepted but parser returned None")

                # === Strategy 2: Parse rendered DOM ===
                html = await page.content()

                # Check for login redirect
                if self._is_login_page(html):
                    logger.warning(f"Cookies expired — affiliate portal redirected to login for {item_id}")
                    return None

                data = self._parse_affiliate_page(html, item_id, shop_id)
                if data:
                    data["source"] = "dom_parse"
                    logger.info(f"Got product via DOM parse: {data.get('item_name', 'N/A')[:50]}")
                    return data

                logger.warning(f"Could not extract product data for {item_id}")
                return None

            except Exception as e:
                logger.error(f"Scraping failed for {item_id}: {type(e).__name__}: {e}")
                return None
            finally:
                if page:
                    await page.close()

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

        logger.info(f"Searching products: keyword='{keyword}'")

        async with self._semaphore:
            page = None
            try:
                page = await _browser.new_page()
                await self._set_cookies(page, cookies)

                # Intercept search API response
                search_results = {}

                async def handle_response(response):
                    try:
                        resp_url = response.url
                        if "api/v3" in resp_url:
                            logger.info(f"Search API intercepted: {resp_url}")
                        
                        if "/api/v3/offer/product/list" in resp_url:
                            if response.status == 200:
                                body = await response.json()
                                search_results["data"] = body
                                logger.info("Captured search API response successfully")
                    except Exception as e:
                        logger.error(f"Error handling search response: {e}")

                page.on("response", handle_response)

                import urllib.parse
                encoded_keyword = urllib.parse.quote(keyword)
                
                # Navigate to the search page — this triggers the API call
                search_url = (
                    f"https://affiliate.shopee.vn/offer/product_offer"
                    f"?keyword={encoded_keyword}&listType=0"
                )
                logger.info(f"Navigating to search URL: {search_url}")
                await page.goto(search_url, wait_until="networkidle",
                                timeout=settings.page_load_timeout * 1000)

                # Wait for search results to load
                await page.wait_for_timeout(5000)

                if search_results.get("data"):
                    return self._parse_search_results(search_results["data"])

                # Fallback: parse DOM for search results
                html = await page.content()
                if self._is_login_page(html):
                    logger.warning("Cookies expired — search redirected to login")
                    return []

                # Debug: check if products are embedded in the HTML
                if "productName" in html or "priceMin" in html:
                    logger.info("Found product data embedded in HTML! Need to extract from DOM.")
                    # Try to find the __INITIAL_STATE__ or similar script
                    match = re.search(r'window\.__INITIAL_STATE__\s*=\s*(\{.*?\});', html)
                    if match:
                        logger.info("Found window.__INITIAL_STATE__")
                    match2 = re.search(r'window\.__NUXT__\s*=\s*(\{.*?\});', html)
                    if match2:
                        logger.info("Found window.__NUXT__")

                logger.warning("No search results captured via API or DOM matched")
                return []

            except Exception as e:
                logger.error(f"Search failed: {type(e).__name__}: {e}")
                return []
            finally:
                if page:
                    await page.close()

    # ==============================================================
    # Cookie Management
    # ==============================================================

    @staticmethod
    async def _set_cookies(page: Page, cookie_string: str):
        """
        Parse a raw cookie string and inject into the browser page.
        Filters out massive tracking blobs to prevent HTTP 400 Header Too Large.
        """
        unique_cookies = {}
        for pair in cookie_string.split(";"):
            pair = pair.strip()
            if "=" not in pair:
                continue
            name, _, value = pair.partition("=")
            name = name.strip()
            value = value.strip()
            if not name:
                continue
                
            # STRICT WHITELIST: Only Shopee essential auth/session cookies
            # This prevents 40KB+ payloads causing HTTP 400 "Request Header or Cookie Too Large"
            if not (name.startswith("SPC_") or name in ["REC_T_ID", "shopee_token", "csrftoken", "language"]):
                continue
                
            unique_cookies[name] = value

        cookies = []
        for name, value in unique_cookies.items():
            cookies.append({
                "name": name,
                "value": value,
                "domain": ".shopee.vn",
                "path": "/",
            })

        if cookies:
            context = page.context
            await context.add_cookies(cookies)
            logger.info(f"Injected {len(cookies)} filtered cookies into browser context")

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

            logger.info(f"Product node keys: {list(node.keys())}")

            return {
                "item_id": str(node.get("itemId", node.get("item_id", item_id))),
                "shop_id": str(node.get("shopId", node.get("shop_id", shop_id or ""))),
                "item_name": node.get("productName", node.get("product_name", node.get("name"))),
                "price_min": self._safe_int(node.get("priceMin", node.get("price_min", node.get("price")))),
                "price_max": self._safe_int(node.get("priceMax", node.get("price_max"))),
                "image_url": node.get("imageUrl", node.get("image_url", node.get("image"))),
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
