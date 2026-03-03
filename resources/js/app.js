import './bootstrap';
import 'tom-select/dist/css/tom-select.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { bootTomSelectEnhancer } from './plugins/tomSelectEnhancer';

createInertiaApp({
    title: (title) => `${title} - ${import.meta.env.VITE_APP_NAME}`,

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
