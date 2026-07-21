const inputJson = $input.first().json;
const body = inputJson.body || {};
const query = inputJson.query || {};
const headers = inputJson.headers || {};
const hostMap = {};

function first(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null && String(value).trim() !== '') return String(value).trim();
  }
  return '';
}

function isPlainObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value);
}

function displayValue(value) {
  if (value === undefined || value === null || value === '') return '';
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (Array.isArray(value)) return `[${value.length} item${value.length === 1 ? '' : 's'}]`;
  if (typeof value === 'object') return JSON.stringify(value).slice(0, 120);
  return String(value).replace(/\s+/g, ' ').trim().slice(0, 160);
}

function redactText(value) {
  return String(value || '')
    .replace(/(bearer\s+)[A-Za-z0-9._~+/-]+/gi, '$1***')
    .replace(/((?:secret|token|password|authorization|api[_-]?key)=)[^\s&]+/gi, '$1***');
}

function sanitizeForAudit(value, depth = 0) {
  if (depth > 6) return '[truncated]';
  if (Array.isArray(value)) return value.slice(0, 100).map(item => sanitizeForAudit(item, depth + 1));
  if (!isPlainObject(value)) return typeof value === 'string' ? redactText(value) : value;
  const out = {};
  for (const [key, item] of Object.entries(value)) {
    if (/(?:authorization|token|secret|password|api[_-]?key|credential|chat[_-]?id|phone|mobile|destination|extension)/i.test(key)) {
      out[key] = '[redacted]';
    } else {
      out[key] = sanitizeForAudit(item, depth + 1);
    }
  }
  return out;
}

function pickFields(source, keys) {
  const out = {};
  if (!isPlainObject(source)) return out;
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(source, key)) {
      const value = displayValue(source[key]);
      if (value !== '') out[key] = value;
    }
  }
  return out;
}

function formatFieldList(fields, separator = ', ') {
  return Object.entries(fields).map(([key, value]) => `${key}=${value}`).join(separator);
}

function objectSnapshotKey(eventType, data) {
  const type = first(data.type, eventType.split('.')[0], 'object');
  const id = first(data.id, data.product_id, data.order_id, data.user_id, data.sku, data.code, data.name, data.title);
  return id ? `${type}:${id}` : '';
}

function objectLabel(eventType, data) {
  const kind = first(data.type, eventType.split('.')[0], 'Object').replaceAll('_', ' ');
  const name = first(data.name, data.title, data.woo_name, data.display_name, data.login);
  const sku = first(data.sku, data.patris_product_code, data.product_code, data.code);
  const id = first(data.id, data.product_id, data.order_id, data.user_id);
  const parts = [];
  if (name) parts.push(name);
  if (sku) parts.push(`SKU ${sku}`);
  if (id) parts.push(`ID ${id}`);
  return `${kind.charAt(0).toUpperCase()}${kind.slice(1)}${parts.length ? `: ${parts.join(' / ')}` : ''}`;
}

function summarizeObjectEvent(eventType, data) {
  if (!isPlainObject(data)) return null;

  const trackedKeys = [
    'id', 'product_id', 'type', 'name', 'title', 'sku', 'status',
    'regular_price', 'sale_price', 'price', 'min_price', 'max_price',
    'stock_quantity', 'stock_status', 'manage_stock', 'weight',
    'patris_product_code', 'patris_name', 'patris_foreign_currency',
    'patris_foreign_price', 'patris_total_stock', 'patris_minimum_stock',
    'patris_final_price', 'patris_updated_at', 'import_freight_method_id',
    'price_per_kg_cny', 'enabled', 'old_status', 'new_status',
    'number', 'total', 'currency', 'payment_method',
    'login', 'email', 'display_name',
  ];
  const snapshot = pickFields(data, trackedKeys);
  const label = objectLabel(eventType, data);
  const actionText = eventType.split('.').slice(1).join(' ') || first(data.status, 'updated');
  const lines = [label];
  const changes = Array.isArray(data.changed_fields)
    ? data.changed_fields
        .filter(item => isPlainObject(item) && first(item.field))
        .map(item => ({
          field: first(item.field),
          old: displayValue(item.old),
          new: displayValue(item.new),
        }))
    : [];
  const key = objectSnapshotKey(eventType, data);

  try {
    const store = typeof $getWorkflowStaticData === 'function' ? $getWorkflowStaticData('global') : {};
    store.objectSnapshots = isPlainObject(store.objectSnapshots) ? store.objectSnapshots : {};
    const previous = key && isPlainObject(store.objectSnapshots[key]) ? store.objectSnapshots[key] : null;
    if (previous && !changes.length) {
      for (const [field, value] of Object.entries(snapshot)) {
        if (previous[field] !== undefined && previous[field] !== value) {
          changes.push({ field, old: previous[field], new: value });
        }
      }
    }
    if (key && !eventType.endsWith('.deleted')) {
      store.objectSnapshots[key] = snapshot;
      const keys = Object.keys(store.objectSnapshots).sort();
      while (keys.length > 500) delete store.objectSnapshots[keys.shift()];
    } else if (key && eventType.endsWith('.deleted')) {
      delete store.objectSnapshots[key];
    }
  } catch (error) {
    // Static data is best-effort; the notification still carries current values.
  }

  if (changes.length) {
    lines.push(`Changed: ${changes.slice(0, 8).map(item => `${item.field}: ${item.old} -> ${item.new}`).join('; ')}`);
    if (changes.length > 8) lines.push(`Changed fields truncated: ${changes.length - 8} more.`);
  } else if (Object.keys(snapshot).length) {
    lines.push(`Current: ${formatFieldList(snapshot)}`);
  }

  return {
    title: `${label} ${actionText}`.slice(0, 180),
    summary: lines.join('\n'),
    detailLines: lines,
    object: { label, key },
    changes,
    current_values: snapshot,
  };
}

const eventType = first(body.event_type, body.event, body.type, query.event_type, query.event, 'automation_event');
const status = first(body.status, body.state, body.value, query.status, query.state).toLowerCase();
const data = isPlainObject(body.data) ? body.data : {};
const ip = first(body.ip, body.host, body.address, query.ip, query.host);
const mac = first(body.mac, body['mac-address'], body.mac_address, query.mac);
const hostname = first(body.hostname, body.host_name, body.name, query.hostname, query.name, hostMap[ip]?.name);
const person = first(body.person, hostMap[ip]?.person);
const source = first(body.source, body.origin, body.event ? 'wordpress' : 'routeros');
const router = first(body.router, body.router_name, headers['x-routeros-identity']);
const ssid = first(body.ssid, body.interface, body.radio, query.ssid);
const signal = first(body.signal, body.signal_strength, body['signal-strength']);
const camera = first(body.camera, body.channel, body.device, query.camera);
const brightness = first(body.brightness, body.led_brightness, body.intensity);
const mode = first(body.mode, body.led_mode);
const nowIso = new Date().toISOString();
const localTime = new Date().toLocaleString('fa-IR', { timeZone: 'Asia/Tehran' });

function parseRelayTime(value) {
  const match = String(value || '').match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z/);
  if (!match) return null;
  const [, year, month, day, hour, minute, second] = match;
  return new Date(Date.UTC(year, Number(month) - 1, day, hour, minute, second));
}

const relayId = first(body.relay_id, headers['x-office-automation-relay-id']);
const relayTime = parseRelayTime(relayId);
const ingressAgeSeconds = relayTime ? Math.floor((Date.now() - relayTime.getTime()) / 1000) : null;
const maxRelayAgeSeconds = 15 * 60;
if (ingressAgeSeconds !== null && ingressAgeSeconds > maxRelayAgeSeconds) return [];


let title = 'Office automation event';
let action = status || eventType;
let summary = '';
let tags = 'bell,computer';
let objectSummary = null;

if (eventType.includes('netwatch') || eventType.includes('host')) {
  const isUp = ['up', 'available', 'online', '1', 'true'].includes(status);
  const isDown = ['down', 'unavailable', 'offline', '0', 'false'].includes(status);
  action = isUp ? 'arrived/online' : isDown ? 'left/offline' : action;
  title = `${hostname || ip || 'Host'} ${isUp ? 'online' : isDown ? 'offline' : action}`;
  tags = isUp ? 'green_circle,computer' : 'red_circle,computer';
  summary = `${hostname || 'Host'} ${ip ? `(${ip})` : ''} is ${isUp ? 'online' : isDown ? 'offline' : status || 'updated'}.`;
} else if (eventType.includes('wifi') || eventType.includes('registration')) {
  const joined = ['up', 'connected', 'registered', 'join', 'joined'].includes(status);
  const left = ['down', 'disconnected', 'deregistered', 'leave', 'left'].includes(status);
  action = joined ? 'phone/device joined Wi-Fi' : left ? 'phone/device left Wi-Fi' : action;
  title = `${hostname || mac || 'Wireless device'} ${joined ? 'joined Wi-Fi' : left ? 'left Wi-Fi' : 'Wi-Fi update'}`;
  tags = joined ? 'green_circle,wifi' : left ? 'red_circle,wifi' : 'wifi';
  summary = `${hostname || 'Wireless device'} ${mac ? `(${mac})` : ''} ${joined ? 'joined' : left ? 'left' : 'updated on'} Wi-Fi${ssid ? ` via ${ssid}` : ''}.`;
} else if (eventType.includes('dahua') || eventType.includes('camera') || eventType.includes('motion') || eventType.includes('led')) {
  title = `${camera || 'Camera'} ${eventType.replaceAll('_', ' ')}`;
  tags = eventType.includes('motion') ? 'warning,camera' : 'bulb,camera';
  summary = `${camera || 'Camera'} event: ${eventType}${mode ? `, mode ${mode}` : ''}${brightness ? `, brightness ${brightness}` : ''}.`;
} else if (isPlainObject(data) && Object.keys(data).length) {
  objectSummary = summarizeObjectEvent(eventType, data);
  title = first(body.title, objectSummary?.title, eventType.replaceAll('.', ' '));
  summary = first(body.message, body.text, objectSummary?.summary, `${eventType} ${status}`.trim());
  tags = eventType.includes('product') ? 'shopping_cart,package' : eventType.includes('order') ? 'receipt' : 'bell';
} else {
  title = first(body.title, 'Office automation event');
  summary = first(body.message, body.text, `${eventType} ${status}`.trim());
}

title = redactText(title);
summary = redactText(summary);

const actor = person ? `\nPerson: ${person}` : '';
const objectDetailLines = objectSummary?.detailLines?.length ? objectSummary.detailLines : [];
const details = [
  `<b>${title}</b>`,
  '',
  `<b>Office:</b> Digitalogic`,
  `<b>Time:</b> ${localTime}`,
  `<b>Event:</b> <code>${eventType}</code>`,
  ip ? `<b>IP:</b> <code>${ip}</code>` : '',
  mac ? `<b>MAC:</b> <code>${mac}</code>` : '',
  hostname ? `<b>Device:</b> ${hostname}` : '',
  person ? `<b>Person:</b> ${person}` : '',
  router ? `<b>Router:</b> ${router}` : '',
  ssid ? `<b>Wi-Fi:</b> ${ssid}` : '',
  signal ? `<b>Signal:</b> ${signal}` : '',
  camera ? `<b>Camera:</b> ${camera}` : '',
  ...objectDetailLines.map(line => `<b>Object:</b> ${line}`),
  '',
  `<blockquote>${summary}</blockquote>`,
].filter(Boolean).join('\n');

const eventRecord = {
  timestamp: nowIso,
  local_time: localTime,
  office: 'Digitalogic',
  event_type: eventType,
  status,
  action,
  ip,
  mac,
  hostname,
  person,
  source,
  router,
  ssid,
  signal,
  camera,
  brightness,
  mode,
  relay_id: relayId,
  ingress_age_seconds: ingressAgeSeconds,
  raw: sanitizeForAudit(body),
};

return [{
  json: {
    ...sanitizeForAudit(inputJson),
    eventRecord,
    telegramText: details,
    ntfyTitle: title,
    ntfyTags: tags,
    ntfyText: `${title}\nDigitalogic\n${localTime}\n${summary}${actor}`,
    eventJsonLine: JSON.stringify(eventRecord) + '\n',
    redisMessage: JSON.stringify(eventRecord),
    sipPayload: JSON.stringify({
      title,
      status: status || eventType.split('.').slice(1).join('.'),
      message: summary,
      reason: redactText(first(body.reason, body.cause, body.hint, body.error_description, body.error_message, body.error)),
      severity: first(body.severity, body.level, eventType.includes('failure') || eventType.includes('error') ? 'error' : 'info'),
      event_type: eventType,
      channels: body.channels,
      notify_channels: body.notify_channels,
      object: objectSummary?.object,
      details: objectDetailLines,
      changes: objectSummary?.changes || [],
      current_values: objectSummary?.current_values || {},
    }),
  },
  pairedItem: { item: 0 },
}];
