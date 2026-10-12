import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import type { Config } from 'ziggy-js';
import { route, ZiggyVue } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'Digital Workflow';
const nonce = document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content || undefined;

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue');
        const page = pages[`./Pages/${name}.vue`];
        if (!page) {
            throw new Error(`Unknown page: ${name}`);
        }
        return page();
    },
    setup({ el, App, props, plugin }) {
        // The route table arrives as a once-shared prop rather than an inline
        // script, so the CSP never has to allow one.
        const ziggy = props.initialPage.props.ziggy as Config;
        Object.assign(globalThis, { Ziggy: ziggy, route });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, ziggy)
            .mount(el);
    },
    nonce,
    progress: { color: '#2563eb', delay: 200 },
});
