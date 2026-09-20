<?php

require_once __DIR__ . '/PackageManifest.php';

class PackageCompatibility
{
    public static function evaluate(array $manifest, array $runtime)
    {
        $manifest = PackageManifest::validate($manifest);
        $requirements = $manifest['compatibility'];
        $checks = [];

        $phpVersion = self::stringValue($runtime, 'php_version');
        $checks[] = self::rangeCheck('php_version', $phpVersion, $requirements['php']['minimum'], $requirements['php']['maximum_exclusive']);
        $observedExtensions = self::normalizedList(isset($runtime['php_extensions']) && is_array($runtime['php_extensions']) ? $runtime['php_extensions'] : []);
        $requiredExtensions = self::normalizedList($requirements['php']['extensions']);
        $missingExtensions = array_values(array_diff($requiredExtensions, $observedExtensions));
        $checks[] = [
            'id' => 'php_extensions',
            'compatible' => !$missingExtensions,
            'required' => $requiredExtensions,
            'observed' => $observedExtensions,
            'missing' => $missingExtensions,
        ];

        $mysqlVersion = self::stringValue($runtime, 'mysql_version');
        $checks[] = self::rangeCheck('mysql_version', $mysqlVersion, $requirements['mysql']['minimum'], $requirements['mysql']['maximum_exclusive']);
        $observedModes = self::normalizedList(isset($runtime['mysql_sql_modes']) && is_array($runtime['mysql_sql_modes']) ? $runtime['mysql_sql_modes'] : []);
        $requiredModes = self::normalizedList($requirements['mysql']['sql_modes']);
        $missingModes = array_values(array_diff($requiredModes, $observedModes));
        $checks[] = [
            'id' => 'mysql_sql_modes',
            'compatible' => !$missingModes,
            'required' => $requiredModes,
            'observed' => $observedModes,
            'missing' => $missingModes,
        ];
        $checks[] = self::exactCheck('mysql_charset', self::stringValue($runtime, 'mysql_charset'), $requirements['mysql']['charset']);
        $checks[] = self::exactCheck('mysql_collation', self::stringValue($runtime, 'mysql_collation'), $requirements['mysql']['collation']);

        $readerVersion = self::stringValue($runtime, 'reader_version');
        $checks[] = [
            'id' => 'reader_version',
            'compatible' => $readerVersion !== '' && version_compare($readerVersion, $manifest['minimum_reader_version'], '>='),
            'required' => ['minimum' => $manifest['minimum_reader_version']],
            'observed' => $readerVersion,
        ];

        $compatible = true;
        foreach ($checks as $check) {
            if (!$check['compatible']) {
                $compatible = false;
                break;
            }
        }
        return ['compatible' => $compatible, 'checks' => $checks];
    }

    private static function rangeCheck($id, $observed, $minimum, $maximumExclusive)
    {
        return [
            'id' => $id,
            'compatible' => $observed !== ''
                && version_compare($observed, $minimum, '>=')
                && version_compare($observed, $maximumExclusive, '<'),
            'required' => ['minimum' => $minimum, 'maximum_exclusive' => $maximumExclusive],
            'observed' => $observed,
        ];
    }

    private static function exactCheck($id, $observed, $required)
    {
        return [
            'id' => $id,
            'compatible' => $observed !== '' && strcasecmp($observed, $required) === 0,
            'required' => $required,
            'observed' => $observed,
        ];
    }

    private static function normalizedList(array $values)
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $normalized[strtolower(trim($value))] = true;
        }
        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }

    private static function stringValue(array $source, $key)
    {
        return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : '';
    }
}
