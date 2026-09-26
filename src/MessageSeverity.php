<?php

/**
 * Canonical, presentation-independent message importance.
 * Message kind identifies the source; severity describes the event outcome.
 */
class MessageSeverity
{
    const VALUES = ['neutral', 'info', 'success', 'warning', 'error', 'critical'];

    public static function normalize($value, $default = 'neutral')
    {
        $severity = strtolower(trim((string) ($value === null || $value === '' ? $default : $value)));
        if (!in_array($severity, self::VALUES, true)) {
            throw new InvalidArgumentException(
                'severity must be one of: ' . implode(', ', self::VALUES) . '.'
            );
        }
        return $severity;
    }

    public static function forTaskStatus($status)
    {
        switch (strtolower(trim((string) $status))) {
            case 'completed': return 'success';
            case 'blocked': return 'warning';
            case 'cancelled': return 'warning';
            default: return 'neutral';
        }
    }
}
