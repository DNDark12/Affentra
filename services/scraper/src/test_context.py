import asyncio
from camoufox.async_api import AsyncCamoufox

async def main():
    async with AsyncCamoufox(headless=True) as browser:
        # Create two pages using new_page()
        page1 = await browser.new_page()
        page2 = await browser.new_page()
        
        # Add cookie to page1
        await page1.context.add_cookies([{
            "name": "test_cookie",
            "value": "user1",
            "domain": "example.com",
            "path": "/"
        }])
        
        # Add a different cookie to page2
        await page2.context.add_cookies([{
            "name": "test_cookie",
            "value": "user2",
            "domain": "example.com",
            "path": "/"
        }])
        
        # Check cookies
        cookies1 = await page1.context.cookies()
        cookies2 = await page2.context.cookies()
        
        print("Page 1 cookies:", cookies1)
        print("Page 2 cookies:", cookies2)
        
        print("Are contexts same?", page1.context == page2.context)

asyncio.run(main())
