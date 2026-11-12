# Mister Szoko — Design System

A warm, appetising brand system for **Mister Szoko**, a webshop selling artisanal
chocolate. Everything here is rooted in one supplied asset — the logo — and built
outward into a full premium-chocolate e‑commerce identity.

> **Source materials provided:** a single logo PNG
> (`uploads/Capture d'écran 2026-07-21 144243.png`, copied to `assets/logo.png`).
> No codebase, Figma file, brand guide, or product copy was supplied. All content,
> tone, colour, type and component decisions below are original, chosen to fit the
> logo and the artisanal-chocolate category. Treat them as a proposed foundation to
> refine with real brand inputs.

## The logo
A smooth, dark-chocolate **pebble/blob** silhouette containing a stylised profile
of a man (quiffed hair, glasses) rendered in negative space (cream). Wordmark
"MISTER SZOKO" set bold. The single sampled brand colour is a deep cocoa brown
**`#41281A`** — the seed of the entire palette.

---

## CONTENT FUNDAMENTALS
*(Proposed voice — no real product copy was provided.)*

- **Persona.** "Mister Szoko" is a character — a slightly theatrical, warm-hearted
  chocolatier. Copy speaks in his confident, generous, sensory voice.
- **Person & address.** Warm second person to the shopper ("your bar", "made for
  you"); first person plural for the maker ("we temper", "our beans"). Occasional
  first-person from Mister Szoko himself as a signature flourish.
- **Tone.** Indulgent but not fussy; craft-proud, never clinical. Sensory verbs
  (melt, snap, roast, temper, bloom). Confident, short declaratives.
- **Casing.** Sentence case for body and buttons ("Add to basket"). Small‑caps /
  uppercase with wide tracking reserved for eyebrows and labels ("SINGLE ORIGIN").
  Display headlines in the serif, sentence case, sometimes with an italic accent.
- **Emoji.** None. The brand expresses warmth through imagery, colour and type —
  not emoji.
- **Numbers & units.** Cocoa percentages are a hero detail ("70% dark"). Weights in
  grams, prices with the store currency symbol, monospaced for a deli-label feel.
- **Examples.**
  - Hero: *"Chocolate, the way Mister Szoko intended."*
  - Eyebrow: `SINGLE ORIGIN · MADAGASCAR`
  - Product blurb: *"Slow-roasted Criollo beans, tempered by hand for a clean snap
    and a long, fruity finish."*
  - CTA: `Add to basket` · `Build your box`
  - Reassurance: *"Packed cold. Shipped fast. Melts only in your mouth."*

---

## VISUAL FOUNDATIONS

**Colour.** Edible and warm. A deep-chocolate primary scale (`--choco-*`, core
`#41281A`), warm cream neutrals (`--cream-*`, page bg `#FBF6EF`), and two accents:
a caramel/gold (`--caramel-500` `#C68A3C`, plus foil gold `--gold-500`) for
premium highlights and CTAs-on-dark, and a raspberry **berry** (`--berry-500`) for
sale flags and editorial pops. Semantics are warm-tuned (no pure blue/red/green).
Max two background colours per surface: cream or chocolate.

**Type.** Editorial serif display (**DM Serif Display**) for headlines and the
brand's romantic voice; a friendly humanist sans (**Mulish**) for all UI and body;
a mono (**DM Mono**) for prices, cocoa %, weights and label eyebrows. Big
type-size contrast — large serif heroes over calm sans body. Uppercase eyebrows
carry `--tracking-caps` (0.16em).

**Backgrounds.** Solid warm cream or deep chocolate — never harsh white. No loud
gradients; at most a very soft warm vignette or a subtle cocoa-dusting grain on
hero panels. Product photography is the hero imagery: warm, close, softly lit,
slightly moody (see Imagery). Full-bleed chocolate panels break up cream sections.

**Spacing & layout.** 4px base grid (`--space-*`). Generous whitespace; content
container 1240px, narrow reading column 760px. Editorial asymmetry allowed in
marketing; product grids are tidy and even.

**Corners.** Soft throughout (`--radius-*`) — pebble-like, echoing the logo blob
and a praline's edges. Buttons and tags are pill (`--radius-pill`); cards `lg`/`xl`.
An organic `--radius-blob` is available for playful brand moments. Nothing sharp.

**Shadows.** Warm, **brown-tinted** (`rgba(46,22,12,…)`) — never neutral grey.
Soft and low. Product cards rest at `--shadow-sm` and lift to `--shadow-md` on
hover.

**Borders.** Thin, warm (`--border-subtle`/`--border-default` from the cream
scale). Used sparingly — separation is usually by surface colour and shadow, not
lines.

**Motion.** Gentle and unhurried — a "melt", not a bounce. `--ease-out` for
entrances/fades; `--ease-soft` for a subtle overshoot on interactive elements.
Durations 140/240/420ms. Respect `prefers-reduced-motion`.

**Hover / press.** Hover: gentle lift (`--lift`, translateY -4px) + deepen shadow;
buttons darken (accent → `--accent-hover`, brand → `--brand-hover`). Press: settle
back down (`translateY(0)`) and slightly scale in (~0.98). No opacity-only hovers.

**Transparency & blur.** Minimal. A light backdrop-blur only on sticky headers over
scrolling imagery and on modal scrims (chocolate at ~55% opacity). Otherwise solid.

**Imagery vibe.** Warm, appetising, tactile — golden/amber light, shallow depth of
field, close crops of chocolate texture (snap, shards, cocoa dust), natural props
(linen, wood, cacao pods). No cold tones, no heavy b&w. Optional fine grain.

---

## ICONOGRAPHY

- **No icon assets were provided.** The system uses **Lucide** (open-source,
  1.75px round-cap stroke) loaded from CDN as the substitute icon set — its rounded,
  friendly strokes match the soft brand geometry. **Flagged as a substitution;**
  swap for a bespoke set if the brand has one.
- Style: line icons, currentColor, ~1.75–2px stroke, rounded caps/joins. Sizes step
  16 / 20 / 24. Icons take `--text-body`/`--brand`, never a random hue.
- **No emoji** and no unicode-glyph icons in product UI.
- The **logo** (`assets/logo.png`) is the one true brand mark — used at small sizes
  in the header and as a large watermark motif on chocolate panels. Do not redraw
  or recolour it.

---

## Fonts caveat
The real brand fonts are unknown (only a logo was supplied). Substitutes chosen
from Google Fonts and loaded via `@import` in `tokens/fonts.css` (so no binaries are
shipped in-repo). Please supply licensed brand font files to replace them.

---

## INDEX / MANIFEST

**Root**
- `styles.css` — global entry (import this); `@import`s all token files.
- `readme.md` — this guide. · `SKILL.md` — Agent-Skill wrapper. · `thumbnail.html`.

**tokens/** — `fonts.css`, `colors.css`, `typography.css`, `spacing.css`,
`radius.css`, `shadow.css`, `motion.css`.

**assets/** — `logo.png` (the one supplied brand mark).

**components/** — reusable React primitives (see below).

**templates/webshop/** — retail storefront template (home, product page, basket).

**templates/couverture-shop/** — B2B couverture webshop template: catalogue,
product page (HT/TTC, formats 2,5/10/20 kg), cart upsell, VIES checkout, admin
back-office. Both register as Templates for consuming projects.

**guidelines/** — foundation specimen cards (Type, Colors, Spacing, Brand).

### Components
- `core/` — Button, IconButton, Tag, Badge, PriceTag
- `forms/` — Input, QuantityStepper, Select
- `commerce/` — ProductCard, RatingStars
- `layout/` — Card, SectionHeading

### Intentional additions
Since no source defined a component inventory, a standard e‑commerce set was
authored. `PriceTag`, `QuantityStepper`, `ProductCard` and `RatingStars` are
added specifically for the chocolate-shop use case.
