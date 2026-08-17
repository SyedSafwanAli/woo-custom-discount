# Woo Custom Discount

Discounts, expiry batches, shop filters and countdowns for
[importedvitamins.com](https://importedvitamins.com) — in one plugin, with no
dependency on a third-party discount plugin.

- **Requires** WordPress 6.0+, WooCommerce 8.0+, PHP 8.0+
- **Current version** 0.2.0 — feature complete, verified on a staging copy of the live store

## What it does

The plugin knows about exactly two things, and every feature comes from them.

**Campaign** — an ordinary discount. Can target the whole store, some
categories, or a list of products. May have an end date. When a campaign ends
the discount stops; the product carries on being sold as normal.

**Expiry batch** — a group of products that share an expiry month and a
discount, for clearing short-dated stock. When the month passes the discount
stops *and* the products are hidden from the shop.

A product belongs to one or the other, never both. A product sitting in an
expiry batch is skipped by campaigns, including a store-wide one.

From those two objects the plugin drives:

| Feature | Detail |
| --- | --- |
| Discount engine | Writes WooCommerce's native `_sale_price`, so sorting, price filters and product feeds all see the real price |
| Shop filters | Six groups — discount %, expiry month, category, price, in-stock, sort by. Multi-select, URL-driven |
| Countdowns | Two kinds (sale end, expiry), rendered client-side so page caching cannot freeze them |
| Importer | Reads existing rules from Discount Rules for WooCommerce once, so nothing is re-entered by hand |

## Safety rules

These are not optional, and the code is arranged around them.

1. **Nothing is on by default.** A fresh activation creates two tables and
   changes no prices, hides no products, and renders nothing on the front end.
2. **The engine refuses to run beside a conflicting discount plugin.** If
   Discount Rules for WooCommerce is active, the engine parks itself and the
   admin says why — otherwise that plugin would apply a second discount on top
   of the sale price this one wrote.
3. **Only our own sale prices are ever touched.** Every price the plugin writes
   carries a marker, and whatever was there before is stored first. A sale price
   set by hand has no marker and is left completely alone.
4. **Deactivating restores original prices.** The deactivation hook clears every
   sale price the plugin owns. Rules and settings survive, so reactivating
   re-applies everything with no manual work.
5. **Deleting keeps your data** unless "delete everything on uninstall" was
   ticked. A mis-click on Delete should not destroy hours of product lists.
6. **A product with no expiry date never hides.** Only products in an expiry
   batch can be hidden, and only after that month has passed.

## Layout

```
woo-custom-discount.php       Plugin header, constants, autoloader, hooks
uninstall.php                 Honours the purge setting
includes/
  class-plugin.php            Bootstrap; decides what runs
  class-install.php           Tables, activation, deactivation
  class-settings.php          Settings, all defaulting to off
  class-rules.php             Campaigns and expiry batches
  class-resolver.php          Which rule applies to a product
  class-price-engine.php      Writes and clears sale prices
  class-expiry.php            Hides stock whose month has passed
  class-buckets.php           Filter bands, and suggesting them from real data
  class-filter-query.php      Turns URL parameters into query conditions
  class-filter-ui.php         Shortcode, widget, automatic placement
  class-filter-widget.php     Sidebar widget
  class-countdown.php         Sale and expiry countdowns
  class-importer.php          Reads the old plugin — the only file that does
  class-admin*.php            Admin screens
assets/                       Front-end and admin CSS/JS
```

## The resolution order

One place decides every price, and nothing else second-guesses it:

1. In an expiry batch? Batch percent. Stop.
2. Own campaign? That percent.
3. Category campaign? That percent.
4. Store-wide campaign? That percent.
5. Otherwise no discount.

Discounts never stack — a product on 60% does not also collect the store-wide
10%. Within one level, the larger discount wins. An exclusion list beats the
scope, so a store-wide campaign can be told to skip individual products.

## Verified against the live catalogue

Checked on a staging copy restored from the live backup, 122 products:

| Check | Result |
| --- | --- |
| Discount percent vs the old plugin, every product | 0 mismatches |
| Prices written | 118 priced, 4 correctly skipped |
| Hand-set sale price (product 4015) | untouched through every cycle |
| Variable products | skipped, as intended |
| Sale end dates | last day of the batch month, 23:59:59 |
| Filter totals | 60%: 12, 50%: 20, both: 32, Aug expiry: 5 |
| Category + discount | 10 → 7, so groups combine with AND |
| Deactivate → reactivate | prices cleared then restored, repeatably |
| Conflict guard | engine refused to run while the old plugin was active |

Only price differences are from rounding down, at most 1 unit, always in the
customer's favour. The preview screen lists each one before anything is applied.

## Roadmap

- [x] Foundation — tables, settings, safe activate/deactivate, status screen
- [x] Resolver and price engine
- [x] Importer — rules, plus expiry batches seeded from the old category names
- [x] Preview — every current price beside its proposed one
- [x] Shop filters — discount, expiry, category, price, stock, sort
- [x] Countdowns — sale and expiry, rendered client-side
- [ ] Live rollout, one switch at a time

## Licence

GPL-2.0-or-later
