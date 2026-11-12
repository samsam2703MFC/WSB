# Webshop UI kit

An interactive, high-fidelity recreation of the Mister Szoko chocolate storefront,
composed entirely from this system's components. Not production code.

**Open:** `index.html`

## Flow
Home (hero → story strip → filterable product grid → editorial atelier panel →
footer) → click any product → **product page** (gallery, buy box with quantity +
size, related items) → **add to basket** fires a toast and updates the header
count → open the **basket drawer** (free-shipping progress, line items with
quantity steppers, subtotal, checkout).

## Files
- `data.js` — fake catalogue (`window.MS_DATA`).
- `Header.jsx` — sticky header, announcement bar, nav, cart count.
- `Home.jsx` — hero, story strip, filters, product grid, atelier panel.
- `ProductPage.jsx` — PDP gallery + buy box + related.
- `BasketDrawer.jsx` — slide-in basket with free-shipping meter.
- `App.jsx` — state, routing, footer, toast.

## Imagery
No product photography was supplied, so all image areas render warm chocolate
placeholder swatches labelled "Product photo" / "Atelier photo". Drop in real
photography (product `image` URLs, hero/atelier backgrounds) to finish.
