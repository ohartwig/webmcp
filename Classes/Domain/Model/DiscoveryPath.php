<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

/**
 * Paths of the discovery documents that more than one layer names: the
 * middleware serves them, the page head and the Link header point at them.
 * Kept in one place so that what is advertised and what is served cannot drift.
 */
final class DiscoveryPath
{
    /**
     * The ARD manifest (ARD v0.91 §5.1). The predecessor name
     * `/.well-known/ai-catalog.json` is served as well, but only this one is
     * advertised.
     */
    public const string ARD = '/.well-known/ard.json';

    public const string ARD_LEGACY = '/.well-known/ai-catalog.json';

    /**
     * Agent Skills Discovery v0.2.0: the index every publisher must provide.
     */
    public const string AGENT_SKILLS_INDEX = '/.well-known/agent-skills/index.json';

    /**
     * Agent Skills Discovery v0.2.0: the conventional location of a
     * `skill-md` artifact, `{name}` being the skill name.
     */
    public const string AGENT_SKILL_MD = '/.well-known/agent-skills/%s/SKILL.md';

    private function __construct() {}
}
