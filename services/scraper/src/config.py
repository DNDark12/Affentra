"""
Configuration module for Shopee Scraper Service.
All settings are loaded from environment variables.
"""
import os
from pydantic_settings import BaseSettings


class Settings(BaseSettings):
    """Application settings loaded from environment variables."""

    # --- API Security ---
    api_secret_key: str = os.getenv("API_SECRET_KEY", "dev-secret-key")

    # --- Scraper Behavior ---
    # Maximum time (seconds) to wait for Shopee page to fully render
    page_load_timeout: int = int(os.getenv("PAGE_LOAD_TIMEOUT", "15"))

    # Maximum time (seconds) for a single scrape request
    request_timeout: int = int(os.getenv("REQUEST_TIMEOUT", "30"))

    # Max concurrent browser contexts (controls RAM usage)
    max_browser_contexts: int = int(os.getenv("MAX_BROWSER_CONTEXTS", "3"))

    # --- Proxy (Optional) ---
    proxy_server: str | None = os.getenv("PROXY_SERVER", None)
    proxy_username: str | None = os.getenv("PROXY_USERNAME", None)
    proxy_password: str | None = os.getenv("PROXY_PASSWORD", None)

    # --- Shopee ---
    shopee_base_url: str = os.getenv("SHOPEE_BASE_URL", "https://shopee.vn")

    # --- Rate Limiting ---
    rate_limit_requests: int = int(os.getenv("RATE_LIMIT_REQUESTS", "10"))
    rate_limit_window: int = int(os.getenv("RATE_LIMIT_WINDOW", "60"))

    class Config:
        env_file = ".env"


settings = Settings()
