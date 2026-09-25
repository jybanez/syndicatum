<?php

require_once __DIR__ . '/Db.php';

class ProjectTemplateService
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function listCategories()
    {
        $rows = $this->pdo->query(
            "SELECT id, slug, name, description, sort_order
             FROM project_template_categories WHERE status = 'active' ORDER BY sort_order, name, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function ($row) {
            return ['id' => (int) $row['id'], 'slug' => $row['slug'], 'name' => $row['name'],
                'description' => $row['description'], 'sort_order' => (int) $row['sort_order']];
        }, $rows);
    }

    public function listTemplates($includeArchived = false)
    {
        $sql = "SELECT t.*, category.slug AS category_slug, category.name AS category_name,
                       category.description AS category_description, category.sort_order AS category_sort_order,
                       creator.display_name AS created_by_display_name, updater.display_name AS updated_by_display_name
                FROM project_templates t
                JOIN project_template_categories category ON category.id = t.category_id
                LEFT JOIN users creator ON creator.id = t.created_by_user_id
                LEFT JOIN users updater ON updater.id = t.updated_by_user_id";
        if (!$includeArchived) { $sql .= " WHERE t.status = 'active'"; }
        $sql .= ' ORDER BY t.status ASC, t.name ASC, t.id ASC';
        $templates = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (!$templates) { return []; }
        $ids = array_map(function ($row) { return (int) $row['id']; }, $templates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $agents = $this->pdo->prepare(
            "SELECT a.*, supervisor.display_name AS supervising_agent_name
             FROM project_template_agents a
             LEFT JOIN project_template_agents supervisor ON supervisor.id = a.supervising_agent_id
             WHERE a.template_id IN ($placeholders)
             ORDER BY a.template_id, a.sort_order, a.id"
        );
        $agents->execute($ids);
        $byTemplate = [];
        foreach ($agents->fetchAll(PDO::FETCH_ASSOC) as $agent) {
            $byTemplate[(int) $agent['template_id']][] = $this->publicAgent($agent);
        }
        return array_map(function ($row) use ($byTemplate) {
            return $this->publicTemplate($row, isset($byTemplate[(int) $row['id']]) ? $byTemplate[(int) $row['id']] : []);
        }, $templates);
    }

    public function activeTemplate($templateId, $version = null)
    {
        foreach ($this->listTemplates(false) as $template) {
            if ((int) $template['id'] !== (int) $templateId) { continue; }
            if ($version !== null && (int) $template['version'] !== (int) $version) {
                throw new RuntimeException('TEMPLATE_VERSION_CONFLICT');
            }
            return $template;
        }
        throw new RuntimeException('TEMPLATE_NOT_FOUND');
    }

    public function createTemplate($userId, array $input)
    {
        $values = $this->validateTemplate($input);
        $now = Db::now();
        $statement = $this->pdo->prepare(
            "INSERT INTO project_templates
             (public_id, category_id, name, description, instructions, status, version, created_by_user_id, updated_by_user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'active', 1, ?, ?, ?, ?)"
        );
        $statement->execute([Db::uuidV4(), $values['category_id'], $values['name'], $values['description'], $values['instructions'], $userId, $userId, $now, $now]);
        return $this->findTemplate((int) $this->pdo->lastInsertId());
    }

    public function updateTemplate($templateId, $userId, array $input)
    {
        $this->assertCustomTemplate($templateId);
        $values = $this->validateTemplate($input);
        $version = $this->requiredVersion($input);
        $statement = $this->pdo->prepare(
            "UPDATE project_templates SET category_id = ?, name = ?, description = ?, instructions = ?, updated_by_user_id = ?,
                    updated_at = ?, version = version + 1
             WHERE id = ? AND status = 'active' AND version = ?"
        );
        $statement->execute([$values['category_id'], $values['name'], $values['description'], $values['instructions'], $userId, Db::now(), $templateId, $version]);
        if ($statement->rowCount() !== 1) { $this->throwMissingOrConflict($templateId); }
        return $this->findTemplate($templateId);
    }

    public function archiveTemplate($templateId, $userId, $version)
    {
        $this->assertCustomTemplate($templateId);
        $statement = $this->pdo->prepare(
            "UPDATE project_templates SET status = 'archived', archived_at = ?, updated_at = ?, updated_by_user_id = ?, version = version + 1
             WHERE id = ? AND status = 'active' AND version = ?"
        );
        $now = Db::now();
        $statement->execute([$now, $now, $userId, $templateId, (int) $version]);
        if ($statement->rowCount() !== 1) { $this->throwMissingOrConflict($templateId); }
        return $this->findTemplate($templateId);
    }

    public function createAgent($templateId, $userId, array $input)
    {
        $values = $this->validateAgent($templateId, $input);
        $this->pdo->beginTransaction();
        try {
            $template = $this->lockActiveTemplate($templateId, $this->requiredVersion($input));
            $now = Db::now();
            $statement = $this->pdo->prepare(
                'INSERT INTO project_template_agents
                 (template_id, display_name, provider, role_title, role_summary, role_instructions, supervising_agent_id, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$templateId, $values['display_name'], $values['provider'], $values['role_title'], $values['role_summary'], $values['role_instructions'], $values['supervising_agent_id'], $values['sort_order'], $now, $now]);
            $this->touchTemplate($templateId, $userId);
            $this->pdo->commit();
            return $this->findTemplate($templateId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function updateAgent($templateId, $agentId, $userId, array $input)
    {
        $values = $this->validateAgent($templateId, $input, $agentId);
        $this->pdo->beginTransaction();
        try {
            $this->lockActiveTemplate($templateId, $this->requiredVersion($input));
            $statement = $this->pdo->prepare(
                'UPDATE project_template_agents SET display_name = ?, provider = ?, role_title = ?, role_summary = ?,
                 role_instructions = ?, supervising_agent_id = ?, sort_order = ?, updated_at = ? WHERE id = ? AND template_id = ?'
            );
            $statement->execute([$values['display_name'], $values['provider'], $values['role_title'], $values['role_summary'], $values['role_instructions'], $values['supervising_agent_id'], $values['sort_order'], Db::now(), $agentId, $templateId]);
            if ($statement->rowCount() !== 1) {
                $exists = $this->pdo->prepare('SELECT id FROM project_template_agents WHERE id = ? AND template_id = ?');
                $exists->execute([$agentId, $templateId]);
                if ($exists->fetchColumn() === false) { throw new RuntimeException('TEMPLATE_AGENT_NOT_FOUND'); }
            }
            $this->touchTemplate($templateId, $userId);
            $this->pdo->commit();
            return $this->findTemplate($templateId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function deleteAgent($templateId, $agentId, $userId, $version)
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockActiveTemplate($templateId, (int) $version);
            $statement = $this->pdo->prepare('DELETE FROM project_template_agents WHERE id = ? AND template_id = ?');
            $statement->execute([$agentId, $templateId]);
            if ($statement->rowCount() !== 1) { throw new RuntimeException('TEMPLATE_AGENT_NOT_FOUND'); }
            $this->touchTemplate($templateId, $userId);
            $this->pdo->commit();
            return $this->findTemplate($templateId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    private function findTemplate($templateId)
    {
        foreach ($this->listTemplates(true) as $template) {
            if ((int) $template['id'] === (int) $templateId) { return $template; }
        }
        throw new RuntimeException('TEMPLATE_NOT_FOUND');
    }

    private function validateTemplate(array $input)
    {
        $categoryId = isset($input['category_id']) ? (int) $input['category_id'] : 0;
        $name = trim(isset($input['name']) ? (string) $input['name'] : '');
        $description = trim(isset($input['description']) ? (string) $input['description'] : '');
        $instructions = trim(isset($input['instructions']) ? (string) $input['instructions'] : '');
        if ($name === '' || $this->length($name) > 160) { throw new InvalidArgumentException('Template name is required and must not exceed 160 characters.'); }
        if ($categoryId < 1) { throw new InvalidArgumentException('Template category is required.'); }
        $category = $this->pdo->prepare("SELECT id FROM project_template_categories WHERE id = ? AND status = 'active'");
        $category->execute([$categoryId]);
        if ($category->fetchColumn() === false) { throw new InvalidArgumentException('Select an active template category.'); }
        if ($this->length($description) > 10000) { throw new InvalidArgumentException('Description must not exceed 10,000 characters.'); }
        if ($this->length($instructions) > 50000) { throw new InvalidArgumentException('Operating instructions must not exceed 50,000 characters.'); }
        return ['category_id' => $categoryId, 'name' => $name, 'description' => $description === '' ? null : $description, 'instructions' => $instructions === '' ? null : $instructions];
    }

    private function validateAgent($templateId, array $input, $agentId = null)
    {
        $displayName = trim(isset($input['display_name']) ? (string) $input['display_name'] : '');
        $provider = strtolower(trim(isset($input['provider']) ? (string) $input['provider'] : ''));
        $roleTitle = trim(isset($input['role_title']) ? (string) $input['role_title'] : '');
        $roleSummary = trim(isset($input['role_summary']) ? (string) $input['role_summary'] : '');
        $roleInstructions = trim(isset($input['role_instructions']) ? (string) $input['role_instructions'] : '');
        $supervisor = isset($input['supervising_agent_id']) && (string) $input['supervising_agent_id'] !== '' ? (int) $input['supervising_agent_id'] : null;
        if ($displayName === '' || $this->length($displayName) > 120) { throw new InvalidArgumentException('Agent display name is required and must not exceed 120 characters.'); }
        if (!in_array($provider, ['unassigned', 'codex', 'chatgpt', 'gemini'], true)) { throw new InvalidArgumentException('Provider must be selected during setup, Codex, ChatGPT, or Gemini.'); }
        if ($roleTitle === '' || $this->length($roleTitle) > 160) { throw new InvalidArgumentException('Role title is required and must not exceed 160 characters.'); }
        if ($this->length($roleSummary) > 10000 || $this->length($roleInstructions) > 50000) { throw new InvalidArgumentException('Agent role details exceed the supported length.'); }
        if ($supervisor !== null) {
            if ($agentId !== null && $supervisor === (int) $agentId) { throw new InvalidArgumentException('An agent preset cannot report to itself.'); }
            $check = $this->pdo->prepare('SELECT id FROM project_template_agents WHERE id = ? AND template_id = ?');
            $check->execute([$supervisor, $templateId]);
            if ($check->fetchColumn() === false) { throw new InvalidArgumentException('The selected supervising agent preset does not belong to this template.'); }
        }
        return ['display_name' => $displayName, 'provider' => $provider, 'role_title' => $roleTitle, 'role_summary' => $roleSummary === '' ? null : $roleSummary, 'role_instructions' => $roleInstructions === '' ? null : $roleInstructions, 'supervising_agent_id' => $supervisor, 'sort_order' => max(0, isset($input['sort_order']) ? (int) $input['sort_order'] : 0)];
    }

    private function requiredVersion(array $input)
    {
        $version = isset($input['version']) ? (int) $input['version'] : 0;
        if ($version < 1) { throw new InvalidArgumentException('The current template version is required.'); }
        return $version;
    }

    private function lockActiveTemplate($templateId, $version)
    {
        $statement = $this->pdo->prepare("SELECT id, version, origin FROM project_templates WHERE id = ? AND status = 'active' FOR UPDATE");
        $statement->execute([$templateId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('TEMPLATE_NOT_FOUND'); }
        if ($row['origin'] === 'system') { throw new RuntimeException('TEMPLATE_SYSTEM_MANAGED'); }
        if ((int) $row['version'] !== (int) $version) { throw new RuntimeException('TEMPLATE_VERSION_CONFLICT'); }
        return $row;
    }

    private function touchTemplate($templateId, $userId)
    {
        $this->pdo->prepare('UPDATE project_templates SET version = version + 1, updated_by_user_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$userId, Db::now(), $templateId]);
    }

    private function throwMissingOrConflict($templateId)
    {
        $statement = $this->pdo->prepare('SELECT id FROM project_templates WHERE id = ?');
        $statement->execute([$templateId]);
        if ($statement->fetchColumn() === false) { throw new RuntimeException('TEMPLATE_NOT_FOUND'); }
        throw new RuntimeException('TEMPLATE_VERSION_CONFLICT');
    }

    private function assertCustomTemplate($templateId)
    {
        $statement = $this->pdo->prepare('SELECT origin FROM project_templates WHERE id = ?');
        $statement->execute([$templateId]);
        $origin = $statement->fetchColumn();
        if ($origin === false) { throw new RuntimeException('TEMPLATE_NOT_FOUND'); }
        if ($origin === 'system') { throw new RuntimeException('TEMPLATE_SYSTEM_MANAGED'); }
    }

    private function publicTemplate(array $row, array $agents)
    {
        return ['id' => (int) $row['id'], 'public_id' => $row['public_id'], 'category_id' => (int) $row['category_id'],
            'category' => ['id' => (int) $row['category_id'], 'slug' => $row['category_slug'], 'name' => $row['category_name'],
                'description' => $row['category_description'], 'sort_order' => (int) $row['category_sort_order']],
            'name' => $row['name'], 'description' => $row['description'], 'instructions' => $row['instructions'], 'origin' => $row['origin'], 'status' => $row['status'], 'version' => (int) $row['version'], 'created_by_display_name' => $row['created_by_display_name'] ?: 'Syndicatum', 'updated_by_display_name' => $row['updated_by_display_name'] ?: 'Syndicatum', 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'archived_at' => $row['archived_at'], 'agents' => $agents];
    }

    private function publicAgent(array $row)
    {
        return ['id' => (int) $row['id'], 'template_id' => (int) $row['template_id'], 'display_name' => $row['display_name'], 'provider' => $row['provider'], 'role_title' => $row['role_title'], 'role_summary' => $row['role_summary'], 'role_instructions' => $row['role_instructions'], 'supervising_agent_id' => $row['supervising_agent_id'] === null ? null : (int) $row['supervising_agent_id'], 'supervising_agent_name' => $row['supervising_agent_name'], 'sort_order' => (int) $row['sort_order']];
    }

    private function length($value) { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
}
