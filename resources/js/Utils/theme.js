function readThemeCookie() {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
    return match?.[1] || null;
}

function writeThemeCookie(theme) {
    if (typeof document === 'undefined') {
        return;
    }

    document.cookie = `theme=${theme};path=/;max-age=31536000;samesite=lax`;
}

function setDomTheme(theme) {
    if (typeof document === 'undefined') {
        return;
    }

    const isDark = theme === 'dark';
    document.documentElement.classList.toggle('dark', isDark);
    document.body?.classList?.toggle('dark', isDark);
}

export function resolveThemePreference() {
    if (typeof window === 'undefined') {
        return 'light';
    }

    const localTheme = window.localStorage.getItem('theme');
    if (localTheme === 'dark' || localTheme === 'light') {
        return localTheme;
    }

    const cookieTheme = readThemeCookie();
    if (cookieTheme === 'dark' || cookieTheme === 'light') {
        return cookieTheme;
    }

    const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
    return prefersDark ? 'dark' : 'light';
}

export function applyTheme(theme, options = {}) {
    const normalized = theme === 'dark' ? 'dark' : 'light';
    const persist = options.persist !== false;

    setDomTheme(normalized);

    if (!persist || typeof window === 'undefined') {
        return normalized;
    }

    window.localStorage.setItem('theme', normalized);
    writeThemeCookie(normalized);

    return normalized;
}

export function getCurrentTheme() {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}
