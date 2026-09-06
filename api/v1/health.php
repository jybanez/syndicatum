<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';

try {
    $pdo = Db::pdo();
    $pdo->query('SELECT 1')->fetchColumn();
    $expanded = Db::tableExists($pdo, 'messages') && Db::tableExists($pdo, 'projects');
    $integrations = ['realtime' => ['enabled' => false, 'configured' => false], 'account' => ['enabled' => false, 'configured' => false]];
    if ($expanded && Db::tableExists($pdo, 'system_settings')) {
        $settings = new SettingsService($pdo); $public = $settings->publicSettings('integrations');
        $integrations['realtime'] = ['enabled' => (bool) $settings->get('realtime.enabled'),
            'configured' => $settings->get('realtime.base_url') !== '' && !empty($public['realtime.signing_secret']['configured']) && !empty($public['realtime.backend_ingress_secret']['configured'])];
        $integrations['account'] = ['enabled' => (bool) $settings->get('account.enabled'),
            'configured' => $settings->get('account.base_url') !== '' && !empty($public['account.client_secret']['configured'])];
    }
    Api::json(['data' => ['core' => ['status' => 'ok', 'database' => true, 'expanded_schema' => $expanded], 'integrations' => $integrations]]);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'CORE_UNAVAILABLE', 'message' => 'Syndicatum database is unavailable.'], 503);
}
