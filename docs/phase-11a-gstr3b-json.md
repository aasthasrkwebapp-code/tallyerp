# Phase 11a: GSTR-3B JSON for the GST portal

## What it does
The GSTR-3B screen has a **JSON for the GST portal** button. It downloads `GSTR3B_<GSTIN>_<MMYYYY>.json` in the layout the portal's GSTR-3B offline utility and upload accept. The figures are those on the screen:

| Table | JSON | From |
|---|---|---|
| 3.1(a) outward taxable | `sup_details.osup_det` | sales less credit notes, GST above 0% |
| 3.1(b) zero-rated | `osup_zero` | exports and SEZ |
| 3.1(c) nil / exempt | `osup_nil_exmp` | 0% lines and documents without tax |
| 3.1(d) inward under reverse charge | `isup_rev` | reverse-charge purchases less debit notes |
| 3.2 inter-state to unregistered / composition | `inter_sup.unreg_details`, `comp_details` | inter-state taxable sales by place of supply, less credit notes |
| 4(A)(3) ITC on reverse charge | `itc_elg.itc_avl` ISRC | tax on reverse-charge purchases |
| 4(A)(5) all other ITC | `itc_avl` OTH | tax on other purchases |
| 4(B)(2) ITC reversed | `itc_rev` OTH | tax on debit notes |
| 4(C) net ITC | `itc_net` | available less reversed |
| 5 inward nil / exempt | `inward_sup.isup_details` GST | 0% purchases (not imports or reverse charge), inter-state vs intra-state by the supplier's state |

These go out as zero, because the books don't record them: imports (IMPG, IMPS), ISD credit, ineligible credit, non-GST supplies, e-commerce supplies (3.1.1), UIN holders, and interest and late fee. Fill them on the portal if they apply.

The file is refused, with the reason shown on the screen, when:
- the company GSTIN is missing or invalid
- the period is not a whole month or a whole quarter

## Permissions
`gst.view` plus `reports.export`, as for the GSTR-1 JSON.

## Tests
`tests/Feature/Gst/GstReportsTest.php`, `test_gstr3b_json_for_the_portal`, checks every table against the test company's September figures. It also covers:
- the download file name and content
- a part-month period refused
- a missing GSTIN refused
- a viewer refused

## Manual checklist
1. Open GSTR-3B for a month and download the JSON.
2. Open it in the GST portal's GSTR-3B offline utility (Import JSON). Each table matches the screen.
3. Enter a period of 10 days. The button returns with a message and no file.

## Done / partial / missing
- Done: GSTR-3B JSON with tables 3.1, 3.2, 4 and 5 from the books.
- Missing:
  - ineligible ITC and rule-based reversals (42/43)
  - interest calculation
  - direct filing through a GSP
