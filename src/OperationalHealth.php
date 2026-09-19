<?php

function operationalDeliveryState($recentTerminalFailures, $oldestPendingSeconds)
{
    if ($recentTerminalFailures < 0 || $oldestPendingSeconds < 0) {
        throw new InvalidArgumentException('Operational delivery metrics cannot be negative');
    }
    return $recentTerminalFailures > 0 || $oldestPendingSeconds > 300
        ? 'degraded' : 'ok';
}

function operationalWorkerState($ageSeconds, $staleAfterSeconds = 120)
{
    if ($staleAfterSeconds < 30 || $staleAfterSeconds > 3600) {
        throw new InvalidArgumentException('Worker stale threshold must be 30–3600 seconds');
    }
    if ($ageSeconds === null) {
        return 'unknown';
    }
    if ($ageSeconds < 0) {
        throw new InvalidArgumentException('Worker heartbeat age cannot be negative');
    }
    return $ageSeconds > $staleAfterSeconds ? 'degraded' : 'ok';
}

function operationalAggregateState(array $components)
{
    $hasUnknown = false;
    $hasDegraded = false;
    foreach ($components as $state) {
        if ($state === 'unknown') {
            $hasUnknown = true;
        } elseif ($state === 'degraded') {
            $hasDegraded = true;
        } elseif ($state !== 'ok') {
            throw new InvalidArgumentException('Unsupported operational component state');
        }
    }
    if ($hasUnknown) {
        return 'unknown';
    }
    return $hasDegraded ? 'degraded' : 'ok';
}
