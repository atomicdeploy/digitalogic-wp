# Product documents

Digitalogic product pages can show a document card with two customer choices:

1. a verified PDF hosted in the Digitalogic WordPress media library;
2. the exact official HTTPS source from the manufacturer.

The card is rendered from the private product meta key
`_digitalogic_product_documents`. A document is shown only when both links and
all provenance fields pass validation.

## Required workflow

1. Prove the exact product identity from the manufacturer source.
2. Download the official PDF and verify that it opens and matches the product.
3. Record its SHA-256 and byte size.
4. Upload that exact PDF as a WordPress `application/pdf` attachment owned by
   the product.
5. Store the reviewed document record with
   `Digitalogic_Product_Resources::replace_documents()`.
6. Read the meta and attachment back, verify the hosted hash, and check both
   public actions. The write API also rejects a declared SHA-256 or byte size
   that does not match the attached file.

Do not use a marketplace or mirrored PDF as the official source. Do not attach
a family document when the exact model cannot be proven from that document.

## Closed document shape

```php
array(
	array(
		'title'            => 'دیتاشیت رسمی OMRON G8FE',
		'attachment_id'    => 123,
		'source_url'       => 'https://manufacturer.example/path/datasheet.pdf',
		'source_label'     => 'OMRON',
		'sha256'           => 'lowercase-64-character-sha256',
		'bytes'            => 557949,
		'product_identity' => 'G8FE-1AP-L DC12',
		'verified_at'      => '2026-07-25T04:00:00+03:30',
	),
)
```

Unknown fields, non-PDF attachments, non-HTTPS source URLs, missing provenance,
and duplicate hashes or source URLs fail closed.

## Directionality

The Product Experience specifications shortcode emits isolated `<bdi>` values
and is the single insertion point for the document card. This integration
resolves the first strong Persian/Arabic or Latin character and replaces
`dir="auto"` with an explicit `dir="rtl"` or `dir="ltr"`. Its stylesheet also
overrides the legacy rule that forced every specification value to LTR.
