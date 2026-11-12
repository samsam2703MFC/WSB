# Commerce

Shop-specific pieces built on the core + layout primitives.

```jsx
import { ProductCard } from './ProductCard';
import { RatingStars } from './RatingStars';

<RatingStars value={4.6} count={128} showValue />

<ProductCard
  name="Madagascar 70%" origin="Madagascar" cocoa="70% dark"
  price="8.50" was="10.00" badge={{ tone: 'sale', label: '-15%' }}
  rating={4.6} count={128} onAdd={() => {}} />
```

- **ProductCard** — full tile: image (or chocolate placeholder), corner `badge`,
  wishlist toggle, `origin`/`cocoa` tags, serif `name`, `rating`, `PriceTag`,
  add-to-basket. Lifts on hover. Pass `image` URL for real photography.
- **RatingStars** — gold stars 0–5; `showValue` adds the numeric value + `count`.
