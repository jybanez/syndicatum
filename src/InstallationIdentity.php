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
        $allowed = [
            'application_version', 'schema_baseline', 'schema_head', 'baseline_source_commit',
            'release_source_commit', 'package_sha256', 'package_format_version',
            'installation_id', 'installed_at', 'last_upgrade_id', 'last_upgrade_from_version',
            'last_upgrade_to_version', 'last_upgraded_at',
        ];
        foreach ($values as $field => $_value) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Installation identity contains an unsupported field: ' . (string) $field);
            }
        }
        foreach ([
            'application_version', 'schema_baseline', 'schema_head', 'baseline_source_commit',
            'release_source_commit', 'package_format_version', 'installation_id', 'installed_at',
        ] as $field) {
            if (!isset($values[$field]) || !is_string($values[$field]) || trim($values[$field]) === '') {
                throw new InvalidArgumentException($field . ' is required.');
            }
        }
        if (!isset($values['package_sha256']) || !is_string($values['package_sha256']) || !preg_match('/\A[a-f0-9]{64}\z/i', $values['package_sha256'])) {
            throw new InvalidArgumentException('package_sha256 must be a SHA-256 digest.');
        }
        foreach (['baseline_source_commit', 'release_source_commit'] as $field) {
            if (!preg_match('/\A[a-f0-9]{40}\z/i', $values[$field])) {
                throw new InvalidArgumentException($field . ' must be a full 40-character Git commit.');
            }
        }
        if (!preg_match('/\A1\.\d+(?:\.\d+)?\z/', $values['package_format_version'])) {
            throw new InvalidArgumentException('package_format_version is not supported.');
        }
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $values['installation_id'])) {
            throw new InvalidArgumentException('installation_id must be a UUID.');
        }
        self::validateTimestamp($values['installed_at'], 'installed_at');
        $upgradeFields = ['last_upgrade_id', 'last_upgrade_from_version', 'last_upgrade_to_version', 'last_upgraded_at'];
        $presentUpgradeFields = 0;
        foreach ($upgradeFields as $field) {
            if (isset($values[$field]) && (!is_string($values[$field]) || trim($values[$field]) === '')) {
                throw new InvalidArgumentException($field . ' must be a non-empty string when present.');
            }
            if (isset($values[$field]) && trim($values[$field]) !== '') {
                $presentUpgradeFields++;
            }
        }
        if ($presentUpgradeFields !== 0 && $presentUpgradeFields !== count($upgradeFields)) {
            throw new InvalidArgumentException('Last successful upgrade identifier, from/to versions, and timestamp must be recorded together.');
        }
        if ($presentUpgradeFields === count($upgradeFields)) {
            self::validateTimestamp($values['last_upgraded_at'], 'last_upgraded_at');
            if ($values['last_upgrade_from_version'] === $values['last_upgrade_to_version']) {
                throw new InvalidArgumentException('Upgrade from/to versions must describe a transition.');
            }
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
