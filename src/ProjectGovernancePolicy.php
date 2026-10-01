<?php

/** Immutable operating rules that apply to every Syndicatum project. */
class ProjectGovernancePolicy
{
    const VERSION = 1;

    const INSTRUCTIONS = 'The project owner sets priorities and makes final decisions. Participants must work within their assigned roles and authorization boundaries, preserve security and auditability, validate material work before reporting completion, and escalate unclear, conflicting, destructive, or irreversible decisions to the project owner.';

    public static function current()
    {
        return [
            'version' => self::VERSION,
            'instructions' => self::INSTRUCTIONS,
            'immutable' => true,
        ];
    }

    public static function effectiveInstructions($projectInstructions, $googleDriveUrl = null)
    {
        $projectInstructions = trim((string) $projectInstructions);
        $googleDriveUrl = trim((string) $googleDriveUrl);
        $effective = "Governance baseline (version " . self::VERSION . ")\n\n" . self::INSTRUCTIONS;
        if ($projectInstructions !== '') {
            $effective .= "\n\nProject-specific operating instructions\n\n" . $projectInstructions;
        }
        if ($googleDriveUrl !== '') {
            $effective .= "\n\nProject shared storage\n\n"
                . "Use the project's Google Drive folder for files generated for this project when your current environment has authorized access: "
                . $googleDriveUrl . "\n\n"
                . "Follow the project-specific and role-specific instructions for file organization. Do not change sharing permissions or claim an upload succeeded unless it is confirmed. If the folder is unavailable, report the limitation and preserve the file for an authorized handoff.";
        }
        return $effective;
    }
}
