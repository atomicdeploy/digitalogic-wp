const input = $input.first().json || {};
const body = input.body && typeof input.body === 'object' ? input.body : input;

function first(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null && String(value).trim() !== '') {
      return String(value).trim();
    }
  }
  return '';
}

const eventType = first(body.event_type, body.event, body.type).toLowerCase();
const status = first(body.status, body.state).toLowerCase();
const direction = first(body.direction).toLowerCase();
const observedAt = first(body.observed_at, body.timestamp, new Date().toISOString());
const correlationId = first(body.linkedid, body.uniqueid, body.correlation_id, $execution.id);

if (
  (eventType.includes('dhcp') || eventType.includes('wifi') || eventType.includes('registration'))
  && first(body.subject, body.subject_hash)
) {
  const present = ['bound', 'up', 'online', 'joined', 'connected', 'registered', '1', 'true'].includes(status);
  const away = ['unbound', 'down', 'offline', 'left', 'disconnected', 'deregistered', '0', 'false'].includes(status);
  return [{
    json: {
      route: 'presence',
      source: eventType.includes('wifi') || eventType.includes('registration') ? 'routeros_wifi' : 'routeros_dhcp',
      state: present ? (eventType.includes('dhcp') ? 'bound' : 'joined') : (away ? (eventType.includes('dhcp') ? 'unbound' : 'left') : 'unknown'),
      router_subject: first(body.subject, body.subject_hash),
      observed_at: observedAt,
      metadata: { source: 'routeros' },
    },
  }];
}

if (
  eventType === 'sip_trunk_incoming_call'
  && direction === 'incoming_confirmed'
) {
  const caller = first(body.caller_id_number, body.calleridnum);
  if (!caller) return [];
  return [{
    json: {
      route: 'caller',
      event_type: eventType,
      direction,
      phone: caller,
      correlation_id: correlationId,
      observed_at: observedAt,
      external_candidates: Array.isArray(body.external_candidates) ? body.external_candidates.slice(0, 50) : [],
    },
  }];
}

return [{json: {route: 'ignore', event_type: eventType, status}}];
