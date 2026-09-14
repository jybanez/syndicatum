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
        ], [
            'code' => 'chatgpt',
            'display_name' => 'ChatGPT',
            'reference_label' => 'ChatGPT discussion URL',
            'reference_placeholder' => 'https://chatgpt.com/c/...',
            'reference_help' => 'Required. The Syndicatum browser companion uses this URL to notify the existing ChatGPT discussion.',
            'working_directory_supported' => false,
            'working_directory_label' => 'Working directory hint',
            'working_directory_help' => 'Not used by the ChatGPT plugin.',
            'proactive_activation' => true,
            'activation_kind' => 'browser_companion',
        ], [
            'code' => 'gemini',
            'display_name' => 'Gemini',
            'reference_label' => 'Gemini discussion URL',
            'reference_placeholder' => 'https://gemini.google.com/app/...',
            'reference_help' => 'Required. The Syndicatum browser companion uses this URL to notify the existing Gemini discussion.',
            'working_directory_supported' => false,
            'working_directory_label' => 'Working directory hint',
            'working_directory_help' => 'Not used by the Gemini browser companion.',
            'proactive_activation' => true,
            'activation_kind' => 'browser_companion',
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
        if ($definition['code'] === 'chatgpt') {
            $parts = parse_url($value);
            $host = strtolower((string) (isset($parts['host']) ? $parts['host'] : ''));
            $path = rtrim((string) (isset($parts['path']) ? $parts['path'] : ''), '/');
            if ((isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https' || $host !== 'chatgpt.com'
                || !preg_match('#(?:^|/)c/[A-Za-z0-9_-]+$#', $path)) {
                throw new InvalidArgumentException('Enter a valid ChatGPT discussion URL such as https://chatgpt.com/c/...');
            }
            $canonical = 'https://chatgpt.com' . $path;
            if (strlen($canonical) > 255) { throw new InvalidArgumentException('The ChatGPT discussion URL is too long.'); }
            return [
                'provider' => 'chatgpt',
                'discussion_id' => $canonical,
                'canonical_reference' => $canonical,
            ];
        }
        if ($definition['code'] === 'gemini') {
            $parts = parse_url($value);
            $host = strtolower((string) (isset($parts['host']) ? $parts['host'] : ''));
            $path = rtrim((string) (isset($parts['path']) ? $parts['path'] : ''), '/');
            if ((isset($parts['scheme']) ? $parts['scheme'] : '') !== 'https' || $host !== 'gemini.google.com'
                || !preg_match('#^/app/[A-Za-z0-9_-]+$#', $path)) {
                throw new InvalidArgumentException('Enter a valid Gemini discussion URL such as https://gemini.google.com/app/...');
            }
            $canonical = 'https://gemini.google.com' . $path;
            if (strlen($canonical) > 255) { throw new InvalidArgumentException('The Gemini discussion URL is too long.'); }
            return [
                'provider' => 'gemini',
                'discussion_id' => $canonical,
                'canonical_reference' => $canonical,
            ];
        }
        throw new InvalidArgumentException('The selected discussion provider is not supported.');
    }

    public function fromStoredId($provider, $discussionId)
    {
        $definition = $this->definition($provider);
        $value = trim((string) $discussionId);
        if (in_array($definition['code'], ['chatgpt', 'gemini'], true)) { return $this->normalize($definition['code'], $value); }
        if ($value === '' || strlen($value) > 255 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value)) {
            throw new InvalidArgumentException('The stored discussion ID is invalid.');
        }
        return $this->normalize($definition['code'], $definition['code'] === 'codex' ? 'codex://threads/' . $value : $value);
    }
}
