import asyncio
import sys
from playwright.async_api import async_playwright
import camoufox.async_api
import json

async def main():
    cookie_string = sys.argv[1]
    
    unique_cookies = {}
    for pair in cookie_string.split(';'):
        pair = pair.strip()
        if "=" not in pair: continue
        name, _, value = pair.partition("=")
        name = name.strip()
        value = value.strip()
        if not name: continue
        
        # STRICT WHITELIST
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

    api_responses = {}
    async def handle_response(response):
        if '/api/v3' in response.url or '/graphql' in response.url:
            print(f'API Intercepted: {response.url}')
        if '/offer/product/list' in response.url:
            try:
                body = await response.json()
                api_responses['search'] = body
            except Exception as e:
                print(f'Failed to parse JSON: {e}')

    async with camoufox.async_api.AsyncCamoufox(headless=True) as browser:
        page = await browser.new_page()
        context = page.context
        await context.add_cookies(cookies)
        
        page.on('response', handle_response)
        
        url = 'https://affiliate.shopee.vn/offer/product_offer?keyword=ban+phim&listType=0'
        print(f'Visiting {url}')
        
        await page.goto(url, wait_until='networkidle', timeout=30000)
        await page.wait_for_timeout(3000)
        
        if 'search' in api_responses:
            print('Search API captured successfully!')
            items = api_responses["search"].get("data", {}).get("list", [])
            print(f'Items found: {len(items)}')
            if len(items) > 0:
                print('First item keys:', list(items[0].keys()))
                print('First item JSON:')
                print(json.dumps(items[0], indent=2, ensure_ascii=False))
        else:
            print('Search API not captured!')

if __name__ == "__main__":
    asyncio.run(main())
