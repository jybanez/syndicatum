<?php

require_once dirname(__DIR__) . '/src/DeliveryFailureTaxonomy.php';

$expected = [
    0 => 'transport', 401 => 'authentication', 403 => 'authentication',
    404 => 'routing', 408 => 'timeout', 429 => 'rate_limiting',
    503 => 'upstream_error', 422 => 'rejected',
];
foreach ($expected as $status => $code) {
    if (DeliveryFailureTaxonomy::fromHttpStatus($status) !== $code) {
        throw new RuntimeException('Unexpected delivery category for HTTP ' . $status);
    }
}
if (DeliveryFailureTaxonomy::fromException(new DeliveryTransportException('timeout')) !== 'timeout') {
    throw new RuntimeException('A transport timeout lost its category.');
}
if (DeliveryFailureTaxonomy::fromException(new DeliveryProviderStateException('failed')) !== 'rejected') {
    throw new RuntimeException('A terminal provider state lost its category.');
}
$summary = DeliveryFailureTaxonomy::safeSummary('bearer secret-from-provider', 429);
if ($summary !== 'Delivery failed: unknown (HTTP 429).') {
    throw new RuntimeException('Untrusted failure text escaped the bounded taxonomy.');
}
echo "Shared delivery failure taxonomy passed.\n";
