const items = $input.all();

return items.filter((item) => {
  const input = item.json || {};
  const body = input.body && typeof input.body === 'object' ? input.body : input;
  const eventType = String(body.event_type || body.event || body.type || '').trim().toLowerCase();
  return ![
    'routeros_dhcp_lease',
    'routeros_wifi_registration',
    'digitalogic_presence_evidence',
  ].includes(eventType);
});
