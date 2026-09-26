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

        $templateData = $data;
        $templateData['brand_logo_url'] = $this->brandLogoUrl($data['invitation_url']);

        $text = <<<'TEXT'
{{inviter_name}} invited you to join {{project_name}} in {{installation_name}} as {{role_label}}.

View invitation:
{{invitation_url}}

If the invitation link will not open, sign in to {{installation_name}} and use this invitation token:
{{invitation_token}}

This invitation expires {{expires_at}}.

Check the inviter and installation before continuing. If you were not expecting this invitation, you can ignore this email. Keep the invitation link and token private.
TEXT;
        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>Invitation to {{project_name}}</title>
  <style>
    @media only screen and (max-width: 520px) {
      .email-frame { width: 100% !important; }
      .email-pad { padding: 24px 20px !important; }
      .detail-label, .detail-value { display: block !important; width: auto !important; }
      .detail-label { padding-bottom: 3px !important; }
      .action-cell, .action-link { display: block !important; width: auto !important; text-align: center !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f3f6fb;color:#172033;font-family:Arial,Helvetica,sans-serif;line-height:1.5">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{{inviter_name}} invited you as {{role_label}}. View the invitation.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f6fb">
    <tr>
      <td align="center" style="padding:28px 14px">
        <table role="presentation" class="email-frame" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border-top:5px solid #2563eb">
          <tr>
            <td class="email-pad" style="padding:34px 36px">
              <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 28px">
                <tr>
                  <td style="padding:0 11px 0 0;vertical-align:middle"><img src="{{brand_logo_url}}" width="48" height="48" alt="" style="display:block;width:48px;height:48px;border:0"></td>
                  <td style="vertical-align:middle;color:#172033;font-size:20px;line-height:24px;font-weight:bold">Syndicatum</td>
                </tr>
              </table>

              <div style="margin:0 0 8px;color:#2563eb;font-size:12px;line-height:17px;font-weight:bold;letter-spacing:1.3px">PROJECT INVITATION</div>
              <h1 style="margin:0 0 14px;color:#172033;font-size:27px;line-height:33px;font-weight:bold">{{project_name}}</h1>
              <p style="margin:0 0 20px;color:#39445a;font-size:16px;line-height:25px">You’ve been invited to join this project in Syndicatum.</p>

              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 24px;background:#f5f7fb;border-top:1px solid #e2e7f0;border-bottom:1px solid #e2e7f0">
                <tr>
                  <td class="detail-label" width="120" style="width:120px;padding:13px 16px;color:#5c6880;font-size:14px;line-height:21px;border-bottom:1px solid #e2e7f0;vertical-align:top">Invited by</td>
                  <td class="detail-value" style="padding:13px 16px;color:#172033;font-size:16px;line-height:23px;font-weight:bold;border-bottom:1px solid #e2e7f0;vertical-align:top;word-break:break-word">{{inviter_name}}</td>
                </tr>
                <tr>
                  <td class="detail-label" width="120" style="width:120px;padding:13px 16px;color:#5c6880;font-size:14px;line-height:21px;border-bottom:1px solid #e2e7f0;vertical-align:top">Installation</td>
                  <td class="detail-value" style="padding:13px 16px;color:#172033;font-size:16px;line-height:23px;font-weight:bold;border-bottom:1px solid #e2e7f0;vertical-align:top;word-break:break-word">{{installation_name}}</td>
                </tr>
                <tr>
                  <td class="detail-label" width="120" style="width:120px;padding:13px 16px;color:#5c6880;font-size:14px;line-height:21px;vertical-align:top">Your role</td>
                  <td class="detail-value" style="padding:13px 16px;color:#172033;font-size:16px;line-height:23px;font-weight:bold;vertical-align:top;word-break:break-word">{{role_label}}</td>
                </tr>
              </table>

              <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px">
                <tr>
                  <td class="action-cell" bgcolor="#2563eb" style="border-radius:7px;background:#2563eb">
                    <a class="action-link" href="{{invitation_url}}" style="display:inline-block;padding:14px 22px;color:#ffffff;font-size:16px;line-height:20px;font-weight:bold;text-decoration:none">View invitation</a>
                  </td>
                </tr>
              </table>

              <div style="margin:0;padding:14px 16px;background:#f8fafc;border-left:3px solid #2563eb;color:#4d5970;font-size:14px;line-height:21px">
                <strong style="color:#172033">Expires {{expires_at}}.</strong><br>
                Check the inviter and installation before continuing. If this was unexpected, you can ignore this email. Keep the invitation link and token private.
              </div>

              <div style="margin:25px 0 0;padding:20px 0 0;border-top:1px solid #dce3ef;color:#5c6880;font-size:14px;line-height:21px;word-break:break-word">
                <a href="{{invitation_url}}" style="color:#2563eb;text-decoration:underline">Open invitation link</a><br>
                If the link will not open, sign in to {{installation_name}} and use this invitation token:
                <div style="margin-top:8px;padding:8px 10px;background:#eef1f6;color:#39445a;font-family:Consolas,'Courier New',monospace;font-size:13px;line-height:19px;word-break:break-all">{{invitation_token}}</div>
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body></html>
HTML;

        return [
            'template' => $template,
            'template_version' => 2,
            'subject' => 'Invitation to ' . (string) $data['project_name'],
            'text' => $this->replace($text, $templateData, false),
            'html' => $this->replace($html, $templateData, true),
        ];
    }

    private function brandLogoUrl($invitationUrl)
    {
        $parts = parse_url((string) $invitationUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Email template invitation URL is invalid.');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) { $origin .= ':' . (int) $parts['port']; }
        return $origin . '/assets/brand/png/color/syndicatum-128.png';
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
