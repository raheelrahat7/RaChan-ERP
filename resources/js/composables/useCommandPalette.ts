import { onBeforeUnmount, onMounted, ref } from 'vue';

const open = ref(false);

function onKey(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;
    }
}

export function useCommandPalette() {
    function toggle(): void {
        open.value = !open.value;
    }

    /** Call once, from the component that owns the palette. */
    function listen(): void {
        onMounted(() => window.addEventListener('keydown', onKey));
        onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
    }

    return { open, toggle, listen };
}
