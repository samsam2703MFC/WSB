# Core

Brand primitives: buttons, icons, tags, badges, prices.

```jsx
import { Button } from './Button';
import { Icon } from './Icon';
import { IconButton } from './IconButton';
import { Tag } from './Tag';
import { Badge } from './Badge';
import { PriceTag } from './PriceTag';

<Button variant="primary" iconRight={<Icon name="arrowRight" />}>Build your box</Button>
<Button variant="accent">Add to basket</Button>
<IconButton label="Wishlist" variant="soft"><Icon name="heart" /></IconButton>
<Tag tone="origin" icon={<Icon name="leaf" size={13} />}>Single origin</Tag>
<Badge tone="sale">-20%</Badge>
<PriceTag amount="8.50" was="10.00" size="lg" />
```

- **Button** — variants `primary` (chocolate), `accent` (caramel), `secondary`
  (outline), `ghost`; sizes sm/md/lg; `block`, `iconLeft`/`iconRight`, `as="a"`.
- **IconButton** — circular; `solid`/`soft`/`ghost`/`outline`; pass one `<Icon/>`.
- **Tag** — uppercase mono eyebrow; tones origin/accent/berry/plain.
- **Badge** — image flag; tones sale/new/gold/soft.
- **PriceTag** — monospace figures; pass `was` for struck-through sale price.
- **Icon** — line glyphs by `name`; `StarIcon` supports `filled`.
