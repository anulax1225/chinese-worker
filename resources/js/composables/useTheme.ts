import { ref, onMounted, onScopeDispose } from 'vue';

export type Theme = 'light' | 'dark' | 'system';
export type BackgroundStyle = 'solid' | 'gradient' | 'radial' | 'mesh';
export type FontFamily = 'instrument-sans' | 'inter' | 'nunito' | 'poppins' | 'dm-sans' | 'system';

const theme = ref<Theme>('system');
const resolvedTheme = ref<'light' | 'dark'>('light');
const primaryHue = ref(286);
const secondaryHue = ref(286);
const intensity = ref(100);
const radius = ref(0.625);
const fontFamily = ref<FontFamily>('instrument-sans');
const fontWeight = ref(400);
const borderWidth = ref(1);
const shadowIntensity = ref(100);
const spacing = ref(100);
const backgroundStyle = ref<BackgroundStyle>('solid');

export const FONT_STACKS: Record<FontFamily, string> = {
    'instrument-sans': 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
    'inter': 'Inter, ui-sans-serif, system-ui, sans-serif',
    'nunito': 'Nunito, ui-sans-serif, system-ui, sans-serif',
    'poppins': 'Poppins, ui-sans-serif, system-ui, sans-serif',
    'dm-sans': 'DM Sans, ui-sans-serif, system-ui, sans-serif',
    'system': 'ui-sans-serif, system-ui, sans-serif',
};

const getSystemTheme = (): 'light' | 'dark' => {
    if (typeof window === 'undefined') return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

const applyTheme = (newTheme: Theme) => {
    const resolved = newTheme === 'system' ? getSystemTheme() : newTheme;
    resolvedTheme.value = resolved;

    document.documentElement.classList.remove('light', 'dark');
    document.documentElement.classList.add(resolved);

    localStorage.setItem('theme', newTheme);
};

const applyCustomizations = () => {
    const root = document.documentElement;
    root.style.setProperty('--primary-hue', primaryHue.value.toString());
    root.style.setProperty('--secondary-hue', secondaryHue.value.toString());
    root.style.setProperty('--theme-intensity', (intensity.value / 100).toString());
    root.style.setProperty('--radius', `${radius.value}rem`);
    const stack = FONT_STACKS[fontFamily.value] || FONT_STACKS['instrument-sans'];
    root.style.setProperty('--font-family', stack);
    root.style.setProperty('--font-weight-base', fontWeight.value.toString());
    root.style.setProperty('--border-width-base', `${borderWidth.value}px`);
    root.style.setProperty('--shadow-intensity', (shadowIntensity.value / 100).toString());
    root.style.setProperty('--spacing-base', `${0.25 * (spacing.value / 100)}rem`);
};

const applyBackgroundStyle = () => {
    const body = document.body;
    body.classList.remove('bg-solid', 'bg-gradient', 'bg-radial', 'bg-mesh');
    body.classList.add(`bg-${backgroundStyle.value}`);
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

    const setPrimaryHue = (hue: number) => {
        const validHue = Math.max(0, Math.min(360, hue));
        primaryHue.value = validHue;
        localStorage.setItem('primaryHue', validHue.toString());
        applyCustomizations();
    };

    const setSecondaryHue = (hue: number) => {
        const validHue = Math.max(0, Math.min(360, hue));
        secondaryHue.value = validHue;
        localStorage.setItem('secondaryHue', validHue.toString());
        applyCustomizations();
    };

    const setIntensity = (value: number) => {
        const validIntensity = Math.max(0, Math.min(100, value));
        intensity.value = validIntensity;
        localStorage.setItem('themeIntensity', validIntensity.toString());
        applyCustomizations();
    };

    const setRadius = (value: number) => {
        const validRadius = Math.max(0, Math.min(2, value));
        radius.value = validRadius;
        localStorage.setItem('themeRadius', validRadius.toString());
        applyCustomizations();
    };

    const setFontFamily = (family: FontFamily) => {
        if (FONT_STACKS[family]) {
            fontFamily.value = family;
            localStorage.setItem('themeFontFamily', family);
            applyCustomizations();
        }
    };

    const setFontWeight = (value: number) => {
        const validWeight = Math.max(300, Math.min(700, value));
        fontWeight.value = validWeight;
        localStorage.setItem('themeFontWeight', validWeight.toString());
        applyCustomizations();
    };

    const setBorderWidth = (value: number) => {
        const validWidth = Math.max(0, Math.min(10, value));
        borderWidth.value = validWidth;
        localStorage.setItem('themeBorderWidth', validWidth.toString());
        applyCustomizations();
    };

    const setShadowIntensity = (value: number) => {
        const validIntensity = Math.max(0, Math.min(200, value));
        shadowIntensity.value = validIntensity;
        localStorage.setItem('themeShadowIntensity', validIntensity.toString());
        applyCustomizations();
    };

    const setSpacing = (value: number) => {
        const validSpacing = Math.max(75, Math.min(125, value));
        spacing.value = validSpacing;
        localStorage.setItem('themeSpacing', validSpacing.toString());
        applyCustomizations();
    };

    const setBackgroundStyle = (style: BackgroundStyle) => {
        const validStyles: BackgroundStyle[] = ['solid', 'gradient', 'radial', 'mesh'];
        if (validStyles.includes(style)) {
            backgroundStyle.value = style;
            localStorage.setItem('themeBackgroundStyle', style);
            applyBackgroundStyle();
        }
    };

    const loadStoredValues = () => {
        const storedPrimaryHue = localStorage.getItem('primaryHue');
        const storedSecondaryHue = localStorage.getItem('secondaryHue');
        const storedIntensity = localStorage.getItem('themeIntensity');
        const storedRadius = localStorage.getItem('themeRadius');
        const storedFont = localStorage.getItem('themeFontFamily');
        const storedFontWeight = localStorage.getItem('themeFontWeight');
        const storedBorderWidth = localStorage.getItem('themeBorderWidth');
        const storedShadowIntensity = localStorage.getItem('themeShadowIntensity');
        const storedSpacing = localStorage.getItem('themeSpacing');
        const storedBackgroundStyle = localStorage.getItem('themeBackgroundStyle');

        if (storedPrimaryHue) primaryHue.value = parseInt(storedPrimaryHue, 10);
        if (storedSecondaryHue) secondaryHue.value = parseInt(storedSecondaryHue, 10);
        if (storedIntensity) intensity.value = parseInt(storedIntensity, 10);
        if (storedRadius) radius.value = parseFloat(storedRadius);
        if (storedFont && FONT_STACKS[storedFont as FontFamily]) {
            fontFamily.value = storedFont as FontFamily;
        }
        if (storedFontWeight) fontWeight.value = parseInt(storedFontWeight, 10);
        if (storedBorderWidth) borderWidth.value = parseFloat(storedBorderWidth);
        if (storedShadowIntensity) shadowIntensity.value = parseInt(storedShadowIntensity, 10);
        if (storedSpacing) spacing.value = parseInt(storedSpacing, 10);
        if (storedBackgroundStyle) {
            backgroundStyle.value = storedBackgroundStyle as BackgroundStyle;
        }

        applyCustomizations();
        applyBackgroundStyle();
    };

    let cleanup: (() => void) | null = null;

    onMounted(() => {
        const saved = localStorage.getItem('theme') as Theme | null;
        if (saved && ['light', 'dark', 'system'].includes(saved)) {
            theme.value = saved;
        }
        applyTheme(theme.value);
        loadStoredValues();

        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const handleChange = () => {
            if (theme.value === 'system') {
                applyTheme('system');
            }
        };
        mediaQuery.addEventListener('change', handleChange);

        cleanup = () => mediaQuery.removeEventListener('change', handleChange);
    });

    onScopeDispose(() => {
        cleanup?.();
    });

    return {
        theme,
        resolvedTheme,
        primaryHue,
        secondaryHue,
        intensity,
        radius,
        fontFamily,
        fontWeight,
        borderWidth,
        shadowIntensity,
        spacing,
        backgroundStyle,
        FONT_STACKS,
        toggleTheme,
        setTheme,
        setPrimaryHue,
        setSecondaryHue,
        setIntensity,
        setRadius,
        setFontFamily,
        setFontWeight,
        setBorderWidth,
        setShadowIntensity,
        setSpacing,
        setBackgroundStyle,
    };
}
