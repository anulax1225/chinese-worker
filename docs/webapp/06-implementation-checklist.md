# Implementation Checklist

Step-by-step guide to implementing the webapp design enhancements.

## Phase 1: Foundation (Colors & Theme)

### 1.1 Update CSS Color Variables

**File:** `resources/css/app.css`

- [ ] Add configurable brand color hue variables
- [ ] Define primary, secondary, accent color scales
- [ ] Add light mode semantic colors
- [ ] Add dark mode semantic colors
- [ ] Add shadow variables (xs through xl)

```bash
# Verification
npm run build  # Should complete without errors
```

### 1.2 Create Theme Composable

**File:** `resources/js/composables/useTheme.ts`

- [ ] Create `useTheme` composable
- [ ] Implement `theme` ref (light/dark/system)
- [ ] Implement `resolvedTheme` computed
- [ ] Add `toggleTheme` function
- [ ] Add `setTheme` function
- [ ] Handle localStorage persistence
- [ ] Listen for system preference changes

### 1.3 Create Theme Toggle Component

**File:** `resources/js/components/ThemeToggle.vue`

- [ ] Create button component
- [ ] Show Sun icon in dark mode
- [ ] Show Moon icon in light mode
- [ ] Wire to `useTheme` composable

### 1.4 Test Dark Mode

- [ ] Test Dashboard page
- [ ] Test Agents list and show pages
- [ ] Test Conversations page
- [ ] Test Settings pages
- [ ] Test all form components
- [ ] Test modals and dropdowns
- [ ] Verify contrast ratios meet WCAG AA

---

## Phase 2: Layout Updates

### 2.1 Create Header Component

**File:** `resources/js/components/AppHeader.vue`

- [ ] Fixed position header (h-14)
- [ ] Logo on left
- [ ] Breadcrumbs (hidden on mobile)
- [ ] Theme toggle on right
- [ ] User avatar dropdown on right
- [ ] Blur backdrop when scrolled

### 2.2 Create Breadcrumbs Component

**File:** `resources/js/components/Breadcrumbs.vue`

- [ ] Accept breadcrumbs from page props
- [ ] Fallback to URL-based generation
- [ ] Home icon as first item
- [ ] Truncate long names
- [ ] Mobile: show back arrow + current page only

### 2.3 Update Sidebar

**File:** `resources/js/components/AppSidebar.vue` (or existing sidebar)

- [ ] Update width (w-14 collapsed, w-64 expanded)
- [ ] Add top offset for header (top-14)
- [ ] Update settings icons:
  - Profile: `User`
  - Password: `Lock`
  - API Tokens: `Terminal`
  - Two-Factor: `ShieldCheck`
- [ ] Add active state styling
- [ ] Mobile: convert to Sheet

### 2.4 Update AppLayout

**File:** `resources/js/layouts/AppLayout.vue`

- [ ] Add AppHeader component
- [ ] Add content offset for header (pt-14)
- [ ] Add content offset for sidebar (ml-14 lg:ml-64)
- [ ] Update main content padding (p-4)

### 2.5 Update Controllers for Breadcrumbs

- [ ] AgentController: add breadcrumbs to show/edit
- [ ] ConversationController: add breadcrumbs to show
- [ ] SettingsController: add breadcrumbs to each page

```bash
# Verification
npm run build
php artisan test --compact
# Manual: Navigate through app, verify header and breadcrumbs
```

---

## Phase 3: Component Updates

### 3.1 Add Sheet Component

**Directory:** `resources/js/components/ui/sheet/`

- [ ] Check if Sheet already exists in shadcn/ui setup
- [ ] If not, add Sheet component from shadcn/ui
- [ ] Configure slide-from-right animation
- [ ] Set default width (w-[480px])

### 3.2 Convert Create Pages to Sheets

| Page | Status |
|------|--------|
| [ ] Create Agent | Sheet from `/agents` |
| [ ] Create Tool | Sheet from `/agents/{id}` |
| [ ] New Conversation | Sheet from `/conversations` |
| [ ] Create API Token | Sheet from settings |

For each:
- [ ] Move form to Sheet component
- [ ] Add SheetTrigger button
- [ ] Update form submission to close sheet
- [ ] Handle validation errors in sheet
- [ ] Test mobile responsiveness

### 3.3 Convert Edit Pages to Sheets

| Page | Status |
|------|--------|
| [ ] Edit Agent | Sheet from agent show page |
| [ ] Edit Tool | Sheet from tool section |

### 3.4 Update Shadow Utilities

**File:** `resources/css/app.css`

- [ ] Add shadow CSS variables
- [ ] Verify Tailwind picks them up

### 3.5 Update Card Styles

- [ ] Review all Card usages
- [ ] Update padding (p-6 → p-4)
- [ ] Add hover states to interactive cards
- [ ] Ensure consistent border usage

### 3.6 Update Table Styles

- [ ] Reduce row padding (py-4 → py-2.5)
- [ ] Add uppercase tracking to headers
- [ ] Review all table pages

```bash
# Verification
npm run build
php artisan test --compact
# Manual: Test create/edit flows with sheets
```

---

## Phase 4: Conversation Redesign

### 4.1 Update Message Bubble Styles

**File:** `resources/js/pages/Conversations/Show.vue`

- [ ] User messages: primary bg, right-aligned, rounded-br-md
- [ ] Assistant messages: muted bg, left-aligned, rounded-tl-md
- [ ] Add agent avatar to assistant messages
- [ ] Add timestamps

### 4.2 Improve Thinking Display

- [ ] Make thinking collapsible by default (`<details>`)
- [ ] Add expand/collapse icon
- [ ] Style with italic, muted foreground
- [ ] Add left border accent

### 4.3 Enhance Tool Request UI

**File:** `resources/js/components/ToolRequestDialog.vue` (or inline)

- [ ] Distinct accent-colored card
- [ ] Tool name badge
- [ ] Formatted arguments display
- [ ] Approve/Reject buttons
- [ ] Loading state during submission

### 4.4 Improve Code Blocks

- [ ] Install shiki: `npm install shiki`
- [ ] Create CodeBlock component
- [ ] Add syntax highlighting
- [ ] Add language badge
- [ ] Add copy button
- [ ] Style for dark mode

### 4.5 Polish Input Area

- [ ] Make sticky at bottom
- [ ] Textarea auto-resize
- [ ] Add keyboard shortcut hint
- [ ] Style send button
- [ ] Disabled state when empty

### 4.6 Add Streaming Animations

- [ ] Blinking cursor during stream
- [ ] Smooth content appearance
- [ ] Connection status indicator
- [ ] Auto-scroll behavior

### 4.7 Update Conversation Header

- [ ] Agent avatar and name
- [ ] Connection status badge
- [ ] Actions dropdown
- [ ] Sticky below main header

```bash
# Verification
npm run build
# Manual: Test full conversation flow
# - Send message
# - Observe streaming
# - Tool request approval
# - Error states
```

---

## Phase 5: Polish

### 5.1 Consistency Review

- [ ] Dashboard page spacing
- [ ] Agents list page
- [ ] Agent show page
- [ ] Conversations list page
- [ ] Conversation show page
- [ ] Settings profile page
- [ ] Settings password page
- [ ] Settings API tokens page
- [ ] Settings two-factor page
- [ ] Login page
- [ ] Register page

For each page:
- [ ] Correct padding (p-4)
- [ ] Correct typography scale
- [ ] Cards styled consistently
- [ ] Dark mode works
- [ ] Breadcrumbs display

### 5.2 Mobile Responsiveness

Test on:
- [ ] iPhone SE (375px)
- [ ] iPhone 14 (390px)
- [ ] iPad (768px)
- [ ] Desktop (1024px+)

Check:
- [ ] Header collapses correctly
- [ ] Sidebar becomes sheet
- [ ] Breadcrumbs show back arrow
- [ ] Cards stack vertically
- [ ] Tables scroll horizontally
- [ ] Forms are usable

### 5.3 Accessibility Audit

- [ ] All images have alt text
- [ ] Color contrast meets WCAG AA
- [ ] Focus indicators visible
- [ ] Keyboard navigation works
- [ ] Screen reader testing (VoiceOver/NVDA)
- [ ] ARIA labels on interactive elements

### 5.4 Performance Review

- [ ] Run Lighthouse audit
- [ ] Check bundle size
- [ ] Verify no layout shifts
- [ ] Test on slow 3G throttling

### 5.5 Browser Testing

- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile Safari (iOS)
- [ ] Mobile Chrome (Android)

```bash
# Final verification
npm run build
php artisan test --compact
php artisan pint --dirty
```

---

## Files Changed Summary

### New Files

```
resources/js/
├── composables/
│   └── useTheme.ts
├── components/
│   ├── AppHeader.vue
│   ├── Breadcrumbs.vue
│   ├── ThemeToggle.vue
│   ├── MobileNav.vue
│   └── ui/
│       ├── sheet/
│       │   ├── index.ts
│       │   ├── Sheet.vue
│       │   ├── SheetContent.vue
│       │   ├── SheetHeader.vue
│       │   ├── SheetFooter.vue
│       │   ├── SheetTitle.vue
│       │   └── SheetDescription.vue
│       └── code-block/
│           └── CodeBlock.vue
```

### Modified Files

```
resources/css/app.css                          # Color system, shadows
resources/js/layouts/AppLayout.vue             # Header, sidebar layout
resources/js/components/AppSidebar.vue         # Icon updates, width
resources/js/pages/Agents/Index.vue            # Sheet for create
resources/js/pages/Agents/Show.vue             # Sheet for edit/tools
resources/js/pages/Conversations/Index.vue     # Sheet for new
resources/js/pages/Conversations/Show.vue      # Complete redesign
resources/js/pages/Settings/*.vue              # Spacing, sheets
app/Http/Controllers/Web/*Controller.php       # Breadcrumbs props
```

### NPM Packages to Install

```bash
npm install shiki  # Syntax highlighting for code blocks
```

---

## Rollback Plan

If issues arise:

1. **Git reset:** All changes are committed incrementally
2. **Feature flags:** Consider adding `app.use_new_design` config
3. **A/B testing:** Can run both designs side-by-side

---

## Post-Launch

- [ ] Monitor for console errors
- [ ] Collect user feedback
- [ ] Track performance metrics
- [ ] Document any discovered issues
- [ ] Plan follow-up improvements
