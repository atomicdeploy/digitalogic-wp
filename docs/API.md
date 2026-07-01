# API Documentation

## REST API Reference

Base URL: `https://yoursite.com/wp-json/digitalogic/v1`

### Authentication

All endpoints require authentication using WooCommerce REST API credentials:

```bash
# Using Basic Auth
curl -u consumer_key:consumer_secret https://yoursite.com/wp-json/digitalogic/v1/products

# Or with Authorization header
curl -H "Authorization: Basic $(echo -n 'consumer_key:consumer_secret' | base64)" \
  https://yoursite.com/wp-json/digitalogic/v1/products
```

---

## Products Endpoints

### List Products

**GET** `/products`

Query Parameters:
- `page` (int): Page number (default: 1)
- `limit` (int): Results per page (default: 50, max: 100)
- `search` (string): Search term
- `sku` (string): Filter by SKU

**Example Request:**
```bash
curl -u key:secret "https://yoursite.com/wp-json/digitalogic/v1/products?page=1&limit=20&search=arduino"
```

**Example Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "Arduino Uno R3",
      "sku": "ARD-UNO-R3",
      "type": "simple",
      "regular_price": "250000",
      "stock_quantity": 50
    }
  ],
  "total": 1250,
  "page": 1,
  "limit": 20
}
```

---

### Get Single Product

**GET** `/products/{id}`

Get a single product by ID.

**Example Request:**
```bash
curl -u key:secret "https://yoursite.com/wp-json/digitalogic/v1/products/123"
```

**Example Response:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "name": "Arduino Uno R3",
    "sku": "113004012",
    "type": "simple",
    "regular_price": "250000",
    "sale_price": "",
    "price": "250000",
    "stock_quantity": 50,
    "stock_status": "instock"
  }
}
```

---

### Get Product by SKU

**GET** `/products/sku/{sku}`

Get a single product by SKU.

**Example Request:**
```bash
curl -u key:secret "https://yoursite.com/wp-json/digitalogic/v1/products/sku/113004012"
```

**Example Response:**
```json
{
  "success": true,
  "data": {
    "id": 10659,
    "name": "ESP-12S Module",
    "sku": "113004012",
    "type": "variation",
    "regular_price": "167000",
    "price": "167000",
    "stock_quantity": 0,
    "stock_status": "instock"
  }
}
```

---

### Get Product Metadata

**GET** `/products/{id}/metadata`

Get detailed product metadata from both wp_postmeta and wp_wc_product_meta_lookup tables.

**Example Request:**
```bash
curl -u key:secret "https://yoursite.com/wp-json/digitalogic/v1/products/10659/metadata"
```

**Example Response:**
```json
{
  "success": true,
  "data": {
    "product_id": 10659,
    "sku": "113004012",
    "name": "ESP-12S Module",
    "type": "variation",
    "lookup_table": {
      "product_id": 10659,
      "sku": "113004012",
      "min_price": "167000.0000",
      "max_price": "167000.0000",
      "stock_quantity": 0,
      "stock_status": "instock",
      "tax_status": "taxable"
    },
    "postmeta": {
      "_sku": "113004012",
      "_regular_price": "167000",
      "_price": "167000",
      "_stock": "0",
      "_stock_status": "instock",
      "_manage_stock": "yes"
    },
    "inconsistencies": []
  }
}
```

---

### Get Product Metadata by SKU

**GET** `/products/sku/{sku}/metadata`

Get detailed product metadata by SKU.

**Example Request:**
```bash
curl -u key:secret "https://yoursite.com/wp-json/digitalogic/v1/products/sku/113004012/metadata"
```

---

### Update Product

**PUT** `/products/{id}`

Update a product by ID.

**Request Body:**
```json
{
  "regular_price": 275000,
  "sale_price": 250000,
  "stock_quantity": 75
}
```

**Example Request:**
```bash
curl -u key:secret -X PUT \
  -H "Content-Type: application/json" \
  -d '{"regular_price": 275000, "stock_quantity": 75}' \
  "https://yoursite.com/wp-json/digitalogic/v1/products/123"
```

---

### Update Product by SKU

**PUT** `/products/sku/{sku}`

Update a product by SKU.

**Request Body:**
```json
{
  "regular_price": 275000,
  "sale_price": 250000,
  "stock_quantity": 75
}
```

**Example Request:**
```bash
curl -u key:secret -X PUT \
  -H "Content-Type: application/json" \
  -d '{"regular_price": 167000, "stock_quantity": 100}' \
  "https://yoursite.com/wp-json/digitalogic/v1/products/sku/113004012"
```

---

### Bulk Update Products

**POST** `/products/batch`

**Request Body:**
```json
{
  "123": {"regular_price": 275000},
  "124": {"stock_quantity": 100}
}
```

---

## Currency Endpoints

### Get Currency Rates

**GET** `/currency`

### Update Currency Rates

**POST** `/currency`

**Request Body:**
```json
{
  "dollar_price": 42500,
  "yuan_price": 6100
}
```

---

## Webhooks

Webhook events:
- `product.created`
- `product.updated`
- `currency.updated`

See full documentation at: [README.md](../README.md)
