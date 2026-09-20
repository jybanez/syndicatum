<?php

final class BackupSecrets
{
    const CONTRACT_NAME = 'syndicatum-portable-secrets';
    const FORMAT_VERSION = '1.0';

    private static $closedNames = [
        'PBB_AGENTCHAT_DB_PASS',
        'PBB_AGENTCHAT_PREVIOUS_SECRET',
        'PBB_AGENTCHAT_SECRET',
        'SYNDICATUM_BACKUP_KEY',
        'SYNDICATUM_MASTER_KEY',
    ];

    public static function create(array $exported)
    {
        foreach (['PBB_AGENTCHAT_SECRET', 'SYNDICATUM_MASTER_KEY'] as $required) {
            if (!isset($exported[$required]) || !is_string($exported[$required]) || strlen($exported[$required]) < 32) {
                throw new InvalidArgumentException($required . ' must be exported to preserve recovery decryptability.');
            }
        }
        foreach ($exported as $name => $value) {
            if (!in_array($name, ['PBB_AGENTCHAT_SECRET', 'PBB_AGENTCHAT_PREVIOUS_SECRET', 'SYNDICATUM_MASTER_KEY'], true)
                || !is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Portable backup secret input is not allowlisted.');
            }
        }
        $entries = [];
        foreach (self::$closedNames as $name) {
            if (array_key_exists($name, $exported)) {
                $entries[] = ['name' => $name, 'disposition' => 'exported', 'required' => $name !== 'PBB_AGENTCHAT_PREVIOUS_SECRET', 'value' => $exported[$name]];
            } elseif ($name === 'PBB_AGENTCHAT_PREVIOUS_SECRET') {
                $entries[] = ['name' => $name, 'disposition' => 'regenerated', 'required' => false, 'value' => null];
            } else {
                $entries[] = ['name' => $name, 'disposition' => 'operator_supplied', 'required' => true, 'value' => null];
            }
        }
        return ['contract_name' => self::CONTRACT_NAME, 'format_version' => self::FORMAT_VERSION, 'entries' => $entries];
    }

    public static function encode(array $document)
    {
        self::validate($document);
        $json = json_encode($document, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new RuntimeException('Portable backup secrets could not be encoded.'); }
        return $json;
    }

    public static function parse($json)
    {
        if (!is_string($json) || trim($json) === '') { throw new InvalidArgumentException('Portable backup secrets JSON is required.'); }
        $wire = json_decode($json, false, 32, JSON_BIGINT_AS_STRING);
        if (!($wire instanceof stdClass) || json_last_error() !== JSON_ERROR_NONE || !isset($wire->entries) || !is_array($wire->entries)) {
            throw new InvalidArgumentException('Portable backup secrets must contain a JSON entry list.');
        }
        $document = json_decode($json, true, 32, JSON_BIGINT_AS_STRING);
        if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE) { throw new InvalidArgumentException('Portable backup secrets JSON is invalid.'); }
        self::validate($document);
        return $document;
    }

    public static function assertTargetMatches(array $document, array $targetSecrets)
    {
        self::validate($document);
        $classifications = [];
        foreach ($document['entries'] as $entry) {
            $classifications[$entry['name']] = ['disposition' => $entry['disposition'], 'required' => $entry['required']];
            if ($entry['disposition'] === 'exported') {
                if (!isset($targetSecrets[$entry['name']]) || !is_string($targetSecrets[$entry['name']])
                    || !hash_equals($entry['value'], $targetSecrets[$entry['name']])) {
                    throw new InvalidArgumentException('Staged restore secret does not match authenticated backup: ' . $entry['name'] . '.');
                }
            }
        }
        return $classifications;
    }

    private static function validate(array $document)
    {
        if (array_keys($document) !== ['contract_name', 'format_version', 'entries']
            || $document['contract_name'] !== self::CONTRACT_NAME || $document['format_version'] !== self::FORMAT_VERSION
            || !is_array($document['entries'])) {
            throw new InvalidArgumentException('Portable backup secret contract is invalid.');
        }
        $names = [];
        foreach ($document['entries'] as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['name', 'disposition', 'required', 'value']
                || !is_string($entry['name']) || !is_string($entry['disposition']) || !is_bool($entry['required'])
                || !in_array($entry['disposition'], ['exported', 'regenerated', 'operator_supplied'], true)
                || ($entry['disposition'] === 'exported' && (!is_string($entry['value']) || trim($entry['value']) === ''))
                || ($entry['disposition'] !== 'exported' && $entry['value'] !== null)) {
                throw new InvalidArgumentException('Portable backup secret entry is invalid.');
            }
            $names[] = $entry['name'];
        }
        if ($names !== self::$closedNames) { throw new InvalidArgumentException('Portable backup secret classification must match the closed V1 inventory.'); }
    }
}
