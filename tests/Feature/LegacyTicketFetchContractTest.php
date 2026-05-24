<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class LegacyTicketFetchContractTest extends TestCase
{
    public function test_ticket_detail_fetch_avoids_duplicate_message_load_and_keeps_detail_contract(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/TicketController.php'));
        $resourceSource = file_get_contents(app_path('Http/Resources/TicketResource.php'));
        $messageResourceSource = file_get_contents(app_path('Http/Resources/MessageResource.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($resourceSource);
        $this->assertIsString($messageResourceSource);
        $this->assertStringNotContainsString("->first()
                ->load('message')", $controllerSource);
        $this->assertStringContainsString("select(['id', 'ticket_id', 'user_id', 'message', 'created_at', 'updated_at'])", $controllerSource);
        $this->assertStringContainsString('$message->setRelation(\'ticket\', $ticket);', $controllerSource);
        $this->assertStringContainsString('TicketResource::make($ticket)->additional([\'message\' => true])', $controllerSource);
        $this->assertStringContainsString('MessageResource::collection', $resourceSource);
        $this->assertStringContainsString('"is_me" => $this[\'is_from_user\']', $messageResourceSource);
    }
}
