<?php

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/ChatRepository.php';

class RequestAuth
{
    private $pdo;
    private $authService;
    private $chatRepository;
    private $identity;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->authService = new AuthService($pdo);
        $this->chatRepository = new ChatRepository($pdo);
    }

    public function identity()
    {
        if ($this->identity !== null) {
            return $this->identity;
        }

        $user = $this->authService->currentUser();
        if ($user) {
            $this->identity = ['kind' => 'human', 'user' => $user];
            return $this->identity;
        }

        $agent = $this->chatRepository->authenticate(Api::bearerToken());
        if ($agent) {
            $this->identity = ['kind' => 'agent', 'agent' => $agent];
            return $this->identity;
        }

        throw new RuntimeException('AUTHENTICATION_REQUIRED');
    }

    public function requireCsrfForHuman(array $identity)
    {
        if ($identity['kind'] !== 'human') {
            return;
        }
        $this->authService->validateCsrf($identity['user'], self::header('X-CSRF-Token'));
    }

    public function projectAccess($projectId, $scope = 'messages:read')
    {
        $projectId = (int) $projectId;
        if ($projectId < 1) {
            throw new InvalidArgumentException('A valid project_id is required.');
        }

        $identity = $this->identity();
        if ($identity['kind'] === 'human') {
            $statement = $this->pdo->prepare(
                "SELECT p.id AS project_id, p.name AS project_name, p.slug, p.status AS project_status,
                        pm.role, pp.id AS participant_id, pp.status AS participant_status
                 FROM projects p
                 JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active'
                 JOIN project_participants pp ON pp.project_id = p.id AND pp.user_id = pm.user_id
                    AND pp.kind = 'human' AND pp.status = 'active'
                 WHERE p.id = ? LIMIT 1"
            );
            $statement->execute([$identity['user']['id'], $projectId]);
            $access = $statement->fetch();
            if (!$access) {
                throw new RuntimeException('PROJECT_NOT_FOUND');
            }
            if ($scope === 'messages:write' && $access['role'] === 'viewer') {
                throw new RuntimeException('PROJECT_WRITE_FORBIDDEN');
            }
            $access['identity'] = $identity;
            $access['participant_id'] = (int) $access['participant_id'];
            return $access;
        }

        $agentId = (int) $identity['agent']['id'];
        $statement = $this->pdo->prepare(
            "SELECT p.id AS project_id, p.name AS project_name, p.slug, p.status AS project_status,
                    pa.status AS agent_status, pp.id AS participant_id, pp.status AS participant_status
             FROM projects p
             JOIN project_agents pa ON pa.project_id = p.id AND pa.agent_id = ? AND pa.status = 'active'
             JOIN project_participants pp ON pp.project_id = p.id AND pp.agent_id = pa.agent_id
                AND pp.kind = 'agent' AND pp.status = 'active'
             WHERE p.id = ? LIMIT 1"
        );
        $statement->execute([$agentId, $projectId]);
        $access = $statement->fetch();
        if (!$access || !$this->agentHasScope($agentId, $scope)) {
            throw new RuntimeException('PROJECT_NOT_FOUND');
        }
        $access['identity'] = $identity;
        $access['role'] = 'agent';
        $access['participant_id'] = (int) $access['participant_id'];
        return $access;
    }

    public function projects()
    {
        $identity = $this->identity();
        if ($identity['kind'] === 'human') {
            $statement = $this->pdo->prepare(
                "SELECT p.id, p.workspace_id, p.owner_user_id, p.name, p.slug, p.description, p.status,
                        pm.role, pp.id AS participant_id,
                        CASE WHEN p.owner_user_id = ? THEN 'owned' ELSE 'shared' END AS relationship
                 FROM project_members pm
                 JOIN projects p ON p.id = pm.project_id
                 JOIN project_participants pp ON pp.project_id = p.id AND pp.user_id = pm.user_id
                    AND pp.kind = 'human' AND pp.status = 'active'
                 WHERE pm.user_id = ? AND pm.status = 'active'
                 ORDER BY p.name, p.id"
            );
            $statement->execute([$identity['user']['id'], $identity['user']['id']]);
        } else {
            $statement = $this->pdo->prepare(
                "SELECT p.id, p.workspace_id, p.owner_user_id, p.name, p.slug, p.description, p.status,
                        'agent' AS role, pp.id AS participant_id, 'assigned' AS relationship
                 FROM project_agents pa
                 JOIN projects p ON p.id = pa.project_id
                 JOIN project_participants pp ON pp.project_id = p.id AND pp.agent_id = pa.agent_id
                    AND pp.kind = 'agent' AND pp.status = 'active'
                 WHERE pa.agent_id = ? AND pa.status = 'active' ORDER BY p.name, p.id"
            );
            $statement->execute([(int) $identity['agent']['id']]);
        }

        return array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'workspace_id' => (int) $row['workspace_id'],
                'owner_user_id' => (int) $row['owner_user_id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => $row['description'],
                'status' => $row['status'],
                'role' => $row['role'],
                'participant_id' => (int) $row['participant_id'],
                'relationship' => $row['relationship'],
            ];
        }, $statement->fetchAll());
    }

    private function agentHasScope($agentId, $scope)
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM agent_credential_scopes WHERE agent_id = ?');
        $count->execute([$agentId]);
        if ((int) $count->fetchColumn() === 0) {
            return in_array($scope, ['messages:read', 'messages:write', 'messages:acknowledge', 'profile:read'], true);
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM agent_credential_scopes WHERE agent_id = ? AND scope = ?');
        $statement->execute([$agentId, $scope]);
        return (int) $statement->fetchColumn() > 0;
    }

    private static function header($name)
    {
        $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$serverName]) ? trim((string) $_SERVER[$serverName]) : '';
    }
}
