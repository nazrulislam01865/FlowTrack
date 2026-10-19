<?php

namespace Tests\Feature;

use Tests\TestCase;

class MentionFilterJsonRegressionTest extends TestCase
{
    public function test_task_mention_filters_query_json_array_instead_of_serialized_json_text(): void
    {
        $myWork = file_get_contents(app_path('Services/MyWorkService.php'));
        $allTasks = file_get_contents(app_path('Services/BoardTaskPackService.php'));

        $this->assertStringContainsString("whereJsonLength('meta->mention_user_ids', '>', 0)", $myWork);
        $this->assertStringContainsString("whereJsonLength('my_work_mention_activity.meta->mention_user_ids', '>', 0)", $myWork);
        $this->assertStringContainsString("whereJsonLength('board_task_mention_activity.meta->mention_user_ids', '>', 0)", $allTasks);

        $this->assertStringNotContainsString("%\\\"mention_user_ids\\\":[%", $myWork);
        $this->assertStringNotContainsString("%\\\"mention_user_ids\\\":[%", $allTasks);
    }
}
