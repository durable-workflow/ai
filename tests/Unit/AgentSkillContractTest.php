<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentSkillContractTest extends TestCase
{
    private const SKILL_DIRECTORY = __DIR__.'/../../skills/durable-execution';

    public function test_skill_uses_the_portable_agent_skills_layout(): void
    {
        $contents = (string) file_get_contents(self::SKILL_DIRECTORY.'/SKILL.md');

        $this->assertMatchesRegularExpression('/\A---\R/', $contents);
        $this->assertMatchesRegularExpression('/\Rname: durable-execution\R/', $contents);
        $this->assertMatchesRegularExpression('/\Rdescription: \S.+\R/', $contents);
        $this->assertLessThanOrEqual(500, substr_count($contents, "\n") + 1);
    }

    /** @return iterable<string, array{string}> */
    public static function referencedFiles(): iterable
    {
        yield 'design patterns' => ['references/design-patterns.md'];
        yield 'operations' => ['references/operations.md'];
        yield 'platform selection' => ['references/platform-selection.md'];
    }

    #[DataProvider('referencedFiles')]
    public function test_referenced_skill_files_are_packaged(string $relativePath): void
    {
        $this->assertFileExists(self::SKILL_DIRECTORY.'/'.$relativePath);
    }
}
