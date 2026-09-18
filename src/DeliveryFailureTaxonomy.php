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
    public function __construct($state)
    {
        $state = in_array($state, ['failed', 'cancelled', 'incomplete'], true) ? $state : 'unknown';
        parent::__construct('Provider response ended in state ' . $state . '.');
    }
}

class DeliveryFailureTaxonomy
{
    private static $codes = [
        'integration_disabled', 'transport', 'timeout', 'rate_limiting',
        'authentication', 'routing', 'upstream_error', 'rejected',
        'invalid_request', 'internal_error', 'unknown',
    ];

    public static function fromHttpStatus($status)
    {
        $status = (int) $status;
        if ($status === 0) { return 'transport'; }
        if ($status === 408) { return 'timeout'; }
        if ($status === 429) { return 'rate_limiting'; }
        if ($status === 401 || $status === 403) { return 'authentication'; }
        if ($status === 404) { return 'routing'; }
        if ($status >= 500 && $status <= 599) { return 'upstream_error'; }
        return 'rejected';
    }

    public static function fromException(Exception $exception, $httpStatus = null)
    {
        if ($httpStatus !== null) { return self::fromHttpStatus($httpStatus); }
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
