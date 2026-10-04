# Phoca Cart POS Discount

A Joomla system plugin that lets selected staff give a **manual discount at the Phoca Cart POS**: type an amount or a percentage, pick which with a button, apply.

- Works with **Phoca Cart 6** on **Joomla 5 and 6** (tested on Phoca Cart 6.1.8, Phoca POS template 6.0.0, Joomla 6.1).
- No core files, no template overrides, no database changes. Everything goes through Phoca Cart's own coupon engine, so the discount is spread across the basket and stored in the order, order totals, tax recapitulation and receipt exactly like any other coupon.
- **You choose who can discount**, by Joomla user group and/or individual user, and optionally set limits (none by default).

## What staff see

On the POS **Apply Benefits** screen, above the normal coupon field:

```
Discount
[ % | £ ]  [ 10      ]  [ Apply Discount ]
```

The `%` / `£` buttons choose the mode, the field takes the value, **Apply Discount** applies it. The cart then shows the discount, for example:

```
Toti                        £39.00     Staff discount  £35.10
Bel                         £36.00     Staff discount  £32.40
Subtotal                    £75.00
Staff discount (10 %)      - £7.50
Total                       £67.50
```

If a discount is already on the ticket the screen offers **Remove Discount**. A new discount replaces the previous one (Phoca Cart allows one coupon per cart).

## Install

1. Extensions, Install: upload `plg_system_phocacartposdiscount_v1.0.0.zip`.
2. Extensions, Plugins: enable **System - Phoca Cart POS Discount**.
3. Open it and, on the first tab, choose the **User groups** and/or **Individual users** allowed to discount. Until you do, nobody can (safe default). Child groups inherit, as everywhere in Joomla.
4. Check these Phoca Cart settings (Components, Phoca Cart, Options):
   - **POS tab, "Display Apply Benefits Button (POS)" = Yes** (off by default; without it there is no Apply Benefits screen).
   - **Coupons** enabled (default). If coupons are off the plugin tells staff why instead of failing silently.

## Settings

| Tab | Setting | Notes |
|---|---|---|
| Access | User groups allowed to discount | Joomla ACL groups, children included |
| Access | Individual users allowed to discount | Added to the groups, for one-off people |
| Limits | Default maximum percentage / flat amount | `0` = no limit. 100 % and the ticket total are always the ceiling |
| Limits | Limits per group | Optional rows (group, max %, max amount). A user in several listed groups gets the most generous row; users matching no row get the defaults |
| Options | Allow percentage / flat discounts | Turn a mode off if the shop only wants one |
| Options | Discount name | Shown on the POS cart, receipt and order (default "Staff discount") |
| Options | Require a reason / Reasons | A free-text field, or a dropdown if you list reasons (one per line). Stored with the discount |
| Options | Unused discount lifetime | Hours before an unused discount expires and is cleaned up (default 24) |

## What is stored

Each discount creates one single-use, POS-only coupon (`MD-XXXXXXXX`, access Public, type POS, quantity 1) in Phoca Cart's coupon table. Its `params` hold an audit record: who gave it (user id and name), mode, value, reason, ticket scope and time. The order stores its own copy (`#__phocacart_order_coupons`), so reporting does not depend on the coupon row.

- A coupon that was **used on an order is never deleted**.
- Unused ones are removed automatically: superseded ones on the same ticket, and expired ones.
- Coupons not created by this plugin are never touched.

## Security

- The control is only sent to permitted users; the **server re-checks permission and limits on every request** (CSRF token required), so hiding the button is not the only protection.
- Amounts are validated server-side against the live ticket total, never trusted from the browser.
- The coupon cannot be used online (type POS only).

## Limitations

- One coupon per cart, so a manual discount and a real coupon code cannot be combined.
- Percentage discounts show the percentage on the cart; flat discounts show the amount.
- The undiscounted line price stays in the order's product rows; the discounted price per line is in Phoca Cart's per-product coupon discount rows (as for any coupon). Reports that read only the product rows show gross prices.
- The Apply Benefits screen is built by Phoca POS from AJAX fragments; the plugin attaches to it with a MutationObserver and Phoca's own form markup (`#phcoupon`, `.editMainContent`). If a future Phoca release renames those, the control quietly does not appear and nothing else is affected.
- Language: English (en-GB). Add `language/xx-XX/` files to translate.

## Uninstall

Uninstalling removes the plugin. Existing orders keep their coupon copy; the `MD-` coupon rows remain (used ones are your audit trail, delete unused ones if you like).

## Author and licence

Author: David Leeman. GNU General Public License version 2 or later.

## Documentation

- `docs/POS-Manual-Discount-Guide.pdf`: illustrated quick guide (7 pages), setup for the shop owner and a step-by-step till walkthrough with example products.
- `FLOW.md`: design and flow notes for developers.
