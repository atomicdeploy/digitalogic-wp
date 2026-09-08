# Shared catalog creation

The product-sync receiver and Catalog_Materializer are the only product creation/write path. Catalog_Backfill now supplies creation policy and generic REST presentation; it does not scan applied events, create products, or maintain a second rollback engine.

`wp digitalogic product-sync backfill-policy --user=<administrator>` displays the effective policy. Configure enabled, source-id, dataset, status (draft/publish), allow-non-positive and limit through that command. Creation controls apply only when an exact source product is missing; existing product pricing and editorial publication/visibility remain intact. Initial publication choice is consumed on the first canonical commit.

Breaking change: separate `backfill` and `backfill-rollback` commands are removed. Use the existing owner path:

```
wp digitalogic product-sync reconcile --source-id=patris-office --dataset=kala.db --materialize-current --limit=25 --user=<administrator>
```

This operates on committed source data; use the Patris fresh/bulk interface to read new source data first. No permanent migration aliases are maintained.

For sites with the former MU implementation, stage the shared class before replacing it with `scripts/mu-plugins/digitalogic-patris-catalog-backfill.php` in wp-content/mu-plugins. The shim contains no business logic. Install the corresponding main/materializer/feed/receiver files coherently, then restart persistent WordPress workers and verify readiness. Never load both class implementations.

Production evidence: duplicate applied listener removed; bulk accepted in7689ms, following fresh1256ms;901positive DBprices matched, all1163existing publication records/visibility unchanged. No missing products were created in that live run, so actual live creation and policy switching remain separate acceptance work. Local5tests143assertions cover draft creation and preservation of later editorial state. Subsecond target remains unmet.
