# لینک‌های خصوصی تأمین‌کنندگان

این قابلیت برای نگهداری لینک خرید محصولات دیجیتالاجیک طراحی شده است. لینک‌های
تائوبائو، ۱۶۸۸، تی‌مال، علی‌بابا، علی‌اکسپرس، فروشگاه‌های ایرانی و منابع دیگر
به محصول اصلی ووکامرس متصل می‌شوند.

## مرز دسترسی

- فقط کاربری که هم‌زمان مجوزهای `manage_options` و ویرایش همان محصول را دارد
  می‌تواند لینک‌ها را ببیند یا تغییر دهد.
- اطلاعات در متای محافظت‌شده
  `_digitalogic_private_supplier_links_v1` ذخیره می‌شود.
- متا در REST API وردپرس ثبت نمی‌شود و از پاسخ‌های REST و webhook ووکامرس نیز
  حذف می‌شود.
- هیچ فیلد، اسکریپت یا نشانه‌ای در صفحه عمومی محصول یا داده‌های ساختاریافته
  اضافه نمی‌شود.
- نسخه نخست فقط محصول اصلی را می‌پذیرد؛ لینک تنوع‌ها باید فعلاً با توضیح یا
  کد فروشنده روی محصول اصلی ثبت شود.

## پنل مدیریت

در ویرایش کلاسیک محصول، جعبه «منابع خرید خصوصی» برای مدیران نمایش داده می‌شود.
ستون «منابع خرید» در فهرست محصولات فقط تعداد و نام پلتفرم‌ها را نشان می‌دهد و
نشانی اینترنتی را نمایش نمی‌دهد.

## WP-CLI

تمام دستورها باید با `--user=<administrator>` اجرا شوند. شناسه محصول با یکی از
`--id` یا `--sku` به‌صورت دقیق انتخاب می‌شود.

```bash
wp digitalogic supplier-links list --id=123 --user=administrator
```

برای جلوگیری از ثبت نشانی خصوصی در آرگومان‌های پردازش، دستورهای نوشتن JSON را
فقط از stdin می‌خوانند:

```bash
printf '%s' "$SELLER_LINK_JSON" \
  | wp digitalogic supplier-links add --id=123 --user=administrator

printf '%s' "$SELLER_LINKS_JSON" \
  | wp digitalogic supplier-links replace --sku=MODULE-1 --user=administrator

wp digitalogic supplier-links remove \
  --id=123 \
  --link-id=sl_0123456789abcdef0123 \
  --user=administrator
```

خروجی دستورهای نوشتن فقط شناسه محصول، تعداد لینک‌ها و شناسه‌های داخلی لینک را
برمی‌گرداند و شامل نشانی یا یادداشت خصوصی نیست.

### نمونه شیء JSON

```json
{
  "marketplace": "taobao",
  "site_name": "Taobao",
  "url": "https://item.taobao.com/item.htm?id=123",
  "source_title": "عنوان ثبت‌شده در سابقه خرید",
  "seller": "نام فروشنده",
  "seller_sku": "ABC-123",
  "source": "purchase_history",
  "status": "matched",
  "note": "یادداشت داخلی",
  "last_checked": "2026-07-24"
}
```

مقادیر پشتیبانی‌شده:

- `marketplace`: `taobao`, `1688`, `tmall`, `alibaba`, `aliexpress`,
  `iranian_market`, `other`
- `source`: `purchase_history`, `iranian_market`, `manual`, `other`
- `status`: `candidate`, `matched`, `purchased`, `preferred`, `inactive`
