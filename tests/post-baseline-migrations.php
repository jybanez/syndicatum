<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/BaselineInstaller.php';
require_once dirname(__DIR__) . '/src/InstallationState.php';

$root = dirname(__DIR__);
$metadata = json_decode(file_get_contents($root . '/schema/mysql84/baseline.json'), true);
$database = 'syndicatum_post_baseline_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_post_baseline_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe test database name.');
}
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
if (!preg_match('/^8\.4\./', $version)) {
    echo "SKIP  fresh post-baseline installation requires MySQL 8.4\n";
    exit(0);
}
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=');

try {
    $pdo = Db::pdo();
    $result = (new BaselineInstaller($pdo, $root . '/schema/mysql84/schema.sql',
        $root . '/schema/mysql84/baseline.json'))->install([
            'application_version' => $metadata['application_version'],
            'schema_baseline' => $metadata['baseline_id'],
            'schema_head' => $metadata['schema_head'],
            'baseline_source_commit' => $metadata['source_commit'],
            'release_source_commit' => str_repeat('a', 40),
            'package_sha256' => str_repeat('b', 64),
            'package_format_version' => '1.0',
            'installation_id' => Db::uuidV4(),
            'installed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    if ($result['post_baseline_migration_rows'] !== 10
        || !Db::columnExists($pdo, 'projects', 'context_version')
        || !Db::columnExists($pdo, 'project_agents', 'role_version')
        || !Db::columnExists($pdo, 'project_agents', 'supervising_participant_id')
        || !Db::tableExists($pdo, 'project_tasks')
        || !Db::tableExists($pdo, 'project_templates')
        || !Db::tableExists($pdo, 'project_template_agents')
        || !Db::tableExists($pdo, 'project_template_categories')
        || !Db::columnExists($pdo, 'project_templates', 'category_id')) {
        throw new RuntimeException('Fresh baseline installation did not apply the declared migration suffix.');
    }
    $state = (new InstallationState($pdo))->inspect();
    if (empty($state['ready']) || $state['identity']['schema_head'] !== '202609260005') {
        throw new RuntimeException('Post-baseline installation identity is not ready.');
    }
    echo "PASS  fresh baseline applies the declared migration suffix\n";
} finally {
    if (isset($admin) && $admin instanceof PDO) {
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
}
