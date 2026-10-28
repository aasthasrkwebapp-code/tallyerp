# 11x. Co-products with cost sharing

Some processes make two or more main products from the same inputs. A dairy
makes butter and buttermilk from milk, and a mill makes flour and bran from
wheat. Valuing the second product at a fixed rate, like a by-product, puts
the wrong cost on both. A bill of materials line can now be a **Co-product**
that takes a percentage of the manufacturing cost.

## How it works

- **The line.** On Stock item > Bill of materials, choose **Co-product** as a
  line's type. Enter its quantity per BOM quantity and its **share of the
  cost** as a percentage (above 0 and below 100; "12.5" or "12.5%").
  - The co-products' shares together must stay below 100%, because the main
    item takes the rest.
  - The rate box is for by-products and scrap only, and is not kept for a
    co-product.
- **On manufacture:**
  1. The cost is worked out as before: components used plus other costs,
     less by-products and scrap at their rates.
  2. Each co-product obtained takes its percentage of that cost. Its rate is
     rounded to the paisa, and its value is quantity × rate.
  3. The main item takes what is left.

  The stock journal's value in still equals the components used plus other
  costs.
- **Actual quantities.** A co-product's quantity follows the bill of materials
  or what is entered as obtained.
  - A different quantity changes the co-product's rate, not its share.
  - A co-product not obtained (0) leaves its share to the main item.
- **The manufacture page** shows each co-product with its share and its value
  before other costs, and the totals include a "Less co-products' share" line.
  The values are worked out again when the manufacture is posted, with other
  costs included.
- **The item page** shows the share next to each co-product line. The
  co-product's own page links back under "Co-product, by-product or scrap of".
- **The narration and audit log** show each co-product with its share.

## Example

100 kg milk (cost 4,000) makes 10 kg butter, with 80 kg buttermilk as a
co-product at 30% and 5 kg waste as scrap at 2 a kg. Other costs are 1,000.

| | Amount |
| --- | --- |
| Milk + other costs | 5,000.00 |
| Less waste (5 × 2) | −10.00 |
| Cost to share | 4,990.00 |
| Buttermilk 30% = 1,497.00, i.e. 18.71 a kg × 80 kg | 1,496.80 |
| Butter (the rest) | 3,493.20 |

## Data

Migration `2026_10_28_003600_add_cost_share_to_bom_components` adds
`bom_components.cost_share` (percent, 2 decimals). The kind `co_product` is
added to `BomComponent::KINDS`.

## Tests

`tests/Feature/Inventory/CoProductTest.php`:

- `test_co_products_share_the_cost`: the example above, the journal total,
  the narration, a co-product not obtained, and a changed actual quantity
- `test_cost_share_rules`: a missing, 0 or 100% share, shares adding up to
  100%, and decimal shares
- `test_screens`: the BOM form, the item pages, the manufacture preview, and
  posting from the form

## Manual checklist

1. Make Milk (with opening stock), Butter and Buttermilk in Kg.
2. Butter → Bill of materials: makes 10 Kg; Milk 100 component; Buttermilk 80
   co-product at 30%. The share box appears when Co-product is chosen.
3. Manufacture 10 Kg. The page shows buttermilk's share and the butter's
   remaining cost. Post it.
4. Stock summary shows butter and buttermilk at 70% and 30% of the milk cost.
5. Manufacture again with buttermilk 0. All the cost goes to butter.
6. At 375 px, the BOM form and the manufacture page have no sideways scroll.

## Not done

- Shares are fixed percentages. Sharing by relative sales value is not
  offered.
