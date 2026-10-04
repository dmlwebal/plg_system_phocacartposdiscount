# POS manual discount: design and flow notes

How `plg_system_phocacartposdiscount` was designed, which Phoca Cart extension points it uses, what was missing, and what would make this a native feature. Verified on Phoca Cart 6.1.8, Phoca POS template 6.0.0, Joomla 6.1, 1 October 2026. Paths are relative to the Joomla root.

## 1. The requirement

At the POS, an authorised member of staff keys either a flat amount or a percentage, chosen with buttons. It is applied to the ticket total and spread across the basket lines (POS only), and it must carry through the whole transaction and its history (order, totals, tax, receipt). The shop admin chooses who may do this, and optionally limits.

## 2. What Phoca Cart already does

The coupon engine already does the hard part. A coupon of type POS with `calculation_type` 0 (fixed) or 1 (percentage) is applied proportionally across lines:

| Step | Where |
|---|---|
| POS shows the Apply Benefits form (`#phcoupon`, `task=pos.savepayment`) when option `pos_display_apply_benefits` = Yes | `components/com_phocacart/views/pos/tmpl/default_main_content_applybenefits.php` |
| `.editMainContent` click serialises the closest form and posts it (JSON), then refreshes categories, main panel, input box and cart | `media/com_phocacart/js/phoca/phocapos.js` (`phAjaxEditPos`, handler near line 403) |
| Coupon code looked up with type filter `[0, 2]`, basic validity check | `components/com_phocacart/controllers/pos.json.php` (`savepayment`) |
| Coupon id written to the cart row | `components/com_phocacart/models/pos.php` `savePaymentAndCouponAndReward()` |
| Fixed and percentage distribution across lines, rounding | `libraries/phocacart/cart/calculation.php` `calculateCartCoupons` (about line 1000), `recalculateCartCoupons`, `roundFixedAmountCoupon` |
| Coupon validity rules (dates, `available_quantity`, usage count) | `libraries/phocacart/coupon/coupon.php` `getCoupon`, `checkCoupon` |
| Order save copies coupon, totals, per-line discounts, tax recapitulation | `libraries/phocacart/order/order.php` into `#__phocacart_order_coupons`, `order_total`, `order_product_discounts`, `order_tax_recapitulation` |

What was missing is only the front: the till accepts a coupon **code**, not a keyed value, and nothing limits who may discount.

## 3. Design decisions

1. **Reuse the coupon engine, do not recalculate.** The plugin never touches totals. It creates a one-off coupon and lets Phoca apply it. Everything downstream (tax, rounding, history, receipt) is then proven core behaviour.
2. **System plugin, no override files.** The POS panels are AJAX fragments rendered by the component with no plugin events, and a template override would tie the feature to one template. A system plugin is installable on its own, keeps working across Phoca updates, and uses Joomla mechanisms only: `onBeforeCompileHead`, WebAssetManager, script options, `com_ajax`.
3. **Attach to Phoca's own form.** The script inserts its block above the coupon row of the Apply Benefits form, asks the server for a code, writes it into `#phcoupon` and clicks Phoca's own Apply button. Apply, messages and panel refresh are Phoca's. If markup changes the control simply does not appear.
4. **Server is authoritative.** The browser only sends mode, value, reason. The server checks CSRF token, permission and limits, then validates against the live ticket total.
5. **Permission = plugin parameters** (user groups with Joomla inheritance, plus individual users, plus optional default and per-group limits). Safe default: nobody.
6. **Audit by design.** The coupon `params` record who, mode, value, reason, ticket scope, time; the order keeps its own copy. Used coupons are never deleted; unused superseded and expired ones are purged on each request.

## 4. Request flow

```
Staff opens POS -> Apply Benefits
  1. onBeforeCompileHead (site, com_phocacart, view=pos):
       permitted? -> load asset plg_system_phocacartposdiscount.pos + script options
  2. pos-discount.js MutationObserver sees #phcoupon -> builds [ % | £ ] [value] [Apply Discount]
  3. Click -> POST index.php?option=com_ajax&plugin=phocacartposdiscount&group=system&format=json
       fields: <csrf token>=1, mode, value, reason, ticketid, unitid, sectionid
  4. onAjaxPhocacartposdiscount:
       a. Session::checkToken('post')
       b. getPermissions(user)              -> groups/users, limits (0 = none)
       c. mode/value sanity, reason rule
       d. readTicket(): option=com_phocacart + controller=pos for these calls only,
            PhocacartUser::defineUser(..., forcePos=1), PhocacartTicket::existsTicket,
            PhocacartCartRendercheckout [0,2] -> quantity, subtotal   (context restored)
       e. limits, subtotal ceiling, 100 % ceiling, enable_coupons check
       f. purge(): delete unused MD- coupons (same ticket scope, or expired)
       g. TablePhocacartCoupon: type=POS(2), calc 0|1, discount, qty 1, valid +N h, params audit
       h. return {ok, code}
  5. Script: #phcoupon.value = code; nativeApply.click()
  6. Phoca: pos.savepayment -> coupon applied -> cart refreshes with discount rows
  7. Payment/Confirm as normal -> order saved with coupon copy, totals, tax, receipt
```

## 5. Gotchas found (worth knowing for any POS plugin)

- **POS detection needs the right request.** `PhocacartPos::isPos()` and `PhocacartUtils::isController()` require `option=com_phocacart` and `controller=pos`. A `com_ajax` request has option `com_ajax`, so the cart classes saw no POS ticket and returned an empty basket. The plugin switches those two inputs around Phoca calls and restores them (`finally`).
- **Ticket context comes from request inputs** `ticketid`, `unitid`, `sectionid` (`PhocacartTicket::getTicket()`, defaulting ticket 1), so any custom endpoint must pass them.
- **Coupon dates are mandatory.** `valid_from` and `valid_to` are NOT NULL without defaults and `checkCoupon` rejects a coupon with empty dates.
- **Joomla asset URIs omit the folder.** In `joomla.asset.json` the URI is `plg_x/file.js`, not `plg_x/js/file.js`; Joomla inserts `/js/` or `/css/`. A wrong URI is silently dropped from the page.
- `pos_display_apply_benefits` is off by default and `enable_coupons` can be 0; either makes coupon entry silently do nothing.
- The cart holds **one** coupon, so a manual discount replaces any coupon already applied.

## 6. Test evidence (Phoca 6.1.8, UK shop, GBP)

| Test | Result |
|---|---|
| Fixed £15 on lines £87 / 2 x £39 / £36 (POS-only coupon) | Lines £80.51 / £72.18 / £33.31, total £186.00; stored in `orders.coupon_id`, `order_coupons`, `order_total`, `order_product_discounts`, receipt |
| 10 % on £39 + £36, products at 20 % VAT | Lines £35.10 / £32.40, discount net -£6.25 / gross -£7.50, VAT £11.25, total £67.50; per-line tax £5.85 / £5.40; tax recapitulation net £56.25 + VAT £11.25 = £67.50 |
| Flat £5, percentage 8 / 10 / 20 % | Totals £70.00 / £69.00 / £67.50 / £60.00, correct |
| Remove Discount | Total returns to £75.00 |
| User outside allowed groups | No script, no options, endpoint refuses even with a valid token; no token also refused |
| Individual user (not in an allowed group) | Control appears, works |
| Caps (10 % / £3 default) | 15 % and £5 refused with messages; 8 % and £2 accepted; £999 refused (over total) |
| Per-group row (20 %) over default (5 %) | 20 % accepted, 25 % refused |
| Required reason (dropdown) | Refused without, accepted with; stored in coupon description and params |
| Housekeeping | Superseded unused coupons deleted automatically; used coupon retained with coupon-count row |

## 7. What would make this native (suggestions for Phoca Cart)

The plugin works without any core change, but three small additions would remove its workarounds:

1. **POS view events** (for example `onPCVonPosApplyBenefitsAfterCoupon`, in the same family as the existing `Event\View\*` events) so a plugin can add controls to the Apply Benefits panel server-side instead of through a DOM observer.
2. **A cart-total calculation event** next to `Event\Calculation\CalculationPrice`, so a discount extension can contribute to totals without being expressed as a coupon.
3. **`PhocacartCoupon::createOneOff()`** (or a model method) to create a single-use coupon without going through the table directly, plus a coupon `Type` "Manual (POS)" that lists separately in the admin.
4. Optionally a built-in POS option "Allow manual discount" with the group/limit settings above, making the plugin unnecessary.

## 8. Known edges and next steps

- English only; strings are all in `language/en-GB`.
- Author: David Leeman (namespace `DavidLeeman\Plugin\System\PhocaCartPosDiscount`). Change both before publishing under a company name.
- A "set final total" mode (type the price wanted, plugin computes the flat discount) is straightforward from the ticket subtotal already read server-side; not built yet.
- A reporting view of manual discounts (by staff member, by reason) can read the coupon `params`; not built yet.
- A scheduled task could replace the purge-on-request approach for very busy shops.
