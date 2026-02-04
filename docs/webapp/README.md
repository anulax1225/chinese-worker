# Webapp Design System

A comprehensive design system for the Chinese Worker webapp, focused on creating a professional, compact, and themeable interface.

## Quick Reference

| Document | Purpose |
|----------|---------|
| [01-color-system.md](./01-color-system.md) | Configurable color palette, OKLCH variables, dark mode |
| [02-layout-components.md](./02-layout-components.md) | Header bar, sidebar, spacing system |
| [03-ui-components.md](./03-ui-components.md) | Sheets, cards, shadows, buttons |
| [04-conversation-design.md](./04-conversation-design.md) | Chat UI, messages, streaming, code blocks |
| [05-navigation.md](./05-navigation.md) | Breadcrumbs, routing patterns |
| [06-implementation-checklist.md](./06-implementation-checklist.md) | Step-by-step implementation guide |

## Design Principles

### 1. Compact & Efficient
- Reduced padding and margins
- Tighter table rows
- Smaller secondary text
- Maximum content visibility

### 2. Themeable
- Configurable primary/secondary/accent colors via CSS variables
- Dark mode with primary-tinted backgrounds
- Easy preset switching

### 3. Professional
- Consistent visual language
- Subtle shadows and borders
- Smooth transitions
- Clear visual hierarchy

### 4. Accessible
- Sufficient color contrast
- Keyboard navigation
- Focus indicators
- Screen reader friendly

## Key Changes from Current Design

| Area | Before | After |
|------|--------|-------|
| Create/Edit forms | Dialogs (centered modals) | Sheets (slide from right) |
| Theme | Light only | Dark/Light toggle |
| Colors | Default shadcn | Configurable primary/secondary |
| Layout | Full-width sidebar | Compact sidebar + top header |
| Navigation | Sidebar links | Breadcrumbs in header |
| Settings icons | Duplicate icons | Distinct icons per section |
| Spacing | `p-6` padding | `p-4` compact padding |
| Shadows | Default Tailwind | Refined OKLCH shadows |

## Tech Stack

- **CSS**: Tailwind CSS v4 with OKLCH color space
- **Components**: shadcn/ui Vue components
- **Icons**: Lucide Vue
- **Animations**: Tailwind + CSS transitions

## File Structure

```
resources/
├── css/
│   └── app.css                    # Color variables, shadows
├── js/
│   ├── components/
│   │   ├── ui/
│   │   │   └── sheet/             # Sheet components
│   │   ├── AppHeader.vue          # New top header bar
│   │   ├── Breadcrumbs.vue        # Navigation breadcrumbs
│   │   └── ThemeToggle.vue        # Dark/light toggle
│   ├── layouts/
│   │   └── AppLayout.vue          # Updated layout
│   └── pages/
│       └── Conversations/
│           └── Show.vue           # Enhanced chat UI
```

## Getting Started

1. Read through [01-color-system.md](./01-color-system.md) to understand the color configuration
2. Follow the [06-implementation-checklist.md](./06-implementation-checklist.md) for step-by-step implementation
3. Refer to component-specific docs as needed

## Color Presets

The design supports multiple color presets. To switch, update the hue values in `resources/css/app.css`:

| Preset | Primary | Secondary | Character |
|--------|---------|-----------|-----------|
| **Electric Blue** (default) | Blue `#3B82F6` | Purple `#8B5CF6` | Modern, tech |
| **Emerald** | Green `#10B981` | Blue `#3B82F6` | Fresh, natural |
| **Indigo** | Indigo `#6366F1` | Pink `#EC4899` | Creative, playful |
| **Rose** | Rose `#F43F5E` | Purple `#8B5CF6` | Bold, energetic |
| **Amber** | Amber `#F59E0B` | Blue `#3B82F6` | Warm, inviting |
