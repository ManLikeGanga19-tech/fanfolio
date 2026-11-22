# FanFolio

FanFolio is a discreet, premium home where African creators sell subscriptions, pay-per-view posts and messages to their fans. The interface stays dark and quiet so creators' photos and videos lead, and one warm amber marks everything that involves money or the main action on a screen.

This is a first, deliberately small system built from the approved wireframes. There is no source brand yet: no logo, no custom font files, no components. Extend it as the product grows.

## Voice and content

- **Discreet and confident.** Short, plain sentences. No slang, no innuendo in UI copy, even for adult creators. Example: "Unlock for 250 credits", not "Unlock the hot stuff 🔥".
- **Creator first.** Name the creator, not the platform: "Subscribe to Amani", "Amani's posts".
- **Money is always explicit.** Always show the credit amount and, where useful, the local-currency estimate: "250 credits · ≈ KES 330".
- **No emoji in UI copy.** Use icons where a symbol helps.
- **Safety copy is calm, not threatening:** "Content is watermarked to your account."

## Visual foundations

- **Dark by default.** The fan app uses the Dark theme on `bg` with content on `surface`. The creator studio and admin (Filament) support both themes; Dark is the default.
- **One amber action per view.** `brand` fills only the primary action (Subscribe, Unlock, Top up, Publish, Withdraw) and money figures. Secondary actions are outlined with `border-strong` on `surface`.
- **Media leads.** Photos and video sit edge to edge on mobile with `radius-lg` corners on desktop. Locked media is blurred with `overlay` over it, never a grey box in production.
- **Type.** Headings in Sora (`display`, `h1`–`h3`), everything else in Inter (`body`, `small`, `label`). Both are Google Fonts. Numbers for credits and earnings use Inter with tabular figures.
- **Spacing.** 4px base: `space-4` inside cards on mobile, `space-6` on desktop, `space-8` page gutters.
- **Shape.** `radius-md` for buttons and inputs, `radius-lg` for cards and sheets, `radius-pill` for the balance chip, avatars and filter pills.
- **States.** `success` = done (payment confirmed, payout sent). `warning` = waiting (7-day hold, under review). `danger` = removed, failed or destructive. Always pair a state colour with a word or icon, never colour alone.
- **Focus.** Every interactive element shows a 2px `focus` ring with a 2px offset.

## Iconography

Lucide icons (MIT), 1.5px stroke, 20px in UI and 24px in the bottom navigation, coloured `ink` or `ink-muted`; `brand` only on the active nav item. No filled or multicolour icons.

## Logo

No logo yet. Until one exists, set the wordmark "FanFolio" in Sora 700 in `ink`, with no symbol.

## Using it in the build

- **Tailwind (fan PWA):** map each colour token to a CSS variable per theme and expose them as `bg`, `surface`, `ink`, `brand` and so on in `tailwind.config`; spacing and radius tokens extend the default scale.
- **Filament (studio and admin):** set the panel's `primary` colour to the `brand` amber palette, the font to Inter, and dark mode on by default; use `danger`, `warning` and `success` for Filament's matching colours.

## Accessibility

Text meets 4.5:1 on its ground in both themes (`ink-muted` on `surface` included); control borders (`border-strong`) and the `focus` ring meet 3:1. Touch targets are at least 44px.
