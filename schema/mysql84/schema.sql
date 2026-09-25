-- Syndicatum authoritative MySQL 8.4 baseline.

-- Generated from immutable source commit 8d8cfb12aff96ac1a7ce7ce1a8ad05c6c5e5ec9d.

-- Do not edit by hand; regenerate and review the schema digest.

SET @syndicatum_saved_foreign_key_checks = @@FOREIGN_KEY_CHECKS;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `account_oauth_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attempt_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nonce` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `return_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '/',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attempt_hash` (`attempt_hash`),
  UNIQUE KEY `state_hash` (`state_hash`),
  KEY `idx_account_oauth_attempts_expiry` (`expires_at`,`consumed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `administrative_audit_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_administrative_audit_created` (`created_at`,`id`),
  KEY `idx_administrative_audit_actor` (`actor_user_id`,`created_at`),
  CONSTRAINT `fk_administrative_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `agent_activation_bindings` (
  `agent_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `runtime_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'codex',
  `activation_driver` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'workspace_agent',
  `conversation_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `working_directory` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `workspace_agent_trigger_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `workspace_agent_conversation_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `workspace_agent_token_encrypted` mediumtext COLLATE utf8mb4_unicode_ci,
  `workspace_agent_last_success_at` datetime DEFAULT NULL,
  `workspace_agent_last_failure_at` datetime DEFAULT NULL,
  `workspace_agent_last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responses_model` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responses_api_key_encrypted` mediumtext COLLATE utf8mb4_unicode_ci,
  `responses_mcp_token_encrypted` mediumtext COLLATE utf8mb4_unicode_ci,
  `responses_last_response_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `responses_last_success_at` datetime DEFAULT NULL,
  `responses_last_failure_at` datetime DEFAULT NULL,
  `responses_last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`agent_id`),
  KEY `idx_agent_activation_bindings_project` (`project_id`,`enabled`),
  KEY `fk_agent_activation_bindings_agent` (`project_id`,`agent_id`),
  KEY `fk_agent_activation_bindings_creator` (`created_by_user_id`),
  CONSTRAINT `fk_agent_activation_bindings_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agent_activation_bindings_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `agent_credential_scopes` (
  `agent_id` bigint unsigned NOT NULL,
  `scope` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`agent_id`,`scope`),
  CONSTRAINT `fk_agent_credential_scopes_agent` FOREIGN KEY (`agent_id`) REFERENCES `chat_agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `agent_notification_webhooks` (
  `agent_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `endpoint_url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `events_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `signing_secret_encrypted` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_failure_at` datetime DEFAULT NULL,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`agent_id`),
  KEY `idx_agent_notification_webhooks_project` (`project_id`,`enabled`),
  KEY `fk_agent_notification_webhooks_agent` (`project_id`,`agent_id`),
  CONSTRAINT `fk_agent_notification_webhooks_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `agent_webhook_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('queued','sending','retry','succeeded','dead') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `last_attempt_at` datetime DEFAULT NULL,
  `next_attempt_at` datetime NOT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_failure_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `terminal_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_uuid` (`delivery_uuid`),
  UNIQUE KEY `uq_agent_webhook_delivery` (`message_id`,`agent_id`,`event_type`),
  KEY `idx_agent_webhook_deliveries_pending` (`status`,`next_attempt_at`,`id`),
  KEY `idx_agent_webhook_deliveries_agent` (`agent_id`,`created_at`),
  KEY `fk_agent_webhook_deliveries_agent` (`project_id`,`agent_id`),
  CONSTRAINT `fk_agent_webhook_deliveries_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agent_webhook_deliveries_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_agent_webhook_deliveries_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `source_order` int DEFAULT NULL,
  `token_prefix` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_secret_version` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_prefix` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_secret_version` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_expires_at` datetime DEFAULT NULL,
  `role` enum('agent','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_name` (`project_name`),
  UNIQUE KEY `token_prefix` (`token_prefix`),
  UNIQUE KEY `claim_prefix` (`claim_prefix`),
  KEY `idx_chat_agents_active_order` (`is_active`,`source_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `source_order` int DEFAULT NULL,
  `token_prefix` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_secret_version` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_prefix` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_secret_version` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_expires_at` datetime DEFAULT NULL,
  `role` enum('agent','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_name` (`project_name`),
  UNIQUE KEY `token_prefix` (`token_prefix`),
  UNIQUE KEY `claim_prefix` (`claim_prefix`),
  KEY `idx_chat_agents_active_order` (`is_active`,`source_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entry_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sender_agent_id` bigint unsigned NOT NULL,
  `message_timestamp` datetime NOT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_line` int DEFAULT NULL,
  `source_order` int DEFAULT NULL,
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_uuid` (`entry_uuid`),
  UNIQUE KEY `source_hash` (`source_hash`),
  KEY `idx_chat_entries_timestamp` (`message_timestamp`,`id`),
  KEY `idx_chat_entries_sender` (`sender_agent_id`,`message_timestamp`),
  KEY `idx_chat_entries_deleted` (`deleted_at`),
  CONSTRAINT `fk_chat_entries_sender` FOREIGN KEY (`sender_agent_id`) REFERENCES `chat_agents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_entry_recipients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint unsigned NOT NULL,
  `target_agent_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_entry_recipient` (`entry_id`,`target_agent_id`),
  KEY `idx_chat_entry_recipients_target` (`target_agent_id`,`entry_id`),
  CONSTRAINT `fk_chat_entry_recipients_entry` FOREIGN KEY (`entry_id`) REFERENCES `chat_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_entry_recipients_target` FOREIGN KEY (`target_agent_id`) REFERENCES `chat_agents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_entry_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint unsigned NOT NULL,
  `edited_by_agent_id` bigint unsigned NOT NULL,
  `previous_body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `new_body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `edited_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_entry_revisions_entry` (`entry_id`,`edited_at`),
  KEY `fk_chat_entry_revisions_editor` (`edited_by_agent_id`),
  CONSTRAINT `fk_chat_entry_revisions_editor` FOREIGN KEY (`edited_by_agent_id`) REFERENCES `chat_agents` (`id`),
  CONSTRAINT `fk_chat_entry_revisions_entry` FOREIGN KEY (`entry_id`) REFERENCES `chat_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_topics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_agent_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_hash` (`source_hash`),
  KEY `idx_chat_topics_active` (`is_active`,`deleted_at`),
  KEY `fk_chat_topics_creator` (`created_by_agent_id`),
  CONSTRAINT `fk_chat_topics_creator` FOREIGN KEY (`created_by_agent_id`) REFERENCES `chat_agents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_write_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint unsigned DEFAULT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `ip_address` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_write_audit_created` (`created_at`),
  KEY `fk_chat_write_audit_agent` (`agent_id`),
  CONSTRAINT `fk_chat_write_audit_agent` FOREIGN KEY (`agent_id`) REFERENCES `chat_agents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `connector_device_activation_routes` (
  `device_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `runtime_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'codex',
  `conversation_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `working_directory` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`device_id`,`agent_id`),
  KEY `idx_connector_device_routes_project` (`device_id`,`project_id`,`enabled`),
  KEY `fk_connector_device_routes_agent` (`project_id`,`agent_id`),
  CONSTRAINT `fk_connector_device_routes_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_connector_device_routes_device` FOREIGN KEY (`device_id`) REFERENCES `connector_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `connector_device_authorizations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_code_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_code_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `notification_channel` char(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','approved','denied','consumed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `approved_user_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `approved_at` datetime DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_code_hash` (`device_code_hash`),
  UNIQUE KEY `user_code_hash` (`user_code_hash`),
  UNIQUE KEY `uq_connector_authorizations_channel` (`notification_channel`),
  KEY `idx_connector_authorizations_expiry` (`status`,`expires_at`),
  KEY `fk_connector_authorizations_user` (`approved_user_id`),
  CONSTRAINT `fk_connector_authorizations_user` FOREIGN KEY (`approved_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `connector_devices` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `display_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `token_prefix` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_connector_devices_user` (`user_id`,`revoked_at`,`expires_at`),
  CONSTRAINT `fk_connector_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `connector_discussion_binding_intents` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `context_token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `oauth_access_token_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `requested_agent_id` bigint unsigned DEFAULT NULL,
  `requested_agent_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `confirmed_agent_id` bigint unsigned DEFAULT NULL,
  `discussion_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discussion_reference` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_by_device_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `context_token_hash` (`context_token_hash`),
  KEY `idx_binding_intent_user` (`created_by_user_id`,`status`,`expires_at`),
  KEY `idx_binding_intent_oauth` (`oauth_access_token_id`,`status`),
  KEY `fk_binding_intent_requested_agent` (`project_id`,`requested_agent_id`),
  KEY `fk_binding_intent_confirmed_agent` (`project_id`,`confirmed_agent_id`),
  KEY `fk_binding_intent_device` (`resolved_by_device_id`),
  CONSTRAINT `fk_binding_intent_confirmed_agent` FOREIGN KEY (`project_id`, `confirmed_agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binding_intent_device` FOREIGN KEY (`resolved_by_device_id`) REFERENCES `connector_devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_binding_intent_oauth` FOREIGN KEY (`oauth_access_token_id`) REFERENCES `oauth_access_tokens` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binding_intent_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binding_intent_requested_agent` FOREIGN KEY (`project_id`, `requested_agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_binding_intent_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_worker_heartbeats` (
  `worker_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_success_at` datetime NOT NULL,
  PRIMARY KEY (`worker_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `google_oauth_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attempt_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nonce` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_verifier` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `flow_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'login',
  `link_user_id` bigint unsigned DEFAULT NULL,
  `return_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '/',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attempt_hash` (`attempt_hash`),
  UNIQUE KEY `state_hash` (`state_hash`),
  KEY `idx_google_oauth_attempts_expiry` (`expires_at`,`consumed_at`),
  KEY `idx_google_oauth_attempts_link_user` (`link_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `legacy_api_usage_daily` (
  `usage_date` date NOT NULL,
  `endpoint` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_count` bigint unsigned NOT NULL DEFAULT '0',
  `first_used_at` datetime NOT NULL,
  `last_used_at` datetime NOT NULL,
  PRIMARY KEY (`usage_date`,`endpoint`,`method`),
  KEY `idx_legacy_api_usage_last` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mcp_service_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_mcp_service_agent` (`project_id`,`agent_id`,`revoked_at`),
  KEY `fk_mcp_service_creator` (`created_by_user_id`),
  CONSTRAINT `fk_mcp_service_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mcp_service_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_addressees` (
  `message_id` bigint unsigned NOT NULL,
  `participant_id` bigint unsigned NOT NULL,
  `reason` enum('direct','mention','broadcast') COLLATE utf8mb4_unicode_ci NOT NULL,
  `notified_at` datetime DEFAULT NULL,
  `seen_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `responsibility_status_generation` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`message_id`,`participant_id`),
  KEY `idx_message_addressees_responsibility` (`participant_id`,`acknowledged_at`,`message_id`),
  CONSTRAINT `fk_message_addressees_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_addressees_participant` FOREIGN KEY (`participant_id`) REFERENCES `project_participants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_events_outbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_sequence` bigint unsigned DEFAULT NULL,
  `payload_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `last_attempt_at` datetime DEFAULT NULL,
  `available_at` datetime NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_failure_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_uuid` (`event_uuid`),
  KEY `idx_message_events_outbox_pending` (`published_at`,`failed_at`,`available_at`,`id`),
  KEY `fk_message_events_outbox_project` (`project_id`),
  KEY `fk_message_events_outbox_message` (`message_id`),
  CONSTRAINT `fk_message_events_outbox_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_events_outbox_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_revision_id` bigint unsigned DEFAULT NULL,
  `message_id` bigint unsigned NOT NULL,
  `editor_participant_id` bigint unsigned NOT NULL,
  `previous_body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `new_body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `edited_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legacy_revision_id` (`legacy_revision_id`),
  KEY `idx_message_revisions_message` (`message_id`,`edited_at`),
  KEY `fk_message_revisions_editor` (`editor_participant_id`),
  CONSTRAINT `fk_message_revisions_editor` FOREIGN KEY (`editor_participant_id`) REFERENCES `project_participants` (`id`),
  CONSTRAINT `fk_message_revisions_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `legacy_entry_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned NOT NULL,
  `project_sequence` bigint unsigned NOT NULL,
  `sender_participant_id` bigint unsigned NOT NULL,
  `reply_to_message_id` bigint unsigned DEFAULT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_idempotency_key` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_fingerprint` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `correlation_id` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reply_depth` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `message_uuid` (`message_uuid`),
  UNIQUE KEY `uq_messages_project_sequence` (`project_id`,`project_sequence`),
  UNIQUE KEY `legacy_entry_id` (`legacy_entry_id`),
  UNIQUE KEY `uq_messages_idempotency` (`project_id`,`sender_participant_id`,`client_idempotency_key`),
  KEY `idx_messages_project_page` (`project_id`,`deleted_at`,`project_sequence`),
  KEY `idx_messages_sender` (`sender_participant_id`,`project_sequence`),
  KEY `fk_messages_reply` (`reply_to_message_id`),
  CONSTRAINT `fk_messages_legacy` FOREIGN KEY (`legacy_entry_id`) REFERENCES `chat_entries` (`id`),
  CONSTRAINT `fk_messages_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_reply` FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`),
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_participant_id`) REFERENCES `project_participants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `resource_uri` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_text` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_oauth_access_expiry` (`expires_at`,`revoked_at`),
  KEY `fk_oauth_access_client` (`client_id`),
  KEY `fk_oauth_access_user` (`user_id`),
  KEY `fk_oauth_access_project` (`project_id`),
  KEY `fk_oauth_access_agent` (`agent_id`),
  CONSTRAINT `fk_oauth_access_agent` FOREIGN KEY (`agent_id`) REFERENCES `chat_agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_access_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_access_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_access_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_authorization_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `redirect_uri` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_uri` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_text` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_challenge` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_hash` (`code_hash`),
  KEY `idx_oauth_codes_expiry` (`expires_at`,`consumed_at`),
  KEY `fk_oauth_codes_client` (`client_id`),
  KEY `fk_oauth_codes_user` (`user_id`),
  KEY `fk_oauth_codes_project` (`project_id`),
  KEY `fk_oauth_codes_agent` (`agent_id`),
  CONSTRAINT `fk_oauth_codes_agent` FOREIGN KEY (`agent_id`) REFERENCES `chat_agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_codes_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_codes_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_clients` (
  `client_id` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `redirect_uris_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_endpoint_auth_method` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_refresh_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token_id` bigint unsigned NOT NULL,
  `client_id` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `resource_uri` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_text` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_oauth_refresh_expiry` (`expires_at`,`consumed_at`,`revoked_at`),
  KEY `fk_oauth_refresh_access` (`access_token_id`),
  KEY `fk_oauth_refresh_client` (`client_id`),
  KEY `fk_oauth_refresh_user` (`user_id`),
  KEY `fk_oauth_refresh_project` (`project_id`),
  KEY `fk_oauth_refresh_agent` (`agent_id`),
  CONSTRAINT `fk_oauth_refresh_access` FOREIGN KEY (`access_token_id`) REFERENCES `oauth_access_tokens` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_refresh_agent` FOREIGN KEY (`agent_id`) REFERENCES `chat_agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_refresh_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_refresh_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_refresh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pinned_messages` (
  `project_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `pinned_by_participant_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`project_id`,`message_id`),
  KEY `fk_pinned_messages_message` (`message_id`),
  KEY `fk_pinned_messages_participant` (`pinned_by_participant_id`),
  CONSTRAINT `fk_pinned_messages_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pinned_messages_participant` FOREIGN KEY (`pinned_by_participant_id`) REFERENCES `project_participants` (`id`),
  CONSTRAINT `fk_pinned_messages_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_agents` (
  `project_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `display_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `runtime_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capabilities_json` json DEFAULT NULL,
  `status` enum('active','suspended','retired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`project_id`,`agent_id`),
  UNIQUE KEY `uq_project_agents_agent` (`agent_id`),
  KEY `idx_project_agents_status` (`project_id`,`status`),
  CONSTRAINT `fk_project_agents_agent` FOREIGN KEY (`agent_id`) REFERENCES `chat_agents` (`id`),
  CONSTRAINT `fk_project_agents_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `invited_email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','member','viewer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invited_by_user_id` bigint unsigned NOT NULL,
  `accepted_by_user_id` bigint unsigned DEFAULT NULL,
  `status` enum('pending','accepted','rejected','expired','revoked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_project_invitations_lookup` (`project_id`,`invited_email`,`status`),
  KEY `fk_project_invitations_inviter` (`invited_by_user_id`),
  KEY `fk_project_invitations_acceptor` (`accepted_by_user_id`),
  CONSTRAINT `fk_project_invitations_acceptor` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_project_invitations_inviter` FOREIGN KEY (`invited_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_project_invitations_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_members` (
  `project_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role` enum('owner','admin','member','viewer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member',
  `status` enum('active','suspended','removed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `removed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`project_id`,`user_id`),
  KEY `idx_project_members_user` (`user_id`,`status`,`project_id`),
  CONSTRAINT `fk_project_members_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_message_sequences` (
  `project_id` bigint unsigned NOT NULL,
  `next_sequence` bigint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`project_id`),
  CONSTRAINT `fk_project_message_sequences_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `kind` enum('human','agent') COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `status` enum('active','suspended','removed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `status_generation` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_participants_user` (`project_id`,`user_id`),
  UNIQUE KEY `uq_project_participants_agent` (`project_id`,`agent_id`),
  KEY `idx_project_participants_directory` (`project_id`,`status`,`kind`),
  CONSTRAINT `fk_project_participants_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`),
  CONSTRAINT `fk_project_participants_human` FOREIGN KEY (`project_id`, `user_id`) REFERENCES `project_members` (`project_id`, `user_id`),
  CONSTRAINT `fk_project_participants_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_project_participants_identity` CHECK ((((`kind` = _utf8mb4'human') and (`user_id` is not null) and (`agent_id` is null)) or ((`kind` = _utf8mb4'agent') and (`agent_id` is not null) and (`user_id` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `owner_user_id` bigint unsigned NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `instructions` mediumtext COLLATE utf8mb4_unicode_ci,
  `source_template_public_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_template_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_template_version` bigint unsigned DEFAULT NULL,
  `status` enum('active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_projects_workspace_slug` (`workspace_id`,`slug`),
  UNIQUE KEY `uq_projects_public_id` (`public_id`),
  KEY `idx_projects_owner_status` (`owner_user_id`,`status`),
  KEY `idx_projects_source_template` (`source_template_public_id`),
  KEY `fk_projects_workspace_owner` (`workspace_id`,`owner_user_id`),
  CONSTRAINT `fk_projects_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_projects_workspace_owner` FOREIGN KEY (`workspace_id`, `owner_user_id`) REFERENCES `workspaces` (`id`, `owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `responses_api_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `status` enum('queued','sending','waiting','retry','succeeded','dead') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `last_attempt_at` datetime DEFAULT NULL,
  `next_attempt_at` datetime NOT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `response_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `response_state` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_failure_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `terminal_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_uuid` (`delivery_uuid`),
  UNIQUE KEY `uq_responses_api_delivery` (`message_id`,`agent_id`),
  KEY `idx_responses_api_pending` (`status`,`next_attempt_at`,`id`),
  KEY `fk_responses_api_agent` (`project_id`,`agent_id`),
  CONSTRAINT `fk_responses_api_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_responses_api_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_responses_api_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `responsibility_events` (
  `event_message_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `request_message_id` bigint unsigned NOT NULL,
  `initial_responder_participant_id` bigint unsigned NOT NULL,
  `actor_participant_id` bigint unsigned NOT NULL,
  `actor_was_moderator` tinyint(1) NOT NULL DEFAULT '0',
  `kind` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prior_state` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expected_event_message_id` bigint unsigned NOT NULL,
  `reference_event_message_id` bigint unsigned DEFAULT NULL,
  `target_participant_id` bigint unsigned DEFAULT NULL,
  `responder_status_generation` bigint unsigned DEFAULT NULL,
  `idempotency_key` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`event_message_id`),
  UNIQUE KEY `uq_responsibility_retry` (`project_id`,`actor_participant_id`,`idempotency_key`),
  KEY `idx_responsibility_item` (`project_id`,`request_message_id`,`initial_responder_participant_id`,`event_message_id`),
  KEY `fk_responsibility_request` (`request_message_id`),
  KEY `fk_responsibility_responder` (`initial_responder_participant_id`),
  KEY `fk_responsibility_actor` (`actor_participant_id`),
  KEY `fk_responsibility_target` (`target_participant_id`),
  CONSTRAINT `fk_responsibility_actor` FOREIGN KEY (`actor_participant_id`) REFERENCES `project_participants` (`id`),
  CONSTRAINT `fk_responsibility_message` FOREIGN KEY (`event_message_id`) REFERENCES `messages` (`id`),
  CONSTRAINT `fk_responsibility_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_responsibility_request` FOREIGN KEY (`request_message_id`) REFERENCES `messages` (`id`),
  CONSTRAINT `fk_responsibility_responder` FOREIGN KEY (`initial_responder_participant_id`) REFERENCES `project_participants` (`id`),
  CONSTRAINT `fk_responsibility_target` FOREIGN KEY (`target_participant_id`) REFERENCES `project_participants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `security_rate_limits` (
  `bucket_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `window_started_at` datetime NOT NULL,
  `hit_count` int unsigned NOT NULL DEFAULT '0',
  `blocked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_security_rate_limits_cleanup` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `syndicatum_schema_migrations` (
  `version` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime NOT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `syndicatum_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `csrf_token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_session_id` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `auth_provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'native',
  `ip_address` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_syndicatum_sessions_user` (`user_id`,`revoked_at`,`expires_at`),
  CONSTRAINT `fk_syndicatum_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_settings` (
  `setting_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_json` mediumtext COLLATE utf8mb4_unicode_ci,
  `encrypted_value` mediumtext COLLATE utf8mb4_unicode_ci,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`),
  KEY `fk_system_settings_editor` (`updated_by_user_id`),
  CONSTRAINT `fk_system_settings_editor` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_system_roles` (
  `user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `granted_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_system_roles_role` (`role_id`,`user_id`),
  KEY `fk_user_system_roles_granter` (`granted_by_user_id`),
  CONSTRAINT `fk_user_system_roles_granter` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_system_roles_role` FOREIGN KEY (`role_id`) REFERENCES `system_roles` (`id`),
  CONSTRAINT `fk_user_system_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `normalized_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pbb_user_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_subject` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `status` enum('active','suspended','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `normalized_email` (`normalized_email`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `pbb_user_id` (`pbb_user_id`),
  UNIQUE KEY `google_subject` (`google_subject`),
  KEY `idx_users_status` (`status`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workspace_agent_trigger_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `message_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `status` enum('queued','sending','retry','succeeded','dead') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempt_count` int unsigned NOT NULL DEFAULT '0',
  `last_attempt_at` datetime DEFAULT NULL,
  `next_attempt_at` datetime NOT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `run_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conversation_url` varchar(2048) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_failure_code` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `terminal_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_uuid` (`delivery_uuid`),
  UNIQUE KEY `uq_workspace_agent_trigger_delivery` (`message_id`,`agent_id`),
  KEY `idx_workspace_agent_trigger_pending` (`status`,`next_attempt_at`,`id`),
  KEY `fk_workspace_agent_trigger_agent` (`project_id`,`agent_id`),
  CONSTRAINT `fk_workspace_agent_trigger_agent` FOREIGN KEY (`project_id`, `agent_id`) REFERENCES `project_agents` (`project_id`, `agent_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_workspace_agent_trigger_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_workspace_agent_trigger_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `workspaces` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `owner_user_id` bigint unsigned NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workspaces_owner` (`owner_user_id`),
  UNIQUE KEY `uq_workspaces_id_owner` (`id`,`owner_user_id`),
  CONSTRAINT `fk_workspaces_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `acceptance_criteria` mediumtext COLLATE utf8mb4_unicode_ci,
  `status` enum('open','in_progress','in_review','blocked','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `priority` enum('low','normal','high','urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `assignee_participant_id` bigint unsigned DEFAULT NULL,
  `supervising_participant_id` bigint unsigned DEFAULT NULL,
  `created_by_participant_id` bigint unsigned NOT NULL,
  `source_message_id` bigint unsigned DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `blocked_reason` text COLLATE utf8mb4_unicode_ci,
  `completion_summary` text COLLATE utf8mb4_unicode_ci,
  `version` bigint unsigned NOT NULL DEFAULT '1',
  `started_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_tasks_public_id` (`public_id`),
  KEY `idx_project_tasks_project_status` (`project_id`,`status`,`updated_at`),
  KEY `idx_project_tasks_assignee` (`project_id`,`assignee_participant_id`,`status`),
  KEY `idx_project_tasks_supervisor` (`project_id`,`supervising_participant_id`,`status`),
  KEY `idx_project_tasks_due` (`project_id`,`due_at`),
  KEY `idx_project_tasks_source` (`source_message_id`),
  CONSTRAINT `fk_project_tasks_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_tasks_assignee` FOREIGN KEY (`assignee_participant_id`) REFERENCES `project_participants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_project_tasks_supervisor` FOREIGN KEY (`supervising_participant_id`) REFERENCES `project_participants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_project_tasks_creator` FOREIGN KEY (`created_by_participant_id`) REFERENCES `project_participants` (`id`),
  CONSTRAINT `fk_project_tasks_source` FOREIGN KEY (`source_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_task_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `actor_participant_id` bigint unsigned NOT NULL,
  `event_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `metadata_json` json DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project_task_events_task` (`task_id`,`id`),
  KEY `idx_project_task_events_project` (`project_id`,`created_at`,`id`),
  KEY `idx_project_task_events_actor` (`actor_participant_id`,`created_at`),
  CONSTRAINT `fk_project_task_events_task` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_task_events_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_task_events_actor` FOREIGN KEY (`actor_participant_id`) REFERENCES `project_participants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_template_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `status` enum('active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_template_categories_slug` (`slug`),
  KEY `idx_project_template_categories_status_sort` (`status`,`sort_order`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` bigint unsigned NOT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `instructions` mediumtext COLLATE utf8mb4_unicode_ci,
  `origin` enum('system','custom') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'custom',
  `status` enum('active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `version` bigint unsigned NOT NULL DEFAULT '1',
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_templates_public_id` (`public_id`),
  KEY `idx_project_templates_status_name` (`status`,`name`),
  KEY `idx_project_templates_category_status_name` (`category_id`,`status`,`name`),
  KEY `idx_project_templates_created_by` (`created_by_user_id`),
  CONSTRAINT `fk_project_templates_category` FOREIGN KEY (`category_id`) REFERENCES `project_template_categories` (`id`),
  CONSTRAINT `fk_project_templates_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_project_templates_updated_by` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `project_template_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned NOT NULL,
  `preset_key` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_summary` text COLLATE utf8mb4_unicode_ci,
  `role_instructions` mediumtext COLLATE utf8mb4_unicode_ci,
  `supervising_agent_id` bigint unsigned DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_template_agents_key` (`template_id`,`preset_key`),
  KEY `idx_project_template_agents_template` (`template_id`,`sort_order`,`id`),
  KEY `idx_project_template_agents_supervisor` (`supervising_agent_id`),
  CONSTRAINT `fk_project_template_agents_template` FOREIGN KEY (`template_id`) REFERENCES `project_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_template_agents_supervisor` FOREIGN KEY (`supervising_agent_id`) REFERENCES `project_template_agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `syndicatum_installation_identity` (
  `singleton_id` tinyint unsigned NOT NULL,
  `application_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `schema_baseline` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `schema_head` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `baseline_source_commit` char(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `release_source_commit` char(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `package_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `package_format_version` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `installation_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `installed_at` datetime NOT NULL,
  `last_upgrade_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_upgrade_from_version` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_upgrade_to_version` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_upgraded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`singleton_id`),
  UNIQUE KEY `uq_syndicatum_installation_id` (`installation_id`),
  CONSTRAINT `chk_syndicatum_installation_singleton` CHECK (`singleton_id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER `trg_chat_agents_mirror_delete` AFTER DELETE ON `chat_agents` FOR EACH ROW DELETE FROM agents WHERE id = OLD.id;

CREATE TRIGGER `trg_chat_agents_mirror_insert` AFTER INSERT ON `chat_agents` FOR EACH ROW REPLACE INTO agents (id, project_name, description, source_order, token_prefix, token_hash, token_secret_version, claim_prefix, claim_hash, claim_secret_version, claim_expires_at, role, is_active, created_at, updated_at, last_used_at, claimed_at) VALUES (NEW.id, NEW.project_name, NEW.description, NEW.source_order, NEW.token_prefix, NEW.token_hash, NEW.token_secret_version, NEW.claim_prefix, NEW.claim_hash, NEW.claim_secret_version, NEW.claim_expires_at, NEW.role, NEW.is_active, NEW.created_at, NEW.updated_at, NEW.last_used_at, NEW.claimed_at);

CREATE TRIGGER `trg_chat_agents_mirror_update` AFTER UPDATE ON `chat_agents` FOR EACH ROW REPLACE INTO agents (id, project_name, description, source_order, token_prefix, token_hash, token_secret_version, claim_prefix, claim_hash, claim_secret_version, claim_expires_at, role, is_active, created_at, updated_at, last_used_at, claimed_at) VALUES (NEW.id, NEW.project_name, NEW.description, NEW.source_order, NEW.token_prefix, NEW.token_hash, NEW.token_secret_version, NEW.claim_prefix, NEW.claim_hash, NEW.claim_secret_version, NEW.claim_expires_at, NEW.role, NEW.is_active, NEW.created_at, NEW.updated_at, NEW.last_used_at, NEW.claimed_at);

INSERT INTO `system_roles` (`code`, `name`, `created_at`) VALUES
  ('user', 'User', UTC_TIMESTAMP()),
  ('administrator', 'Administrator', UTC_TIMESTAMP());

SET FOREIGN_KEY_CHECKS = @syndicatum_saved_foreign_key_checks;
