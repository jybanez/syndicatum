<?php

final class TimezoneService
{
    public static function normalize($value, $allowEmpty = false)
    {
        $timezone = trim((string) $value);
        if ($timezone === '' && $allowEmpty) {
            return null;
        }
        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Timezone must be a valid IANA timezone identifier.');
        }
        return $timezone;
    }

    public static function resolve($personalTimezone, $systemTimezone)
    {
        $personal = trim((string) $personalTimezone);
        if ($personal !== '') {
            return self::normalize($personal);
        }
        $system = trim((string) $systemTimezone);
        return $system === '' ? 'UTC' : self::normalize($system);
    }

    public static function formatUtc($value, $timezone)
    {
        $zone = new DateTimeZone(self::normalize($timezone));
        try {
            $dateTime = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Email template expiry date is invalid.');
        }
        return $dateTime->setTimezone($zone)->format('F j, Y \\a\\t g:i A') . ' (' . $zone->getName() . ')';
    }
}
