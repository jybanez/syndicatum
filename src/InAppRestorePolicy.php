<?php

/** Closed data policy for an in-app restore. Runtime, schema, settings and credentials are never applied. */
final class InAppRestorePolicy
{
    private static $restore = [
        'agents', 'agent_activation_bindings', 'agent_credential_scopes', 'agent_notification_webhooks',
        'chat_agents', 'chat_entries', 'chat_entry_recipients', 'chat_entry_revisions', 'chat_topics', 'chat_write_audit',
          'connector_devices', 'integration_connections', 'integration_credentials', 'integration_event_receipts',
        'integration_notification_recipients',
        'messages', 'message_addressees', 'message_revisions', 'pinned_messages',
        'projects', 'project_agents', 'project_invitations', 'project_members', 'project_message_sequences', 'project_participants',
        'users', 'user_system_roles', 'workspaces',
    ];

    private static $clear = [
        'account_oauth_attempts', 'agent_webhook_deliveries', 'connector_device_activation_routes',
        'connector_device_authorizations', 'connector_discussion_binding_intents', 'google_oauth_attempts', 'message_events_outbox',
        'oauth_access_tokens', 'oauth_authorization_codes', 'oauth_refresh_tokens',
        'responses_api_deliveries', 'security_rate_limits', 'syndicatum_sessions',
        'workspace_agent_trigger_deliveries',
    ];

    public static function restoreTables() { $tables = self::$restore; sort($tables, SORT_STRING); return $tables; }
    public static function clearOnlyTables() { $tables = self::$clear; sort($tables, SORT_STRING); return $tables; }

    public static function assertCompatible(PDO $pdo, array $manifest)
    {
        if (($manifest['format_version'] ?? null) !== '2.0' || ($manifest['package_type'] ?? null) !== 'full_clone'
            || ($manifest['include_data'] ?? null) !== true) {
            throw new InvalidArgumentException('In-app restore requires a current full-clone backup.');
        }
        $current = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $current = array_fill_keys($current, true);
        $package = array_fill_keys(array_keys($manifest['sql']['row_counts']), true);
        foreach (self::restoreTables() as $table) {
            if (isset($current[$table]) && !isset($package[$table])) {
                throw new InvalidArgumentException('The backup is missing required user data table: ' . $table . '.');
            }
        }
        return true;
    }
}
