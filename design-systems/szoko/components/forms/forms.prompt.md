# Forms

Warm, softly-inset form controls for the shop.

```jsx
import { Input } from './Input';
import { Select } from './Select';
import { QuantityStepper } from './QuantityStepper';
import { Icon } from '../core/Icon';

<Input label="Email" placeholder="you@example.com" icon={<Icon name="user" size={18} />} />
<Input label="Postcode" error="Required for delivery" />
<Select label="Gift box size" options={['4 pieces', '9 pieces', '16 pieces']} />
<QuantityStepper value={qty} onChange={setQty} />
```

- **Input** — `label`, `hint`, `error`, `icon`, sizes md/lg. Focus shows caramel ring.
- **Select** — native select with chevron; `options` as strings or `{value,label}`.
- **QuantityStepper** — controlled −/＋ pill; `value`/`onChange`, `min`/`max`, sizes sm/md.
