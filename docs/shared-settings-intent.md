# Shared settings field intent

The coordinator now provides resolve_settings_intent(values, admitted_settings, submitted_at). It merges only the seven editable input fields onto the admitted owner document, freezes missing independent currency dates using the existing owner timezone policy, and retains freight metadata and rounding mode. Existing apply_internal_settings remains the sole canonical validator and mutation path; the resolver does not calculate or write prices.

Production-first checkpoint: installed coordinator SHA256 81c13080f370cbc4d0256a09778fe8ee877c0966f4a76c3d5c736cd771c868b1. A real WordPress probe passed 12 assertions covering mixed CNY/profit, default date, explicit override, preserved USD date and freight metadata, rejected unowned metadata, and exact unchanged settings readback. Current CNY remained34500; no data mutations occurred. Apache, PHP-FPM and WebSocket services were active after worker restart.

Incomplete: async settings admission, durable explicit-field/resolved-settings storage, worker invocation and Go mixed-job routing still need wiring. Do not claim mixed writeback is unified yet. Do not split a mixed request into multiple currency/profit operations or pass unedited workbook values as explicit overrides.
