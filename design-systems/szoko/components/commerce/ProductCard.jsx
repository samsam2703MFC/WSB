import React from 'react';
import { Card } from '../layout/Card';
import { PriceTag } from '../core/PriceTag';
import { Badge } from '../core/Badge';
import { Tag } from '../core/Tag';
import { IconButton } from '../core/IconButton';
import { Icon } from '../core/Icon';
import { RatingStars } from './RatingStars';

/**
 * Product tile for shop grids. Pass an `image` URL, or leave it out for a warm
 * chocolate placeholder swatch (no product photography was supplied to the system).
 */
export function ProductCard({
  name, origin, cocoa, price, was, image, badge, rating, count,
  wishlisted = false, onWishlist, onAdd, style, ...rest
}) {
  const [wish, setWish] = React.useState(wishlisted);
  return (
    <Card hover padding="0" radius="var(--radius-lg)" style={{ display: 'flex', flexDirection: 'column', ...style }} {...rest}>
      <div style={{
        position: 'relative', aspectRatio: '1 / 1', overflow: 'hidden',
        background: image ? `center/cover no-repeat url("${image}")`
          : 'radial-gradient(120% 120% at 30% 20%, var(--choco-500), var(--choco-800))',
      }}>
        {!image && (
          <div style={{
            position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
            color: 'var(--choco-200)', fontFamily: 'var(--font-mono)', fontSize: 'var(--text-2xs)',
            letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', opacity: 0.6,
          }}>Product photo</div>
        )}
        {badge && <div style={{ position: 'absolute', top: 12, left: 12 }}><Badge tone={badge.tone}>{badge.label}</Badge></div>}
        <div style={{ position: 'absolute', top: 10, right: 10 }}>
          <IconButton label="Add to wishlist" variant="soft" size="sm"
            onClick={() => { setWish((w) => !w); onWishlist && onWishlist(!wish); }}
            style={wish ? { color: 'var(--berry-500)' } : undefined}>
            <Icon name="heart" />
          </IconButton>
        </div>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', padding: 'var(--space-5)' }}>
        {(origin || cocoa) && (
          <div style={{ display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
            {origin && <Tag tone="origin" icon={<Icon name="leaf" size={12} />}>{origin}</Tag>}
            {cocoa && <Tag tone="accent">{cocoa}</Tag>}
          </div>
        )}
        <h3 style={{
          fontFamily: 'var(--font-display)', fontSize: 'var(--text-xl)', lineHeight: 'var(--leading-snug)',
          color: 'var(--text-strong)', margin: 0, fontWeight: 700,
        }}>{name}</h3>
        {rating != null && <RatingStars value={rating} count={count} showValue size={15} />}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--space-3)', marginTop: 'var(--space-1)' }}>
          <PriceTag amount={price} was={was} size="md" />
          <IconButton label={`Add ${name} to basket`} variant="solid" onClick={onAdd}>
            <Icon name="bag" />
          </IconButton>
        </div>
      </div>
    </Card>
  );
}
