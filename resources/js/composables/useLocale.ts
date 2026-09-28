import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import arabic from '@/locales/ar.json';
const messages: Record<string, string> = arabic;
export function useLocale() {
    const page = usePage();
    const locale = computed(() => (page.props.locale === 'ar' ? 'ar' : 'en'));
    function t(
        text: string,
        parameters: Record<string, string | number> = {},
    ): string {
        const translated =
            locale.value === 'ar'
                ? (messages[text.replace(/\s+/g, ' ').trim()] ?? text)
                : text;
        return translated.replace(/:([A-Za-z_]+)/g, (match, key: string) =>
            String(parameters[key] ?? match),
        );
    }
    function status(text: string): string {
        return t(text.replaceAll('_', ' '));
    }
    return { locale, t, status };
}
