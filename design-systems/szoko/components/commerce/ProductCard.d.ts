import * as React from 'react';

export interface ProductBadge { tone: 'sale' | 'new' | 'gold' | 'soft'; label: string; }

export interface ProductCardProps extends React.HTMLAttributes<HTMLDivElement> {
  /** Product name (serif). */
  name: string;
  /** Origin tag, e.g. "Madagascar". */
  origin?: string;
  /** Cocoa tag, e.g. "70% dark". */
  cocoa?: string;
  /** Current price. */
  price: string | number;
  /** Original price for sale strike-through. */
  was?: string | number;
  /** Image URL. Omit for a warm chocolate placeholder swatch. */
  image?: string;
  /** Corner marketing flag. */
  badge?: ProductBadge;
  /** Rating 0–5. */
  rating?: number;
  /** Review count. */
  count?: number;
  wishlisted?: boolean;
  onWishlist?: (next: boolean) => void;
  onAdd?: () => void;
}

/**
 * Chocolate product tile for shop grids — image with badge & wishlist, origin/
 * cocoa tags, serif name, rating, price and add-to-basket.
 *
 * @startingPoint section="Commerce" subtitle="Chocolate product tile for shop grids" viewport="360x460"
 */
export function ProductCard(props: ProductCardProps): JSX.Element;
