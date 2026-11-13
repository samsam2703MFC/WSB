# Layout

Structural pieces: surface cards and editorial section headers.

```jsx
import { Card } from './Card';
import { SectionHeading } from './SectionHeading';

<SectionHeading eyebrow="Our collection" title="Bars worth slowing down for"
  lead="Single-origin chocolate, tempered by hand in small batches." align="center" />

<Card hover padding="var(--space-5)">…</Card>
<Card tone="choco">…</Card>   {/* light text on chocolate */}
```

- **Card** — tones `card`/`raised`/`choco`; `hover` for product-card lift; `padding`, `radius`.
- **SectionHeading** — `eyebrow` + serif `title` + `lead`; `align`, `invert` (for dark panels).
