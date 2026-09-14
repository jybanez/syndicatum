<?php

$columns = 'id, project_name, description, source_order, token_prefix, token_hash, token_secret_version, claim_prefix, claim_hash, claim_secret_version, claim_expires_at, role, is_active, created_at, updated_at, last_used_at, claimed_at';
$values = 'NEW.id, NEW.project_name, NEW.description, NEW.source_order, NEW.token_prefix, NEW.token_hash, NEW.token_secret_version, NEW.claim_prefix, NEW.claim_hash, NEW.claim_secret_version, NEW.claim_expires_at, NEW.role, NEW.is_active, NEW.created_at, NEW.updated_at, NEW.last_used_at, NEW.claimed_at';

return [
    'version' => '202609110001_canonical_agents',
    'description' => 'Create a synchronized canonical agent identity mirror for staged legacy retirement',
    'statements' => [
        'CREATE TABLE agents LIKE chat_agents',
        'INSERT INTO agents SELECT * FROM chat_agents',
        "CREATE TRIGGER trg_chat_agents_mirror_insert AFTER INSERT ON chat_agents FOR EACH ROW REPLACE INTO agents ($columns) VALUES ($values)",
        "CREATE TRIGGER trg_chat_agents_mirror_update AFTER UPDATE ON chat_agents FOR EACH ROW REPLACE INTO agents ($columns) VALUES ($values)",
        'CREATE TRIGGER trg_chat_agents_mirror_delete AFTER DELETE ON chat_agents FOR EACH ROW DELETE FROM agents WHERE id = OLD.id',
    ],
];
