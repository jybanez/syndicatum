<?php

class DeliveryTransportException extends RuntimeException
{
    private $failureCode;

    public function __construct($failureCode)
    {
        $this->failureCode = $failureCode === 'timeout' ? 'timeout' : 'transport';
        parent::__construct('Delivery transport failed.');
    }

    public function failureCode()
    {
        return $this->failureCode;
    }
}

class DeliveryProviderStateException extends RuntimeException
{
    private $state;

    public function __construct($state)
    {
        $this->state = in_array($state, ['failed', 'cancelled', 'incomplete'], true) ? $state : 'unknown';
        parent::__construct('Provider response ended in state ' . $this->state . '.');
    }

    public function state()
    {
        return $this->state;
    }
}

class DeliveryFailureTaxonomy
{
    private static $codes = [
        'integration_disabled', 'transport', 'timeout', 'rate_limiting',
        'authentication', 'routing', 'upstream_error', 'rejected',
        'invalid_request', 'internal_error', 'unknown',
    ];

    public static function fromHttpStatus($status, $path = 'generic')
    {
        $status = (int) $status;
        if ($status === 0) { return 'transport'; }
        if ($status === 408) { return 'timeout'; }
        if ($status === 429) { return 'rate_limiting'; }
        if ($status === 401 || $status === 403) { return 'authentication'; }
        // Only Realtime's room ingress contract establishes 404 as routing.
        // A webhook or provider API 404 may mean a missing endpoint/resource.
        if ($status === 404) { return $path === 'realtime' ? 'routing' : 'rejected'; }
        if ($status >= 500 && $status <= 599) { return 'upstream_error'; }
        return 'rejected';
    }

    public static function fromException(Exception $exception, $httpStatus = null, $path = 'generic')
    {
        if ($httpStatus !== null) { return self::fromHttpStatus($httpStatus, $path); }
        if ($exception instanceof DeliveryTransportException) { return $exception->failureCode(); }
        if ($exception instanceof DeliveryProviderStateException) { return 'rejected'; }
        return 'internal_error';
    }

    public static function safeCode($code)
    {
        return in_array($code, self::$codes, true) ? $code : 'unknown';
    }

    public static function safeSummary($code, $httpStatus = null)
    {
        $code = self::safeCode($code);
        $status = $httpStatus === null ? null : (int) $httpStatus;
        return 'Delivery failed: ' . $code
            . ($status !== null && $status >= 100 && $status <= 599 ? ' (HTTP ' . $status . ')' : '') . '.';
    }
}
