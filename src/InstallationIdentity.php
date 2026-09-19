<?php

class InstallationIdentity
{
    private $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromArray(array $values)
    {
        foreach ([
            'application_version', 'schema_baseline', 'schema_head', 'package_format_version',
            'installation_id', 'installed_at',
        ] as $field) {
            if (!isset($values[$field]) || !is_string($values[$field]) || trim($values[$field]) === '') {
                throw new InvalidArgumentException($field . ' is required.');
            }
        }
        if (!isset($values['package_sha256']) || !is_string($values['package_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/i', $values['package_sha256'])) {
            throw new InvalidArgumentException('package_sha256 must be a SHA-256 digest.');
        }
        if (!preg_match('/\A1\.\d+(?:\.\d+)?\z/', $values['package_format_version'])) {
            throw new InvalidArgumentException('package_format_version is not supported.');
        }
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $values['installation_id'])) {
            throw new InvalidArgumentException('installation_id must be a UUID.');
        }
        self::validateTimestamp($values['installed_at'], 'installed_at');
        $hasUpgradeId = isset($values['last_upgrade_id']) && trim((string) $values['last_upgrade_id']) !== '';
        $hasUpgradeTime = isset($values['last_upgraded_at']) && trim((string) $values['last_upgraded_at']) !== '';
        if ($hasUpgradeId !== $hasUpgradeTime) {
            throw new InvalidArgumentException('Last successful upgrade identifier and timestamp must be recorded together.');
        }
        if ($hasUpgradeTime) {
            self::validateTimestamp($values['last_upgraded_at'], 'last_upgraded_at');
        }
        return new self($values);
    }

    public function toArray()
    {
        return $this->values;
    }

    private static function validateTimestamp($value, $field)
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value)) {
            throw new InvalidArgumentException($field . ' must be a UTC RFC3339 timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException($field . ' is not a valid timestamp.');
        }
    }
}
