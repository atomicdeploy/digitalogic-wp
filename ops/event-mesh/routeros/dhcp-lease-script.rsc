# Render this secret-free template outside the repository.
# The webhook path and subject pepper are private routing configuration and must not be committed.
:local endpoint "__OFFICE_AUTOMATION_WEBHOOK_URL__"
:local subjectPepper "__ROUTEROS_SUBJECT_PEPPER__"
:local state "unbound"
:if ($leaseBound = "1") do={ :set state "bound" }
:local subject [:convert transform=sha512 to=hex ($subjectPepper . $leaseActMAC)]
:local payload ("{\"event_type\":\"routeros_dhcp_lease\",\"status\":\"" . $state . "\",\"subject\":\"" . $subject . "\",\"source\":\"routeros\"}")
/tool fetch url=$endpoint http-method=post http-header-field="Content-Type: application/json" http-data=$payload output=none keep-result=no
