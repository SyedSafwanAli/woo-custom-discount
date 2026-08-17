# Woo Custom Discount

Discounts, expiry batches, shop filters and countdowns for
[importedvitamins.com](https://importedvitamins.com) — in one plugin, with no
dependency on a third-party discount plugin.

- **Requires** WordPress 6.0+, WooCommerce 8.0+, PHP 8.0+
- **Current version** 0.1.0 — foundation only, nothing applied to the store yet

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
woo-custom-discount.php     Plugin header, constants, autoloader, hooks
uninstall.php               Honours the purge setting
includes/
  class-plugin.php          Bootstrap; decides what runs
  class-install.php         Tables, activation, deactivation
  class-settings.php        Settings, all defaulting to off
  class-rules.php           Campaigns and expiry batches
  class-price-engine.php    Writes and clears sale prices
  class-admin.php           Admin screens
```

## Roadmap

- [x] Foundation — tables, settings, safe activate/deactivate, status screen
- [ ] Resolver and price engine — decide and write each product's price
- [ ] Importer — pull existing rules and seed expiry batches
- [ ] Preview — compare every current price against the proposed one
- [ ] Shop filters
- [ ] Countdowns

## Licence

GPL-2.0-or-later
