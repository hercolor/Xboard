<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class LegacyKnowledgeReadContractTest extends TestCase
{
    public function test_knowledge_reads_reuse_render_context_without_changing_resource_contract(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/KnowledgeController.php'));
        $resourceSource = file_get_contents(app_path('Http/Resources/KnowledgeResource.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($resourceSource);
        $this->assertStringContainsString('private function buildRenderContext(User $user): array', $controllerSource);
        $this->assertStringContainsString('userService->isAvailable($user)', $controllerSource);
        $this->assertStringContainsString('Helper::getSubscribeUrl($user', $controllerSource);
        $this->assertStringContainsString('$renderContext = $this->buildRenderContext($request->user());', $controllerSource);
        $this->assertStringContainsString('$this->processKnowledgeContent($knowledge, $renderContext)', $controllerSource);
        $this->assertStringContainsString('when(isset', $resourceSource);
        $this->assertStringContainsString("HookManager::filter('user.knowledge.resource'", $resourceSource);
    }
}
