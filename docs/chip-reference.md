# Manufacturer chip specifications

Use a chip reference for facts about the silicon, separate from the revision-specific board and product assignment tables. For example, the ENC28J60 VDD range applies to the chip supply pins; it does not identify the voltage at the power header of an ENC28J60 module. Chip references have no product ID, SKU, PCB revision, or pin mapping. Product guides still belong on their WooCommerce product pages.

A reviewed manifest stores a part, the public manufacturer document, and one row per combination of parameter, qualifier and condition. Keep distinct modes (such as Ethernet transmit, active without transmit, and sleep) in different condition keys. Leave undocumented limits absent. The source locator identifies the exact table or section supporting the value.

## Provision and check

On a WordPress test site first, run from the WordPress root with the plugin active and an existing administrator:

```sh
wp --user=<administrator-id> eval-file wp-content/plugins/digitalogic-wp/scripts/provision-chip-reference.php microchip-enc28j60
```

This creates only the three chip reference tables if needed and atomically replaces the selected chip's manufacturer records. Repeat the command to verify that the chip still has ten rows and no duplicates. Back up the three chip tables before replacing existing production records.

Read `/wp-json/digitalogic/v1/hardware/chips/microchip-enc28j60`. It returns `scope: chip` with ten specifications and a source for each one. REST magnitudes use exact decimal strings; the database stores them as `DECIMAL(16,6)` so range queries are numeric. Read `/wp-json/digitalogic/v1/hardware/product?sku=113011001` separately to verify provisioning did not create a board assignment.

To check that the transmit maximum is queryable independently of the typical and sleep values, run the following read-only query (replace `wp_` with the site's actual prefix). Expect one ENC28J60 row, at 180 mA, with document DS39662E and locator §16.1, IDD.

```sql
SELECT c.part_number, p.magnitude, p.unit, p.conditions, s.document_key, p.source_locator
FROM wp_digitalogic_hw_chip_specifications AS p
JOIN wp_digitalogic_hw_chip_parts AS c ON c.id = p.chip_id
JOIN wp_digitalogic_hw_chip_sources AS s ON s.id = p.source_id AND s.chip_id = c.id
WHERE c.slug = 'microchip-enc28j60'
  AND p.parameter = 'supply_current'
  AND p.qualifier = 'max'
  AND p.unit = 'mA'
  AND p.condition_key = 'ethernet_tx'
  AND p.magnitude BETWEEN 170 AND 190;
```
