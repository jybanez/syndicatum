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

    public static function effectiveInstructions($projectInstructions)
    {
        $projectInstructions = trim((string) $projectInstructions);
        $effective = "Governance baseline (version " . self::VERSION . ")\n\n" . self::INSTRUCTIONS;
        if ($projectInstructions !== '') {
            $effective .= "\n\nProject-specific operating instructions\n\n" . $projectInstructions;
        }
        return $effective;
    }
}
