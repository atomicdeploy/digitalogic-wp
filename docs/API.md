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

### Update Product

**PUT** `/products/{id}`

**Request Body:**
```json
{
  "regular_price": 275000,
  "sale_price": 250000,
  "stock_quantity": 75
}
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

**Example Response:**
```json
{
  "success": true,
  "data": {
    "dollar_price": 42500,
    "yuan_price": 6100,
    "update_date": "241213",
    "woocommerce_currency": "IRR",
    "woocommerce_symbol": "﷼",
    "currency_status": {
      "woocommerce_currency": "IRR",
      "woocommerce_symbol": "﷼",
      "dollar_rate": 42500,
      "yuan_rate": 6100,
      "is_usd": false,
      "is_cny": false,
      "needs_exchange_rates": true
    }
  }
}
```

### Update Currency Rates

**POST** `/currency`

**Request Body:**
```json
{
  "dollar_price": 42500,
  "yuan_price": 6100
}
```

**Note:** The plugin automatically syncs with WordPress options and ACF fields. WooCommerce currency setting is monitored separately and changes are logged.

---

## Webhooks

Webhook events:
- `product.created`
- `product.updated`
- `currency.updated`

See full documentation at: [README.md](../README.md)
