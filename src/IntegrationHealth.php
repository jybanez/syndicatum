<?php

require_once __DIR__ . '/SettingsService.php';

class IntegrationHealth
{
    private $settings;

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    public function test($name, $baseUrlOverride = null)
    {
        if (!in_array($name, ['realtime', 'account'], true)) { throw new InvalidArgumentException('Unknown integration.'); }
        $enabled = $this->settings->get($name . '.enabled') === true;
        $baseUrl = rtrim($baseUrlOverride === null
            ? (string) $this->settings->get($name . '.base_url')
            : trim((string) $baseUrlOverride), '/');
        if ($baseUrl !== '') {
            $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
            if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new InvalidArgumentException('Integration base URL must be an absolute HTTP or HTTPS URL.');
            }
        }
        if ($baseUrl === '') { return ['name' => $name, 'enabled' => $enabled, 'configured' => false, 'reachable' => false, 'message' => 'Base URL is not configured.']; }
        $url = $baseUrl . ($name === 'realtime' ? '/api/ready' : '/up');
        $timeout = (int) $this->settings->get($name . '.timeout_seconds');
        $caBundle = trim((string) $this->settings->get($name . '.ca_bundle'));
        $started = microtime(true);
        $curl = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if ($caBundle !== '') { $options[CURLOPT_CAINFO] = $caBundle; }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $error = $body === false ? curl_error($curl) : '';
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $reachable = $body !== false && $status >= 200 && $status < 300;
        $credentialsConfigured = $name === 'realtime'
            ? trim((string) $this->settings->get('realtime.client_code')) !== ''
                && trim((string) $this->settings->get('realtime.project_code')) !== ''
                && trim((string) $this->settings->get('realtime.connector_authorization_project_code')) !== ''
                && trim((string) $this->settings->get('realtime.signing_secret')) !== ''
                && trim((string) $this->settings->get('realtime.backend_ingress_secret')) !== ''
            : trim((string) $this->settings->get('account.client_id')) !== ''
                && trim((string) $this->settings->get('account.client_secret')) !== '';
        return [
            'name' => $name, 'enabled' => $enabled, 'configured' => $credentialsConfigured, 'reachable' => $reachable,
            'credentials_configured' => $credentialsConfigured,
            'http_status' => $status ?: null, 'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'message' => $reachable ? 'Connection succeeded.' : ($error !== '' ? substr($error, 0, 200) : 'Readiness endpoint returned HTTP ' . $status . '.'),
        ];
    }
}
