# 11e By-products and scrap in manufacturing

## What it does

- A bill of materials line can be a **Component** (used up), a **By-product** or **Scrap**
  (comes out with the finished item). By-products and scrap have a quantity per BOM quantity
  and an optional **rate** per unit; when the rate is blank the item's cost on the
  manufacture date is used.
- **Manufacture** works out the by-products and scrap in proportion (rounded to each unit) and
  lets the actual quantity obtained be entered instead (0 for none). The stock journal brings
  them into the finished-goods godown at their rate, and their value is taken off the cost of
  the finished item:
  finished item value = components' cost + other costs − by-products and scrap.
  The journal's value in therefore equals the components used plus other costs.
- By-products and scrap worth more than the cost are refused. A bill needs at least one
  component. Items tracked by batch or serial are refused as before.
- The item page marks by-product and scrap lines and shows "By-product or scrap of" on those
  items; "Used in" lists only real components. The BOM change is audited with the line types.

## Files

- Migration `2026_10_28_002400_add_by_products_to_bom_components.php` (kind, rate).
- `BomComponent::KINDS`, `BillOfMaterials::outputs()`, changes to `save()` and `manufacture()`.
- Views `inventory/items/bom`, `manufacture` and `show`.
- Tests: `tests/Feature/Inventory/BomByProductsTest.php` (3).

## Manual checklist

1. Make items Sugar Cane (with opening stock), Sugar, Molasses and Bagasse in Kg.
2. Sugar → Bill of materials: makes 100 Kg; Sugar Cane 1000 component; Molasses 40 by-product
   at 5; Bagasse 300 scrap, rate blank.
3. Manufacture 250 Kg: the form shows 100 Kg molasses and 750 Kg bagasse with values and the
   reduced cost of sugar. Post it.
4. Stock summary: sugar valued at the cane cost less 1,250; molasses and bagasse in stock.
5. Manufacture again with molasses 0: only sugar and bagasse come in.

## Done / partial / missing

- Done: by-products and scrap with rates, actual quantities, cost reduction, screens, tests.
- Missing: by-products tracked by batch or serial. Co-products sharing the cost by percentage were added in 11x (`docs/phase-11x-co-products.md`).
