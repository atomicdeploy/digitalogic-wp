const context = $input.first().json || {};
const customer = Array.isArray(context.customers) ? context.customers[0] : null;
const orders = Array.isArray(context.recent_orders) ? context.recent_orders : [];
const calls = context.call_history && Array.isArray(context.call_history.calls) ? context.call_history.calls : [];
const external = Array.isArray(context.external_candidates) ? context.external_candidates : [];
const identity = customer || external[0] || null;
const displayName = identity && (identity.display_name || identity.name || identity.company)
  ? String(identity.display_name || identity.name || identity.company)
  : '';
const title = displayName ? `Incoming call — ${displayName}` : 'Incoming customer call';
const lines = [
  context.display ? `Caller: ${context.display}` : '',
  identity && identity.company ? `Company: ${identity.company}` : '',
  `Customer matches: ${(context.customers || []).length + external.length}`,
  `Recent WooCommerce orders: ${orders.length}`,
  `Recent Asterisk calls: ${calls.length}`,
  calls[0] ? `Last call: ${calls[0].direction}, ${calls[0].disposition}, ${calls[0].duration_seconds}s` : '',
].filter(Boolean);

return [{
  json: {
    ...context,
    notification_title: title,
    notification_message: lines.join('\n'),
    notification_correlation_id: context._event && context._event.correlation_id
      ? context._event.correlation_id
      : $execution.id,
  },
}];
