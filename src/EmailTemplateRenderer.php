<?php

final class EmailTemplateRenderer
{
    public function render($template, array $data)
    {
        if ($template !== 'project_invitation') {
            throw new InvalidArgumentException('Unknown email template.');
        }

        $required = ['installation_name', 'project_name', 'inviter_name', 'role_label', 'expires_at', 'invitation_url', 'invitation_token'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data) || trim((string) $data[$key]) === '') {
                throw new InvalidArgumentException('Email template data is incomplete: ' . $key . '.');
            }
        }

        $text = <<<'TEXT'
{{inviter_name}} invited you to join {{project_name}} in {{installation_name}} as {{role_label}}.

Open the invitation:
{{invitation_url}}

If the link cannot be opened, sign in to {{installation_name}} and use this invitation token:
{{invitation_token}}

This invitation expires {{expires_at}}. If you were not expecting it, you can ignore this email.
TEXT;
        $html = <<<'HTML'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Project invitation</title></head>
<body style="margin:0;padding:24px;background:#f5f7fb;color:#172033;font-family:Arial,sans-serif;line-height:1.5">
  <main style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #d9e0ec;border-radius:12px;padding:28px">
    <h1 style="margin:0 0 16px;font-size:22px">You’re invited to {{project_name}}</h1>
    <p>{{inviter_name}} invited you to join <strong>{{project_name}}</strong> in {{installation_name}} as <strong>{{role_label}}</strong>.</p>
    <p style="margin:24px 0"><a href="{{invitation_url}}" style="display:inline-block;padding:11px 18px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none">Open in Syndicatum</a></p>
    <p style="font-size:13px;color:#5c6880">If the button cannot be opened, sign in and use invitation token <code>{{invitation_token}}</code>.</p>
    <p style="font-size:13px;color:#5c6880">This invitation expires {{expires_at}}. If you were not expecting it, you can ignore this email.</p>
  </main>
</body></html>
HTML;

        return [
            'template' => $template,
            'template_version' => 1,
            'subject' => 'Invitation to ' . (string) $data['project_name'],
            'text' => $this->replace($text, $data, false),
            'html' => $this->replace($html, $data, true),
        ];
    }

    private function replace($template, array $data, $escape)
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            $text = (string) $value;
            $replacements['{{' . $key . '}}'] = $escape ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
        }
        $result = strtr($template, $replacements);
        if (preg_match('/\{\{[a-z0-9_]+\}\}/i', $result)) {
            throw new InvalidArgumentException('Email template contains an unresolved placeholder.');
        }
        return $result;
    }
}
