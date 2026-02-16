<script setup>
import { useTheme } from '@/composables/useTheme'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { RotateCcw } from 'lucide-vue-next'

const { primaryHue, secondaryHue, intensity, radius, fontFamily, fontWeight, borderWidth, shadowIntensity, spacing, backgroundStyle, FONT_STACKS, setPrimaryHue, setSecondaryHue, setIntensity, setRadius, setFontFamily, setFontWeight, setBorderWidth, setShadowIntensity, setSpacing, setBackgroundStyle } = useTheme()

const fontLabels = {
  'instrument-sans': 'Instrument',
  'inter': 'Inter',
  'nunito': 'Nunito',
  'poppins': 'Poppins',
  'dm-sans': 'DM Sans',
  'system': 'Système'
}

const backgroundStyles = [
  { key: 'solid', label: 'Uni' },
  { key: 'gradient', label: 'Dégradé' },
  { key: 'radial', label: 'Radial' },
  { key: 'mesh', label: 'Mesh' },
]

const presets = [
  { name: 'Violet', primary: 286, secondary: 286 },
  { name: 'Bleu', primary: 230, secondary: 200 },
  { name: 'Vert', primary: 140, secondary: 120 },
  { name: 'Orange', primary: 40, secondary: 20 },
  { name: 'Rouge', primary: 10, secondary: 350 },
  { name: 'Cyan', primary: 195, secondary: 180 },
]

const applyPreset = (preset) => {
  setPrimaryHue(preset.primary)
  setSecondaryHue(preset.secondary)
}

const handlePrimaryChange = (event) => {
  setPrimaryHue(parseInt(event.target.value))
}

const handleSecondaryChange = (event) => {
  setSecondaryHue(parseInt(event.target.value))
}

const handleIntensityChange = (event) => {
  setIntensity(parseInt(event.target.value))
}

const handleRadiusChange = (event) => {
  setRadius(parseFloat(event.target.value))
}

const handleFontWeightChange = (event) => {
  setFontWeight(parseInt(event.target.value))
}

const handleBorderWidthChange = (event) => {
  setBorderWidth(parseFloat(event.target.value))
}

const handleShadowIntensityChange = (event) => {
  setShadowIntensity(parseInt(event.target.value))
}

const handleSpacingChange = (event) => {
  setSpacing(parseInt(event.target.value))
}

const resetToDefaults = () => {
  setPrimaryHue(286)
  setSecondaryHue(286)
  setIntensity(100)
  setRadius(0.625)
  setFontFamily('instrument-sans')
  setFontWeight(400)
  setBorderWidth(1)
  setShadowIntensity(100)
  setSpacing(100)
  setBackgroundStyle('solid')
}

const hueGradient = 'linear-gradient(to right, hsl(0, 80%, 50%), hsl(60, 80%, 50%), hsl(120, 80%, 50%), hsl(180, 80%, 50%), hsl(240, 80%, 50%), hsl(300, 80%, 50%), hsl(360, 80%, 50%))'
</script>

<template>
  <div class="space-y-4 p-3 min-w-72">
    <div class="flex justify-between items-center">
      <span class="font-medium text-foreground text-sm">Personnalisation du thème</span>
      <Button variant="ghost" size="icon" class="w-7 h-7" @click="resetToDefaults" title="Réinitialiser">
        <RotateCcw class="w-3.5 h-3.5" />
      </Button>
    </div>

    <!-- Primary Hue -->
    <div class="space-y-1.5">
      <div class="flex justify-between items-center">
        <Label class="text-muted-foreground text-xs">Teinte primaire</Label>
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
        <Label class="text-muted-foreground text-xs">Teinte secondaire</Label>
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
        <Label class="text-muted-foreground text-xs">Intensité</Label>
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
        <Label class="text-muted-foreground text-xs">Arrondi</Label>
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
      <Label class="text-muted-foreground text-xs">Police</Label>
      <div class="gap-1.5 grid grid-cols-2">
        <Button
          v-for="(stack, key) in FONT_STACKS"
          :key="key"
          :variant="fontFamily === key ? 'default' : 'outline'"
          size="sm"
          class="h-7 text-xs"
          :style="{ fontFamily: stack }"
          @click="setFontFamily(key)"
        >
          {{ fontLabels[key] }}
        </Button>
      </div>
    </div>

    <!-- Font Weight -->
    <div class="space-y-1.5">
      <div class="flex justify-between items-center">
        <Label class="text-muted-foreground text-xs">Graisse</Label>
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
        <Label class="text-muted-foreground text-xs">Bordures</Label>
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
        <Label class="text-muted-foreground text-xs">Ombres</Label>
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
        <Label class="text-muted-foreground text-xs">Densité</Label>
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
      <Label class="text-muted-foreground text-xs">Arrière-plan</Label>
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
      <div class="flex-1 bg-primary rounded h-6" title="Primaire"></div>
      <div class="flex-1 bg-secondary border border-border rounded h-6" title="Secondaire"></div>
      <div class="flex-1 bg-muted rounded h-6" title="Atténué"></div>
      <div class="flex-1 bg-accent rounded h-6" title="Accent"></div>
    </div>

    <!-- Presets -->
    <div class="space-y-1.5 pt-1 border-border border-t">
      <Label class="text-muted-foreground text-xs">Préréglages</Label>
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
input[type="range"]::-webkit-slider-thumb {
  appearance: none;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: white;
  border: 2px solid oklch(0.5 0.15 var(--primary-hue));
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

input[type="range"]::-moz-range-thumb {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: white;
  border: 2px solid oklch(0.5 0.15 var(--primary-hue));
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
</style>
