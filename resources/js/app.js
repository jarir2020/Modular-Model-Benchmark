import { createApp } from 'vue';
import '../css/app.css';

const modulePages = import.meta.glob('../../Modules/*/Components/*Index.vue', {
    eager: true,
    import: 'default',
});

for (const [componentPath, component] of Object.entries(modulePages)) {
    const match = componentPath.match(/\/Modules\/([^/]+)\/Components\/[^/]+Index\.vue$/);

    if (!match) {
        continue;
    }

    const moduleSlug = match[1]
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .toLowerCase();
    const root = document.getElementById(moduleSlug + '-app');
    const propsElement = document.getElementById(moduleSlug + '-page-props');

    if (!root || !propsElement) {
        continue;
    }

    const props = JSON.parse(propsElement.textContent || '{}');
    createApp(component, props).mount(root);
}
