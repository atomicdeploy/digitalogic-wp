<?php
/** Review a native cart intent before changing this browser's WooCommerce cart. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$mobile_config = array(
	'preview' => home_url( '/app-api/checkout/preview' ),
	'redeem' => home_url( '/app-api/checkout/redeem' ),
	'nonce' => wp_create_nonce( 'digitalogic_mobile_checkout' ),
);
?>
<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>ادامه خرید در دیجیتالاجیک</title>
<style>
:root{color-scheme:light dark;--bg:#f5f6fa;--surface:#fff;--text:#202837;--muted:#687587;--line:#e6e9ef;--accent:#208a99}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Tahoma,sans-serif;line-height:1.9}main{max-width:600px;margin:36px auto;padding:24px}header{display:flex;align-items:center;gap:14px;margin-bottom:24px}.logo{font-weight:800;color:var(--accent);font-size:25px;letter-spacing:-1px}h1{font-size:1.55rem;line-height:1.6;margin:0}p{color:var(--muted)}.card{background:var(--surface);border:1px solid var(--line);border-radius:24px;padding:24px;box-shadow:0 14px 50px #0f32450a}ul{list-style:none;padding:0;margin:12px 0 24px}li{display:flex;gap:14px;align-items:center;padding:16px 0;border-bottom:1px solid var(--line)}li img{width:60px;height:60px;object-fit:contain;background:#fff;border-radius:12px}.item{flex:1;min-width:0}.item strong{display:block;overflow-wrap:anywhere}.item small{color:var(--muted)}button{font:inherit;font-weight:bold;width:100%;border:0;border-radius:14px;padding:14px 18px;background:var(--accent);color:#fff;cursor:pointer}button:disabled{opacity:.5;cursor:wait}button:focus-visible,a:focus-visible{outline:3px solid #ad66e8;outline-offset:4px}.notice{padding:12px 16px;border-radius:14px;background:#e8f5f5;color:#28616b;font-size:.85rem}.error{color:#c63349}a{color:var(--accent)}footer{margin-top:22px;text-align:center;color:var(--muted);font-size:.85rem}@media(prefers-color-scheme:dark){:root{--bg:#0e171d;--surface:#17232b;--text:#e8f0f3;--muted:#a0b4c0;--line:#2a3b46;--accent:#329dab}.notice{background:#203a42;color:#b4e4e8}}@media(max-width:420px){main{margin:12px auto;padding:16px}.card{padding:20px}}@media(prefers-reduced-motion:no-preference){.card{animation:enter .25s ease-out}@keyframes enter{from{opacity:0;transform:translateY(8px)}}}
</style></head><body><main><header><a class="logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">Digitalogic</a><span>همراه پروژه‌های شما</span></header><section class="card"><h1>سبد اپ را مرور کنید</h1><p>یک قدم تا ادامه خرید در فروشگاه.</p><div id="status" role="status" aria-live="polite">در حال بررسی موجودی…</div><ul id="items"></ul><p class="notice" id="notice" hidden>با ادامه، سبد فعلی این مرورگر با کالاهای زیر جایگزین می‌شود. قیمت، ارسال و موجودی در تسویه‌حساب دوباره بررسی می‌شوند. هنوز سفارشی ثبت یا پرداختی انجام نمی‌شود.</p><button id="continue" disabled hidden>تأیید سبد و ادامه خرید</button></section><footer>برای خرید وارد حساب خود شوید؛ ورود و پرداخت در وب‌سایت امن دیجیتالاجیک انجام می‌شود.</footer></main>
<script>
(() => {
  'use strict';
  const config = <?php echo wp_json_encode( $mobile_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
  const token = location.hash.slice(1);
  history.replaceState(null, '', location.pathname);
  const status = document.getElementById('status');
  const list = document.getElementById('items');
  const button = document.getElementById('continue');
  const showError = message => {status.textContent = message; status.className = 'error'; button.disabled = true;};
  const send = async url => {
    const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce}, body:JSON.stringify({token})});
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'ارتباط برقرار نشد؛ از اپ دوباره ادامه دهید.');
    return data;
  };
  if (!token || token.length > 8000) { showError('برای ادامه خرید، این صفحه را از سبد خرید اپ باز کنید.'); return; }
  send(config.preview).then(data => {
    for (const item of data.items) {
      const row = document.createElement('li');
      if (item.image) { const image = document.createElement('img'); image.src = item.image; image.alt = ''; row.append(image); }
      const copy = document.createElement('div'); copy.className = 'item';
      const title = document.createElement('strong'); title.textContent = item.name; copy.append(title);
      const code = document.createElement('small'); code.textContent = 'کد کالا: ' + item.sku; copy.append(code); row.append(copy);
      const quantity = document.createElement('span'); quantity.textContent = new Intl.NumberFormat('fa').format(item.quantity) + ' عدد'; row.append(quantity); list.append(row);
    }
    status.textContent = 'کالاها آماده بررسی در تسویه‌حساب هستند.';
    document.getElementById('notice').hidden = false; button.hidden = false; button.disabled = false;
  }).catch(error => showError(error.message));
  button.addEventListener('click', async () => {
    button.disabled = true; status.textContent = 'در حال انتقال سبد خرید…';
    try { const data = await send(config.redeem); const url = new URL(data.redirect); if(url.origin !== location.origin) throw new Error('نشانی ادامه خرید معتبر نیست.'); location.replace(url.href); }
    catch(error) { showError(error.message); }
  });
})();
</script></body></html>
