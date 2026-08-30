# Currency update jobs

Currency changes run through the existing fenced `Digitalogic_Currency_Admin_Async` worker. Admission is short, returns HTTP `202`, and never performs catalog repricing inside the browser, WebSocket, REST, WP-CLI, or n8n request.

## Safe remote admission

`POST /wp-json/digitalogic/v1/currency` requires both:

- the canonical `expected_state_revision` and an exactly matching quoted `If-Match` header;
- an 8–128 character `request_id` and an exactly matching `Idempotency-Key` header.

The same request ID and same intent always returns the same current or terminal job. Reusing it for another intent fails with `409`. A bounded registry retains immutable terminal results across successor generations, so an unknown transport outcome is recovered by status lookup and is never automatically resubmitted as a new mutation.

## Status and cancellation

- `GET /wp-json/digitalogic/v1/currency/jobs/{job_id}/{generation}` reads an exact worker generation.
- `DELETE /wp-json/digitalogic/v1/currency/jobs/{job_id}/{generation}` cooperatively cancels it.
- `GET /wp-json/digitalogic/v1/currency/requests/{request_id}` recovers an unknown admission response.
- `DELETE /wp-json/digitalogic/v1/currency/requests/{request_id}` cancels by the original request identity.

Queued jobs become terminal immediately. Running jobs enter `cancelling`; the transactional fence observes the request before writes or commit and rolls back. Once the durable effect marker exists, cancellation returns `409` and the exact effect continues only through its idempotent publication finalizer.

All status responses are cache-disabled and include the exact job and request identities, progress, Persian operator message, committed revision, retry state, and whether cancellation remains available.

## WP-CLI and n8n

```text
wp digitalogic currency update --cny=31000 --request-id=n8n-currency-20260830-001
wp digitalogic currency status --request-id=n8n-currency-20260830-001
wp digitalogic currency cancel --request-id=n8n-currency-20260830-001
```

WP-CLI generates a request ID when one is not supplied and prints the accepted job as JSON. n8n should retain its own stable request ID, use the REST admission once, and poll the read-only request route after any timeout or lost response.
