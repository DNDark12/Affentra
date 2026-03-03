import './bootstrap';
import 'tom-select/dist/css/tom-select.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { bootTomSelectEnhancer } from './plugins/tomSelectEnhancer';
import { applyTheme, resolveThemePreference } from './Utils/theme';

// Keep theme stable across reloads even when CSP blocks inline scripts.
applyTheme(resolveThemePreference());

createInertiaApp({
    title: (title) => {
        const appName = (import.meta.env.VITE_APP_NAME || 'Affentra').trim();
        const pageTitle = (title || '').trim();

        return pageTitle ? `${pageTitle} - ${appName}` : appName;
    },

    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);

        void bootTomSelectEnhancer();
    },

    progress: {
        color: '#6366f1',
    },
});
