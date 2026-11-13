'use client';

import React from 'react';
import { Icon } from '../core/Icon';
import { Badge } from '../core/Badge';
import { Carousel, type CarouselItem } from './Carousel';

/**
 * A single ERP module on the landing: `tfb_modules` row + its screenshots.
 * Everything textual arrives already translated — the card never formats copy.
 */
export interface ModuleCardProps {
  /** Icon name from public/icons. One per module: shop → shopping-cart, invoicing → receipt, scan → qr-code, marketing → megaphone, ceobot → bot, pwa → smartphone. */
  icon?: string;
  /** Translated module name (tfb_translations field 'name'). */
  name: string;
  /** Translated short explanation (field 'description'). Two lines maximum. */
  description?: string;
  /** Rows from tfb_module_screenshots, mapped to {src, alt}. */
  screenshots?: CarouselItem[];
  /** tfb_modules.is_new — shows the ember "Nouveau" badge. */
  isNew?: boolean;
  /** Translated badge label; defaults to "Nouveau". */
  newLabel?: string;
  /** tfb_modules.redirect_url. */
  href?: string;
  /** Translated link label; defaults to "Découvrir le module". */
  linkLabel?: string;
  /** tfb_modules.module_group, shown as a small caption. */
  group?: string;
  /** Pass true for Arabic so the carousel and arrow mirror. */
  rtl?: boolean;
  /** Placeholder caption inside the carousel when a screenshot path is empty. */
  placeholderPath?: string;
  onClick?: (e: React.MouseEvent) => void;
  style?: React.CSSProperties;
}

export function ModuleCard({
  icon = 'layers', name, description, screenshots = [], isNew = false, newLabel = 'Nouveau',
  href, linkLabel = 'Découvrir le module', group, rtl = false, placeholderPath, style, ...rest
}: ModuleCardProps) {
  const [hover, setHover] = React.useState(false);
  return (
    <article
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      {...rest}
      style={{
        display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', padding: 'var(--space-5)',
        background: 'var(--surface-card)', border: '1px solid var(--border-default)',
        borderRadius: 'var(--radius-card)',
        boxShadow: hover ? 'var(--shadow-md)' : 'var(--shadow-sm)',
        transform: hover ? 'translateY(-1px)' : 'none',
        transition: 'var(--transition-surface)', minWidth: 0, ...style,
      }}
    >
      <header style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)' }}>
        <span style={{
          display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 40, height: 40, flex: 'none',
          borderRadius: 'var(--radius-md)', background: 'var(--surface-brand-subtle)', color: 'var(--text-brand)',
        }}>
          <Icon name={icon} size={20} />
        </span>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0, flex: 1 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap' }}>
            <h3 style={{ font: 'var(--type-heading-4)' }}>{name}</h3>
            {isNew && <Badge tone="warm" size="sm">{newLabel}</Badge>}
          </div>
          {group && <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{group}</span>}
        </div>
      </header>
      {description && <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)' }}>{description}</p>}
      {screenshots.length > 0 && <Carousel items={screenshots} rtl={rtl} placeholderPath={placeholderPath} />}
      {href && (
        <a href={href} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 'auto', font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>
          {linkLabel}<Icon name="arrow-right" size={15} className="fb-flip" style={{ transition: 'transform var(--duration-fast) var(--ease-standard)' }} />
        </a>
      )}
    </article>
  );
}
