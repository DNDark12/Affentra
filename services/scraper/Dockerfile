# ============================================================
# Shopee Scraper Microservice — Dockerfile
# Stack: Python 3.11 + FastAPI + Camoufox (Firefox-based)
# ============================================================
FROM python:3.11-slim AS base

# --- System deps for headless Firefox (Camoufox) ---
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Firefox runtime dependencies
    libgtk-3-0 \
    libdbus-glib-1-2 \
    libxt6 \
    libx11-xcb1 \
    libasound2 \
    libxcomposite1 \
    libxdamage1 \
    libxrandr2 \
    libxss1 \
    libxtst6 \
    libpango-1.0-0 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libgbm1 \
    libnspr4 \
    libnss3 \
    # Virtual framebuffer (headless display)
    xvfb \
    # Utilities
    curl \
    && rm -rf /var/lib/apt/lists/*

# --- Non-root user for security ---
RUN groupadd -r scraper && useradd -r -g scraper -m scraper

WORKDIR /app

# --- Python dependencies ---
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# --- Install Camoufox browser binary ---
RUN python -m camoufox fetch

# --- Install Playwright browsers (Firefox only, as backup) ---
RUN playwright install firefox --with-deps 2>/dev/null || true

# --- Copy application code ---
COPY src/ ./src/

# --- Switch to non-root user ---
RUN chown -R scraper:scraper /app
USER scraper

# --- Health check ---
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:8000/health || exit 1

EXPOSE 8000

# --- Start with Xvfb (virtual display) + Uvicorn ---
CMD ["sh", "-c", "Xvfb :99 -screen 0 1920x1080x24 &  DISPLAY=:99 uvicorn src.main:app --host 0.0.0.0 --port 8000 --workers 1 --timeout-keep-alive 30"]
