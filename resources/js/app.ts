import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PortalLayout from '@/layouts/PortalLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('portal/'):
                return PortalLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Keeps <html dir/lang> in sync with the active locale. This cannot be a
// `router.on('navigate', …)` listener: Inertia intentionally skips firing
// `navigate` for "replace" visits that land back on the same URL — which is
// exactly what happens after switching locale (POST /locale -> back()) — so
// dir/lang would only update through the browser tab's next full navigation.
// Watching the reactive page prop instead fires for every visit kind.
// No `immediate: true`: the Blade template already renders the correct
// dir/lang for the first paint, and createInertiaApp() is not awaited above,
// so an immediate call here could fire before the page store is populated
// and briefly stomp the server-rendered value with the 'en' fallback.
watch(
    () => usePage().props.locale,
    (value) => {
        const locale = value === 'ar' ? 'ar' : 'en';
        document.documentElement.lang = locale;
        document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    },
);
