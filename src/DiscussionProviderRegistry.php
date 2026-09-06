<?php

class DiscussionProviderRegistry
{
    public function definitions()
    {
        return [[
            'code' => 'codex',
            'display_name' => 'Codex',
            'reference_label' => 'Codex discussion deeplink',
            'reference_placeholder' => 'codex://threads/01abc...',
            'reference_help' => 'In Codex, open the discussion menu and choose Copy → Copy deeplink.',
            'working_directory_supported' => true,
            'working_directory_label' => 'Working directory hint',
            'working_directory_help' => 'Optional. Connectors can activate the existing discussion without requiring the same folder path on every device.',
            'proactive_activation' => true,
        ]];
    }

    public function definition($provider)
    {
        $code = strtolower(trim((string) $provider));
        foreach ($this->definitions() as $definition) {
            if ($definition['code'] === $code) { return $definition; }
        }
        throw new InvalidArgumentException('The selected discussion provider is not supported.');
    }

    public function normalize($provider, $reference)
    {
        $definition = $this->definition($provider);
        $value = trim((string) $reference);
        if ($definition['code'] === 'codex') {
            if (!preg_match('#^codex://threads/([A-Za-z0-9][A-Za-z0-9._:-]*)/?$#', $value, $matches)) {
                throw new InvalidArgumentException('Enter a valid Codex discussion deeplink such as codex://threads/01abc...');
            }
            if (strlen($matches[1]) > 255) { throw new InvalidArgumentException('The Codex discussion ID is too long.'); }
            return [
                'provider' => 'codex',
                'discussion_id' => $matches[1],
                'canonical_reference' => 'codex://threads/' . $matches[1],
            ];
        }
        throw new InvalidArgumentException('The selected discussion provider is not supported.');
    }

    public function fromStoredId($provider, $discussionId)
    {
        $definition = $this->definition($provider);
        $value = trim((string) $discussionId);
        if ($value === '' || strlen($value) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value)) {
            throw new InvalidArgumentException('The stored discussion ID is invalid.');
        }
        return $this->normalize($definition['code'], $definition['code'] === 'codex' ? 'codex://threads/' . $value : $value);
    }
}
