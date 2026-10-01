# Development checks

> **Compatibility naming:** The production database name below is an existing installation identifier, not product branding. See [Terminology and compatibility](terminology.md).

Run these commands from the repository root.

## Tests

Run the core backend security and API integration suites with PHP 8.2:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe tests\run.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\migrations.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\expansion.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\project-api.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\realtime.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\account-sso.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\google-sso.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\registration.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\account-profile.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\surfaces.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\avatar-webhooks.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\agent-activation.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\workspace-agent-triggers.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\project-tasks.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\project-status.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\project-plan.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\responsibility-events.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\integration-connections.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\email-notifications.php
C:\wamp64\bin\php\php8.2.29\php.exe tests\package-contract.php
python tests\openapi-message-contract.py --php C:\wamp64\bin\php\php8.2.29\php.exe
```

This list covers the primary local product contracts but is not the complete CI
inventory. [`.github/workflows/contract-ci.yml`](../.github/workflows/contract-ci.yml)
is authoritative for release-candidate, portability, security, baseline, backup,
browser-adapter, and clean-environment acceptance checks.

The suite creates a uniquely named `syndicatum_test_*` MySQL database, starts a PHP server on an ephemeral loopback port, and removes the test database during guarded cleanup. It does not use or modify the production `pbb_agentchat` database.
