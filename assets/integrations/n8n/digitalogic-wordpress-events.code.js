const { createHmac, timingSafeEqual } = require('crypto');
const { Buffer } = require('buffer');

const first = $input.first();
const json = first.json || {};
const headers = json.headers || {};
const body = json.body || {};
const raw = first.binary?.data?.data
  ? Buffer.from(first.binary.data.data, 'base64').toString('utf8')
  : JSON.stringify(body);
const signature = headers['x-digitalogic-signature'] || headers['X-Digitalogic-Signature'] || '';
const event = String(headers['x-digitalogic-event'] || headers['X-Digitalogic-Event'] || body.event || 'wordpress.event');
const expected = createHmac('sha256', $env.DIGITALOGIC_WP_WEBHOOK_SECRET || '').update(raw).digest('hex');

if (!signature || signature.length !== expected.length || !timingSafeEqual(Buffer.from(signature), Buffer.from(expected))) {
  throw new Error('Invalid Digitalogic WordPress webhook signature');
}

const data = body.data || {};
const site = body.site || {};
const eventId = String(body.event_id || '').trim();
const eventKey = event.toLowerCase();
const orderEvent = ['order.created', 'order.status.changed', 'order.receipt.submitted'].includes(eventKey);

if (eventId) {
  const store = $getWorkflowStaticData('global');
  const now = Date.now();
  store.deliveredEventIds = store.deliveredEventIds && typeof store.deliveredEventIds === 'object'
    ? store.deliveredEventIds
    : {};
  for (const [key, timestamp] of Object.entries(store.deliveredEventIds)) {
    if (now - Number(timestamp) > 7 * 24 * 60 * 60 * 1000) delete store.deliveredEventIds[key];
  }
  if (store.deliveredEventIds[eventId]) {
    return [{ json: { ok: true, duplicate: true, event, eventId, orderId: null, documentAvailable: false } }];
  }
  store.deliveredEventIds[eventId] = now;
  const keys = Object.keys(store.deliveredEventIds);
  while (keys.length > 2000) delete store.deliveredEventIds[keys.shift()];
}

function safe(value, max = 160) {
  return String(value ?? '')
    .normalize('NFKC')
    .replace(/[\u0000-\u001f\u007f\u200b-\u200f\u202a-\u202e\u2060-\u206f\ufeff]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
}

function digits(value) {
  const normalized = String(value ?? '')
    .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
    .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));
  return normalized.replace(/[^\d]/g, '');
}

function amount(value) {
  const valueDigits = digits(value);
  return valueDigits ? Number(valueDigits).toLocaleString('fa-IR') + ' تومان' : 'نامشخص';
}

function effectiveDate(value) {
  const valueDigits = digits(value);
  if (/^\d{6}$/.test(valueDigits)) {
    return '20' + valueDigits.slice(0, 2) + '-' + valueDigits.slice(2, 4) + '-' + valueDigits.slice(4, 6);
  }
  return safe(value || 'نامشخص');
}

function eventTitle(key, payload) {
  const orderNumber = safe(payload.number || payload.id || '', 48);
  const orderSuffix = orderNumber ? ' #' + orderNumber : '';
  const state = safe(payload.new_status || payload.status || '', 48);
  const subject = safe(payload.title || payload.name || payload.sku || '', 72);
  const subjectSuffix = subject ? ': ' + subject : '';

  if (key === 'order.created') return 'سفارش جدید' + orderSuffix;
  if (key === 'order.status.changed') return 'وضعیت سفارش' + orderSuffix + (state ? ': ' + state : '');
  if (key === 'order.receipt.submitted') return 'فیش پرداخت سفارش' + orderSuffix + ' دریافت شد';
  if (key === 'currency.updated') {
    const changed = String(payload.changed_option || '').toLowerCase();
    const currency = changed.includes('dollar') ? 'دلار' : changed.includes('yuan') ? 'یوان' : 'ارز';
    return 'نرخ ' + currency + ' به‌روزرسانی شد';
  }
  if (key === 'product.created') return 'محصول ایجاد شد' + subjectSuffix;
  if (key === 'product.updated') return 'محصول به‌روزرسانی شد' + subjectSuffix;
  if (key === 'product.deleted') return 'محصول حذف شد' + subjectSuffix;

  const tokenLabels = {
    order: 'سفارش', receipt: 'فیش پرداخت', product: 'محصول', shipping: 'ارسال', method: 'روش',
    user: 'کاربر', customer: 'مشتری', price: 'قیمت', stock: 'موجودی', currency: 'ارز',
    created: 'ایجاد شد', updated: 'به‌روزرسانی شد', deleted: 'حذف شد', submitted: 'ثبت شد',
    changed: 'تغییر کرد', completed: 'تکمیل شد', failed: 'ناموفق', restored: 'بازیابی شد',
  };
  const derived = key.split(/[._-]+/).filter(Boolean).map((token) => tokenLabels[token] || safe(token, 32)).join(' ');
  return (derived || 'رویداد وب‌سایت') + subjectSuffix;
}

let downstream;
if (eventKey === 'currency.updated') {
  const changed = String(data.changed_option || '').toLowerCase();
  const currency = changed.includes('dollar') ? 'usd' : changed.includes('yuan') ? 'cny' : 'settings';
  const title = eventTitle(eventKey, data);
  downstream = {
    text: [
      title,
      'دلار (فروش): ' + amount(data.dollar_price),
      'یوان: ' + amount(data.yuan_price),
      'تاریخ مؤثر: ' + effectiveDate(data.update_date),
      'وضعیت: اعمال و ثبت شد',
    ].join('\n'),
    event_type: 'wordpress.currency.' + currency + '.updated',
    severity: 'info',
    status: 'applied',
    title,
    notify_channels: ['telegram'],
  };
} else if (orderEvent) {
  const created = eventKey === 'order.created';
  const receiptSubmitted = eventKey === 'order.receipt.submitted';
  const title = eventTitle(eventKey, data);
  const itemCount = Array.isArray(data.items)
    ? data.items.reduce((sum, item) => sum + Number(item?.quantity || 0), 0)
    : 0;
  const lines = [
    title,
    'شماره سفارش: ' + safe(data.number || data.id || 'نامشخص'),
    'وضعیت: ' + safe(data.new_status || data.status || 'نامشخص'),
  ];
  if (data.total !== undefined && data.total !== '') lines.push('مبلغ: ' + amount(data.total));
  if (data.payment_method) lines.push('پرداخت: ' + safe(data.payment_method));
  if (data.shipping_method) lines.push('تحویل: ' + safe(data.shipping_method));
  if (data.delivery_date) lines.push('تاریخ تحویل: ' + safe(data.delivery_date));
  if (data.delivery_time) lines.push('بازه تحویل: ' + safe(data.delivery_time));
  if (Array.isArray(data.items)) lines.push('تعداد اقلام: ' + Number(itemCount).toLocaleString('fa-IR'));
  downstream = {
    text: lines.join('\n'),
    event_type: 'wordpress.' + eventKey,
    severity: safe(data.severity || 'info', 16),
    status: safe(data.status || (created ? 'placed' : receiptSubmitted ? 'submitted' : 'status_changed'), 64),
    title,
    priority: created || receiptSubmitted ? 'action' : 'archive',
    bypassAggregation: created || receiptSubmitted,
    notify_channels: Array.isArray(data.notify_channels) ? data.notify_channels : ['telegram', 'ntfy'],
    audience: Array.isArray(data.audience) ? data.audience : ['wordpress-operations'],
    event_id: eventId,
    order_id: Number(data.id || 0),
  };
} else {
  const title = eventTitle(eventKey, data);
  const lines = [
    title,
    'رویداد: ' + safe(event),
    'وب‌سایت: ' + safe(site.url || 'digitalogic.ir'),
  ];
  if (data.id || data.number) lines.push('شناسه: ' + safe([data.number, data.id].filter(Boolean).join(' / ')));
  if (data.sku) lines.push('کد کالا: ' + safe(data.sku));
  if (data.status || data.new_status) lines.push('وضعیت: ' + safe([data.old_status, data.new_status || data.status].filter(Boolean).join(' ← ')));
  downstream = {
    text: lines.join('\n'),
    event_type: 'wordpress.' + safe(event, 100).toLowerCase(),
    severity: safe(data.severity || 'info', 16),
    status: safe(data.status || 'observed', 64),
    title,
    notify_channels: Array.isArray(data.notify_channels) ? data.notify_channels : ['telegram'],
    event_id: eventId,
  };
}

if (!orderEvent) {
  await helpers.httpRequest({
    method: 'POST',
    url: 'http://127.0.0.1:5678/webhook/server-event-telegram',
    body: downstream,
    json: true,
    timeout: 5000,
  });
}

return [{
  json: {
    ok: true,
    duplicate: false,
    event,
    eventId: eventId || null,
    orderId: orderEvent ? Number(data.id || 0) : null,
    documentAvailable: eventKey === 'order.created' && data.document_available === true,
    notification: orderEvent ? downstream : null,
  },
}];
