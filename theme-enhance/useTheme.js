import { ref, watch, onMounted } from 'vue';

const theme = ref('system');
const isDark = ref(false);
const primaryHue = ref(286);
const secondaryHue = ref(286);
const intensity = ref(100); // 0-100, controls chroma multiplier
const radius = ref(0.625); // in rem, controls border-radius
const fontFamily = ref('instrument-sans'); // font family key
const fontWeight = ref(400); // 300-700, controls font boldness
const borderWidth = ref(1); // 0-3, controls border thickness in px
const shadowIntensity = ref(100); // 0-200, controls shadow strength
const spacing = ref(100); // 75-125, controls spacing/density as percentage
const backgroundStyle = ref('solid'); // solid, gradient, radial, mesh

const FONT_STACKS = {
  'instrument-sans': 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
  'inter': 'Inter, ui-sans-serif, system-ui, sans-serif',
  'nunito': 'Nunito, ui-sans-serif, system-ui, sans-serif',
  'poppins': 'Poppins, ui-sans-serif, system-ui, sans-serif',
  'dm-sans': 'DM Sans, ui-sans-serif, system-ui, sans-serif',
  'system': 'ui-sans-serif, system-ui, sans-serif'
};

export function useTheme() {
  const setTheme = (newTheme) => {
    theme.value = newTheme;
    localStorage.setItem('theme', newTheme);
    applyTheme();
  };

  const applyTheme = () => {
    const root = document.documentElement;

    if (theme.value === 'dark') {
      root.classList.add('dark');
      isDark.value = true;
    } else if (theme.value === 'light') {
      root.classList.remove('dark');
      isDark.value = false;
    } else {
      // system
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (prefersDark) {
        root.classList.add('dark');
        isDark.value = true;
      } else {
        root.classList.remove('dark');
        isDark.value = false;
      }
    }
  };

  const setPrimaryHue = (hue) => {
    // Validate hue is between 0-360
    const validHue = Math.max(0, Math.min(360, hue));
    primaryHue.value = validHue;
    localStorage.setItem('primaryHue', validHue.toString());
    applyHues();
  };

  const setSecondaryHue = (hue) => {
    // Validate hue is between 0-360
    const validHue = Math.max(0, Math.min(360, hue));
    secondaryHue.value = validHue;
    localStorage.setItem('secondaryHue', validHue.toString());
    applyHues();
  };

  const setIntensity = (value) => {
    // Validate intensity is between 0-100
    const validIntensity = Math.max(0, Math.min(100, value));
    intensity.value = validIntensity;
    localStorage.setItem('themeIntensity', validIntensity.toString());
    applyHues();
  };

  const setRadius = (value) => {
    // Validate radius is between 0-2rem
    const validRadius = Math.max(0, Math.min(2, value));
    radius.value = validRadius;
    localStorage.setItem('themeRadius', validRadius.toString());
    applyHues();
  };

  const setFontFamily = (family) => {
    if (FONT_STACKS[family]) {
      fontFamily.value = family;
      localStorage.setItem('themeFontFamily', family);
      applyHues();
    }
  };

  const setFontWeight = (value) => {
    const validWeight = Math.max(300, Math.min(700, value));
    fontWeight.value = validWeight;
    localStorage.setItem('themeFontWeight', validWeight.toString());
    applyHues();
  };

  const setBorderWidth = (value) => {
    const validWidth = Math.max(0, Math.min(10, value));
    borderWidth.value = validWidth;
    localStorage.setItem('themeBorderWidth', validWidth.toString());
    applyHues();
  };

  const setShadowIntensity = (value) => {
    const validIntensity = Math.max(0, Math.min(200, value));
    shadowIntensity.value = validIntensity;
    localStorage.setItem('themeShadowIntensity', validIntensity.toString());
    applyHues();
  };

  const setSpacing = (value) => {
    const validSpacing = Math.max(75, Math.min(125, value));
    spacing.value = validSpacing;
    localStorage.setItem('themeSpacing', validSpacing.toString());
    applyHues();
  };

  const setBackgroundStyle = (style) => {
    const validStyles = ['solid', 'gradient', 'radial', 'mesh'];
    if (validStyles.includes(style)) {
      backgroundStyle.value = style;
      localStorage.setItem('themeBackgroundStyle', style);
      applyBackgroundStyle();
    }
  };

  const applyBackgroundStyle = () => {
    const body = document.body;
    body.classList.remove('bg-solid', 'bg-gradient', 'bg-radial', 'bg-mesh');
    body.classList.add(`bg-${backgroundStyle.value}`);
  };

  const applyHues = () => {
    const root = document.documentElement;
    root.style.setProperty('--primary-hue', primaryHue.value.toString());
    root.style.setProperty('--secondary-hue', secondaryHue.value.toString());
    // Intensity as a multiplier (0-1)
    root.style.setProperty('--theme-intensity', (intensity.value / 100).toString());
    // Border radius
    root.style.setProperty('--radius', `${radius.value}rem`);
    // Font family
    const stack = FONT_STACKS[fontFamily.value] || FONT_STACKS['instrument-sans'];
    root.style.setProperty('--font-family', stack);
    // Font weight base
    root.style.setProperty('--font-weight-base', fontWeight.value.toString());
    // Border width base
    root.style.setProperty('--border-width-base', `${borderWidth.value}px`);
    // Shadow intensity (as multiplier)
    root.style.setProperty('--shadow-intensity', (shadowIntensity.value / 100).toString());
    // Spacing base (0.25rem * percentage)
    root.style.setProperty('--spacing-base', `${0.25 * (spacing.value / 100)}rem`);
  };

  const initHues = () => {
    const storedPrimaryHue = localStorage.getItem('primaryHue');
    const storedSecondaryHue = localStorage.getItem('secondaryHue');
    const storedIntensity = localStorage.getItem('themeIntensity');
    const storedRadius = localStorage.getItem('themeRadius');
    const storedFont = localStorage.getItem('themeFontFamily');

    if (storedPrimaryHue) {
      primaryHue.value = parseInt(storedPrimaryHue, 10);
    }
    if (storedSecondaryHue) {
      secondaryHue.value = parseInt(storedSecondaryHue, 10);
    }
    if (storedIntensity) {
      intensity.value = parseInt(storedIntensity, 10);
    }
    if (storedRadius) {
      radius.value = parseFloat(storedRadius);
    }
    if (storedFont && FONT_STACKS[storedFont]) {
      fontFamily.value = storedFont;
    }
    const storedFontWeight = localStorage.getItem('themeFontWeight');
    if (storedFontWeight) {
      fontWeight.value = parseInt(storedFontWeight, 10);
    }
    const storedBorderWidth = localStorage.getItem('themeBorderWidth');
    if (storedBorderWidth) {
      borderWidth.value = parseFloat(storedBorderWidth);
    }
    const storedShadowIntensity = localStorage.getItem('themeShadowIntensity');
    if (storedShadowIntensity) {
      shadowIntensity.value = parseInt(storedShadowIntensity, 10);
    }
    const storedSpacing = localStorage.getItem('themeSpacing');
    if (storedSpacing) {
      spacing.value = parseInt(storedSpacing, 10);
    }
    const storedBackgroundStyle = localStorage.getItem('themeBackgroundStyle');
    if (storedBackgroundStyle) {
      backgroundStyle.value = storedBackgroundStyle;
    }

    applyHues();
    applyBackgroundStyle();
  };

  const initTheme = () => {
    const stored = localStorage.getItem('theme') || 'system';
    theme.value = stored;
    applyTheme();
    initHues();

    // Watch for system theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (theme.value === 'system') {
        applyTheme();
      }
    });
  };

  return {
    theme,
    isDark,
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
    initTheme,
  };
}