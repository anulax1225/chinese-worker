<script setup lang="ts">
import { useTheme, type FontFamily } from '@/composables/useTheme';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { RotateCcw } from 'lucide-vue-next';

const {
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
} = useTheme();

const fontLabels: Record<FontFamily, string> = {
    'instrument-sans': 'Instrument',
    'inter': 'Inter',
    'nunito': 'Nunito',
    'poppins': 'Poppins',
    'dm-sans': 'DM Sans',
    'system': 'System',
};

const backgroundStyles = [
    { key: 'solid' as const, label: 'Solid' },
    { key: 'gradient' as const, label: 'Gradient' },
    { key: 'radial' as const, label: 'Radial' },
    { key: 'mesh' as const, label: 'Mesh' },
];

const presets = [
    { name: 'Violet', primary: 286, secondary: 286 },
    { name: 'Blue', primary: 230, secondary: 200 },
    { name: 'Green', primary: 140, secondary: 120 },
    { name: 'Orange', primary: 40, secondary: 20 },
    { name: 'Red', primary: 10, secondary: 350 },
    { name: 'Cyan', primary: 195, secondary: 180 },
];

const applyPreset = (preset: { primary: number; secondary: number }) => {
    setPrimaryHue(preset.primary);
    setSecondaryHue(preset.secondary);
};

const handlePrimaryChange = (event: Event) => {
    setPrimaryHue(parseInt((event.target as HTMLInputElement).value));
};

const handleSecondaryChange = (event: Event) => {
    setSecondaryHue(parseInt((event.target as HTMLInputElement).value));
};

const handleIntensityChange = (event: Event) => {
    setIntensity(parseInt((event.target as HTMLInputElement).value));
};

const handleRadiusChange = (event: Event) => {
    setRadius(parseFloat((event.target as HTMLInputElement).value));
};

const handleFontWeightChange = (event: Event) => {
    setFontWeight(parseInt((event.target as HTMLInputElement).value));
};

const handleBorderWidthChange = (event: Event) => {
    setBorderWidth(parseFloat((event.target as HTMLInputElement).value));
};

const handleShadowIntensityChange = (event: Event) => {
    setShadowIntensity(parseInt((event.target as HTMLInputElement).value));
};

const handleSpacingChange = (event: Event) => {
    setSpacing(parseInt((event.target as HTMLInputElement).value));
};

const resetToDefaults = () => {
    setPrimaryHue(286);
    setSecondaryHue(286);
    setIntensity(100);
    setRadius(0.625);
    setFontFamily('instrument-sans');
    setFontWeight(400);
    setBorderWidth(1);
    setShadowIntensity(100);
    setSpacing(100);
    setBackgroundStyle('solid');
};

const hueGradient =
    'linear-gradient(to right, hsl(0, 80%, 50%), hsl(60, 80%, 50%), hsl(120, 80%, 50%), hsl(180, 80%, 50%), hsl(240, 80%, 50%), hsl(300, 80%, 50%), hsl(360, 80%, 50%))';
</script>

<template>
    <div class="space-y-4 p-3 min-w-72">
        <div class="flex justify-between items-center">
            <span class="font-medium text-foreground text-sm">Theme Customization</span>
            <Button variant="ghost" size="icon" class="w-7 h-7" title="Reset" @click="resetToDefaults">
                <RotateCcw class="w-3.5 h-3.5" />
            </Button>
        </div>

        <!-- Primary Hue -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Primary Hue</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ primaryHue }}°</span>
            </div>
            <input
                type="range"
                :value="primaryHue"
                min="0"
                max="360"
                step="1"
                class="rounded-full w-full h-2 appearance-none cursor-pointer"
                :style="{ background: hueGradient }"
                @input="handlePrimaryChange"
            />
        </div>

        <!-- Secondary Hue -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Secondary Hue</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ secondaryHue }}°</span>
            </div>
            <input
                type="range"
                :value="secondaryHue"
                min="0"
                max="360"
                step="1"
                class="rounded-full w-full h-2 appearance-none cursor-pointer"
                :style="{ background: hueGradient }"
                @input="handleSecondaryChange"
            />
        </div>

        <!-- Intensity -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Intensity</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ intensity }}%</span>
            </div>
            <input
                type="range"
                :value="intensity"
                min="0"
                max="100"
                step="1"
                class="bg-gradient-to-r from-muted to-primary rounded-full w-full h-2 appearance-none cursor-pointer"
                @input="handleIntensityChange"
            />
        </div>

        <!-- Roundedness -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Roundness</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ radius.toFixed(2) }}rem</span>
            </div>
            <input
                type="range"
                :value="radius"
                min="0"
                max="2"
                step="0.05"
                class="bg-muted rounded-full w-full h-2 appearance-none cursor-pointer"
                @input="handleRadiusChange"
            />
        </div>

        <!-- Font Family -->
        <div class="space-y-1.5">
            <Label class="text-muted-foreground text-xs">Font</Label>
            <div class="gap-1.5 grid grid-cols-2">
                <Button
                    v-for="(stack, key) in FONT_STACKS"
                    :key="key"
                    :variant="fontFamily === key ? 'default' : 'outline'"
                    size="sm"
                    class="h-7 text-xs"
                    :style="{ fontFamily: stack }"
                    @click="setFontFamily(key as FontFamily)"
                >
                    {{ fontLabels[key as FontFamily] }}
                </Button>
            </div>
        </div>

        <!-- Font Weight -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Font Weight</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ fontWeight }}</span>
            </div>
            <input
                type="range"
                :value="fontWeight"
                min="300"
                max="700"
                step="100"
                class="bg-muted rounded-full w-full h-2 appearance-none cursor-pointer"
                @input="handleFontWeightChange"
            />
        </div>

        <!-- Border Width -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Borders</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ borderWidth }}px</span>
            </div>
            <input
                type="range"
                :value="borderWidth"
                min="0"
                max="10"
                step="0.5"
                class="bg-muted rounded-full w-full h-2 appearance-none cursor-pointer"
                @input="handleBorderWidthChange"
            />
        </div>

        <!-- Shadow Intensity -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Shadows</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ shadowIntensity }}%</span>
            </div>
            <input
                type="range"
                :value="shadowIntensity"
                min="0"
                max="200"
                step="10"
                class="bg-muted rounded-full w-full h-2 appearance-none cursor-pointer"
                @input="handleShadowIntensityChange"
            />
        </div>

        <!-- Spacing / Density -->
        <div class="space-y-1.5">
            <div class="flex justify-between items-center">
                <Label class="text-muted-foreground text-xs">Density</Label>
                <span class="tabular-nums text-muted-foreground text-xs">{{ spacing }}%</span>
            </div>
            <input
                type="range"
                :value="spacing"
                min="75"
                max="125"
                step="5"
                class="bg-muted rounded-full w-full h-2 appearance-none cursor-pointer"
                @input="handleSpacingChange"
            />
        </div>

        <!-- Background Style -->
        <div class="space-y-1.5">
            <Label class="text-muted-foreground text-xs">Background</Label>
            <div class="gap-1.5 grid grid-cols-4">
                <Button
                    v-for="style in backgroundStyles"
                    :key="style.key"
                    :variant="backgroundStyle === style.key ? 'default' : 'outline'"
                    size="sm"
                    class="h-7 text-xs"
                    @click="setBackgroundStyle(style.key)"
                >
                    {{ style.label }}
                </Button>
            </div>
        </div>

        <!-- Preview -->
        <div class="flex gap-1.5 pt-1">
            <div class="flex-1 bg-primary rounded h-6" title="Primary"></div>
            <div class="flex-1 bg-secondary border border-border rounded h-6" title="Secondary"></div>
            <div class="flex-1 bg-muted rounded h-6" title="Muted"></div>
            <div class="flex-1 bg-accent rounded h-6" title="Accent"></div>
        </div>

        <!-- Presets -->
        <div class="space-y-1.5 pt-1 border-border border-t">
            <Label class="text-muted-foreground text-xs">Presets</Label>
            <div class="gap-1.5 grid grid-cols-3">
                <Button
                    v-for="preset in presets"
                    :key="preset.name"
                    variant="outline"
                    size="sm"
                    class="h-7 text-xs"
                    @click="applyPreset(preset)"
                >
                    <span
                        class="mr-1.5 rounded-full w-2.5 h-2.5"
                        :style="{ background: `oklch(0.5 0.2 ${preset.primary})` }"
                    ></span>
                    {{ preset.name }}
                </Button>
            </div>
        </div>
    </div>
</template>

<style scoped>
input[type='range']::-webkit-slider-thumb {
    appearance: none;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: white;
    border: 2px solid oklch(0.5 0.15 var(--primary-hue));
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

input[type='range']::-moz-range-thumb {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: white;
    border: 2px solid oklch(0.5 0.15 var(--primary-hue));
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
</style>
