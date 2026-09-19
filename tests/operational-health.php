<?php

require_once dirname(__DIR__) . '/src/OperationalHealth.php';

$cases = [
    [['ok', 'ok', 'ok', 'ok'], 'ok'],
    [['ok', 'degraded', 'ok', 'ok'], 'degraded'],
    [['ok', 'unknown', 'ok', 'ok'], 'unknown'],
    [['degraded', 'unknown', 'ok', 'ok'], 'unknown'],
];

foreach ($cases as $case) {
    if (operationalAggregateState($case[0]) !== $case[1]) {
        fwrite(STDERR, 'Operational aggregate state assertion failed' . PHP_EOL);
        exit(1);
    }
}

$deliveryCases = [
    [0, 0, 'ok'],
    [0, 300, 'ok'],
    [0, 301, 'degraded'],
    [1, 0, 'degraded'],
    [1, 301, 'degraded'],
];
foreach ($deliveryCases as $case) {
    if (operationalDeliveryState($case[0], $case[1]) !== $case[2]) {
        fwrite(STDERR, 'Operational delivery state assertion failed' . PHP_EOL);
        exit(1);
    }
}

$workerCases = [[null, 'unknown'], [0, 'ok'], [120, 'ok'], [121, 'degraded']];
foreach ($workerCases as $case) {
    if (operationalWorkerState($case[0]) !== $case[1]) {
        fwrite(STDERR, 'Operational worker state assertion failed' . PHP_EOL);
        exit(1);
    }
}
if (operationalWorkerState(45, 60) !== 'ok' || operationalWorkerState(61, 60) !== 'degraded') {
    fwrite(STDERR, 'Configurable worker threshold assertion failed' . PHP_EOL);
    exit(1);
}

echo 'Operational aggregate, delivery, and worker state assertions passed' . PHP_EOL;
