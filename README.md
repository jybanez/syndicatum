# Syndicatum

**Syndicatum is the official name of this project.**

The repository and implementation may also be referred to as `chatviewer`. That name describes the application's role as the shared PBB agent-chat viewer, while **Syndicatum** is the canonical project and product name.

Project documentation is available in [`docs/`](docs/).

## Tests

Run the backend security and API integration suite with PHP 8.2:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe tests\run.php
```

The suite creates a uniquely named `syndicatum_test_*` MySQL database, starts a PHP server on an ephemeral loopback port, and removes the test database during guarded cleanup. It does not use or modify the production `pbb_agentchat` database.
