import { ref, watch } from 'vue';

/** Per-browser UI preferences. Storage can be unavailable (private mode), so every access is guarded. */
function stored<T extends string>(key: string, fallback: T) {
    let initial = fallback;
    try {
        initial = (localStorage.getItem(key) as T | null) ?? fallback;
    } catch {
        /* storage unavailable */
    }
    const value = ref<T>(initial);
    watch(value, (v) => {
        try {
            localStorage.setItem(key, v);
        } catch {
            /* storage unavailable */
        }
    });
    return value;
}

/** Submenus the user opened by hand; survives page changes (module state). */
export const openGroups = ref(new Set<string>());

/** Colour theme: light (default), dark, or follow the OS. Applied as <html class="dark">; public/js/theme.js applies it before first paint. */
export type ThemePref = 'light' | 'dark' | 'system';
export const themePref = stored<ThemePref>('ui.theme', 'light');

const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;
export const isDark = ref(false);
function applyTheme() {
    isDark.value = themePref.value === 'dark' || (themePref.value === 'system' && !!media?.matches);
    document.documentElement.classList.toggle('dark', isDark.value);
}
if (typeof document !== 'undefined') {
    applyTheme();
    watch(themePref, applyTheme);
    media?.addEventListener('change', applyTheme);
}
