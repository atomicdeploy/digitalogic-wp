# WordPress deployment adapters

`digitalogic-price-updated-display.php` is the existing production price-update
display adapter, now tracked with the shared application source. Version 1.0.2
reads currency provenance from `Digitalogic_Pricing_Service`; it contains no
pricing calculator or compatibility alias. Install it in `wp-content/mu-plugins`
within the same paused-writer activation window as Digitalogic 2.0.0.

The previous live 1.0.1 file had SHA-256
`c719417751bca5a0b9be2e70a52e870b39bf20d7c731648bf3e636b2ea4e139f`.
Preserve that file with the coordinated rollback material; reverting the plugin
also requires reverting this adapter. Do not install the old-class caller beside
the new core. Files under `ops` are intentionally not in the public plugin ZIP.

This adapter displays price-write metadata and owner rate provenance. It does not
prove that every persistence strategy records a per-product write timestamp;
direct-DB and adapter timestamp parity still needs end-to-end verification.
