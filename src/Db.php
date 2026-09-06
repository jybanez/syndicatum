<?php

class Db
{
    public static function config()
    {
        return [
            'host' => getenv('PBB_AGENTCHAT_DB_HOST') ?: '127.0.0.1',
            'database' => getenv('PBB_AGENTCHAT_DB_NAME') ?: 'pbb_agentchat',
            'username' => getenv('PBB_AGENTCHAT_DB_USER') ?: 'root',
            'password' => getenv('PBB_AGENTCHAT_DB_PASS') === false ? '' : getenv('PBB_AGENTCHAT_DB_PASS'),
            'secret' => self::environmentSecret('PBB_AGENTCHAT_SECRET'),
            'previous_secret' => self::environmentSecret('PBB_AGENTCHAT_PREVIOUS_SECRET'),
        ];
    }

    public static function pdo()
    {
        $config = self::config();
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['database']
        );

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function tableExists(PDO $pdo, $table)
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    public static function columnExists(PDO $pdo, $table, $column)
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() > 0;
    }

    public static function hashToken($token)
    {
        return hash_hmac('sha256', $token, self::requireSecret('PBB_AGENTCHAT_SECRET'));
    }

    public static function hashTokenWithPreviousSecret($token)
    {
        $secret = self::environmentSecret('PBB_AGENTCHAT_PREVIOUS_SECRET');
        if ($secret === null) {
            return null;
        }

        return hash_hmac('sha256', $token, $secret);
    }

    public static function hasPreviousSecret()
    {
        return self::environmentSecret('PBB_AGENTCHAT_PREVIOUS_SECRET') !== null;
    }

    public static function secretValue($name)
    {
        return self::environmentSecret($name);
    }

    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    private static function requireSecret($name)
    {
        $secret = self::environmentSecret($name);
        if ($secret === null) {
            throw new RuntimeException($name . ' is required for credential operations.');
        }

        return $secret;
    }

    private static function environmentSecret($name)
    {
        $value = getenv($name);
        if ($value !== false && trim((string) $value) !== '') {
            return (string) $value;
        }

        $secrets = self::privateSecrets();
        if (!isset($secrets[$name]) || trim((string) $secrets[$name]) === '') {
            return null;
        }

        return (string) $secrets[$name];
    }

    private static function privateSecrets()
    {
        $path = getenv('PBB_AGENTCHAT_SECRETS_FILE');
        if ($path === false || trim((string) $path) === '') {
            $path = dirname(dirname(dirname(dirname(__DIR__)))) . '/private/syndicatum-secrets.php';
        }

        if (!is_file($path)) {
            return [];
        }

        $secrets = require $path;
        if (!is_array($secrets)) {
            throw new RuntimeException('Syndicatum private secrets file must return an array.');
        }

        return $secrets;
    }
}
