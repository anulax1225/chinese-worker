import { ref, watch, onMounted } from 'vue';

export type Theme = 'light' | 'dark' | 'system';

const theme = ref<Theme>('system');
const resolvedTheme = ref<'light' | 'dark'>('light');

const getSystemTheme = (): 'light' | 'dark' => {
    if (typeof window === 'undefined') return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

const applyTheme = (newTheme: Theme) => {
    const resolved = newTheme === 'system' ? getSystemTheme() : newTheme;
    resolvedTheme.value = resolved;

    // Update document class
    document.documentElement.classList.remove('light', 'dark');
    document.documentElement.classList.add(resolved);

    // Persist to localStorage
    localStorage.setItem('theme', newTheme);
};

export function useTheme() {
    const toggleTheme = () => {
        const next = resolvedTheme.value === 'light' ? 'dark' : 'light';
        theme.value = next;
        applyTheme(next);
    };

    const setTheme = (newTheme: Theme) => {
        theme.value = newTheme;
        applyTheme(newTheme);
    };

    onMounted(() => {
        // Load saved theme from localStorage
        const saved = localStorage.getItem('theme') as Theme | null;
        if (saved && ['light', 'dark', 'system'].includes(saved)) {
            theme.value = saved;
        }
        applyTheme(theme.value);

        // Listen for system theme changes
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const handleChange = () => {
            if (theme.value === 'system') {
                applyTheme('system');
            }
        };
        mediaQuery.addEventListener('change', handleChange);
    });

    return {
        theme,
        resolvedTheme,
        toggleTheme,
        setTheme,
    };
}
