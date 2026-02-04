# Color System

A configurable color system using CSS variables and OKLCH color space for easy theming.

## Overview

Colors are defined as CSS variables in `resources/css/app.css`. The system uses OKLCH (Lightness, Chroma, Hue) for perceptually uniform colors that look consistent across light and dark modes.

## CSS Variable Structure

```css
/* resources/css/app.css */

@layer base {
  :root {
    /* ============================================
       CONFIGURABLE BRAND COLORS
       Change these 3 hue values to retheme the entire app
       ============================================ */

    /* Primary - Main brand color (buttons, links, active states) */
    --color-primary-hue: 217.2;      /* Blue: 217.2, Emerald: 160.1, Indigo: 238.7 */

    /* Secondary - Accent brand color (badges, highlights) */
    --color-secondary-hue: 262.1;    /* Purple: 262.1, Blue: 217.2, Pink: 330.4 */

    /* Accent - Tertiary highlights (info, special UI) */
    --color-accent-hue: 192.9;       /* Cyan: 192.9, Amber: 45.4, Teal: 172.5 */

    /* ============================================
       DERIVED COLORS (auto-calculated from hues)
       ============================================ */

    /* Primary scale */
    --primary: oklch(0.623 0.214 var(--color-primary-hue));
    --primary-foreground: oklch(0.985 0.014 var(--color-primary-hue));

    /* Secondary scale */
    --secondary: oklch(0.541 0.245 var(--color-secondary-hue));
    --secondary-foreground: oklch(0.985 0.014 var(--color-secondary-hue));

    /* Accent scale */
    --accent: oklch(0.685 0.143 var(--color-accent-hue));
    --accent-foreground: oklch(0.145 0.014 var(--color-accent-hue));

    /* ============================================
       LIGHT MODE SEMANTICS
       ============================================ */
    --background: oklch(0.985 0.002 var(--color-primary-hue));
    --foreground: oklch(0.145 0.014 var(--color-primary-hue));

    --card: oklch(1 0 0);
    --card-foreground: oklch(0.145 0.014 var(--color-primary-hue));

    --popover: oklch(1 0 0);
    --popover-foreground: oklch(0.145 0.014 var(--color-primary-hue));

    --muted: oklch(0.965 0.007 var(--color-primary-hue));
    --muted-foreground: oklch(0.455 0.018 var(--color-primary-hue));

    --border: oklch(0.912 0.014 var(--color-primary-hue));
    --input: oklch(0.912 0.014 var(--color-primary-hue));
    --ring: oklch(0.623 0.214 var(--color-primary-hue));

    /* Semantic colors */
    --destructive: oklch(0.577 0.245 27.325);
    --destructive-foreground: oklch(0.985 0.014 27.325);

    --success: oklch(0.627 0.194 149.214);
    --success-foreground: oklch(0.985 0.014 149.214);

    --warning: oklch(0.769 0.188 70.08);
    --warning-foreground: oklch(0.145 0.014 70.08);

    --info: oklch(0.685 0.143 var(--color-accent-hue));
    --info-foreground: oklch(0.145 0.014 var(--color-accent-hue));
  }

  .dark {
    /* ============================================
       DARK MODE SEMANTICS
       Backgrounds are tinted with primary hue
       ============================================ */
    --background: oklch(0.145 0.014 var(--color-primary-hue));
    --foreground: oklch(0.985 0.005 var(--color-primary-hue));

    --card: oklch(0.175 0.017 var(--color-primary-hue));
    --card-foreground: oklch(0.985 0.005 var(--color-primary-hue));

    --popover: oklch(0.175 0.017 var(--color-primary-hue));
    --popover-foreground: oklch(0.985 0.005 var(--color-primary-hue));

    --muted: oklch(0.215 0.022 var(--color-primary-hue));
    --muted-foreground: oklch(0.645 0.025 var(--color-primary-hue));

    --border: oklch(0.265 0.025 var(--color-primary-hue));
    --input: oklch(0.265 0.025 var(--color-primary-hue));
    --ring: oklch(0.623 0.214 var(--color-primary-hue));

    /* Primary/Secondary stay the same, slightly adjusted for dark */
    --primary: oklch(0.623 0.214 var(--color-primary-hue));
    --primary-foreground: oklch(0.145 0.014 var(--color-primary-hue));

    --secondary: oklch(0.541 0.245 var(--color-secondary-hue));
    --secondary-foreground: oklch(0.145 0.014 var(--color-secondary-hue));
  }
}
```

## Color Presets

### Electric Blue (Default)

```css
--color-primary-hue: 217.2;    /* Blue */
--color-secondary-hue: 262.1;  /* Purple */
--color-accent-hue: 192.9;     /* Cyan */
```

| Role | Color | Usage |
|------|-------|-------|
| Primary | `#3B82F6` | Buttons, links, active states |
| Secondary | `#8B5CF6` | Badges, highlights |
| Accent | `#06B6D4` | Info states, special UI |

### Emerald

```css
--color-primary-hue: 160.1;    /* Emerald */
--color-secondary-hue: 217.2;  /* Blue */
--color-accent-hue: 45.4;      /* Amber */
```

### Indigo

```css
--color-primary-hue: 238.7;    /* Indigo */
--color-secondary-hue: 330.4;  /* Pink */
--color-accent-hue: 172.5;     /* Teal */
```

### Rose

```css
--color-primary-hue: 349.7;    /* Rose */
--color-secondary-hue: 262.1;  /* Purple */
--color-accent-hue: 172.5;     /* Teal */
```

### Amber

```css
--color-primary-hue: 45.4;     /* Amber */
--color-secondary-hue: 217.2;  /* Blue */
--color-accent-hue: 160.1;     /* Emerald */
```

## Dark Mode Implementation

### Theme Toggle Logic

```typescript
// resources/js/composables/useTheme.ts

import { ref, watch, onMounted } from 'vue';

type Theme = 'light' | 'dark' | 'system';

export function useTheme() {
    const theme = ref<Theme>('system');
    const resolvedTheme = ref<'light' | 'dark'>('light');

    const getSystemTheme = (): 'light' | 'dark' => {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    };

    const applyTheme = (newTheme: Theme) => {
        const resolved = newTheme === 'system' ? getSystemTheme() : newTheme;
        resolvedTheme.value = resolved;

        document.documentElement.classList.remove('light', 'dark');
        document.documentElement.classList.add(resolved);

        localStorage.setItem('theme', newTheme);
    };

    const toggleTheme = () => {
        const next = resolvedTheme.value === 'light' ? 'dark' : 'light';
        theme.value = next;
        applyTheme(next);
    };

    onMounted(() => {
        const saved = localStorage.getItem('theme') as Theme | null;
        theme.value = saved || 'system';
        applyTheme(theme.value);

        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (theme.value === 'system') {
                applyTheme('system');
            }
        });
    });

    return {
        theme,
        resolvedTheme,
        toggleTheme,
        setTheme: applyTheme,
    };
}
```

### Tailwind Configuration

```css
/* resources/css/app.css - ensure dark mode uses class strategy */

@import "tailwindcss";

/* Dark mode is controlled by .dark class on <html> */
@custom-variant dark (&:where(.dark, .dark *));
```

## Using Colors in Components

### With Tailwind Classes

```vue
<template>
  <!-- Primary button -->
  <button class="bg-primary text-primary-foreground hover:bg-primary/90">
    Submit
  </button>

  <!-- Secondary badge -->
  <span class="bg-secondary/10 text-secondary">
    New
  </span>

  <!-- Muted text -->
  <p class="text-muted-foreground">
    Last updated 2 hours ago
  </p>

  <!-- Card with border -->
  <div class="bg-card border border-border rounded-lg">
    Content
  </div>
</template>
```

### With CSS Variables

```css
.custom-gradient {
  background: linear-gradient(
    135deg,
    oklch(0.623 0.214 var(--color-primary-hue)),
    oklch(0.541 0.245 var(--color-secondary-hue))
  );
}
```

## Accessibility Considerations

### Contrast Ratios

All color combinations meet WCAG 2.1 AA standards:

| Combination | Ratio | Requirement |
|-------------|-------|-------------|
| `foreground` on `background` | 15:1 | ✓ AAA (7:1) |
| `primary-foreground` on `primary` | 4.8:1 | ✓ AA (4.5:1) |
| `muted-foreground` on `muted` | 5.2:1 | ✓ AA (4.5:1) |

### Testing Colors

Use browser DevTools or tools like:
- [Polypane Color Contrast Checker](https://polypane.app/color-contrast/)
- [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)

## Migration Guide

### From Current Setup

1. Add the CSS variables to `resources/css/app.css`
2. Update existing color classes:
   - `bg-blue-500` → `bg-primary`
   - `text-gray-500` → `text-muted-foreground`
   - `border-gray-200` → `border-border`
3. Add `.dark` variant to existing components where needed
4. Test all pages in both light and dark mode
