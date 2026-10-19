<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class OrderListTaskColorAndAvatarTest extends TestCase
{
    public function test_order_list_uses_active_task_color_and_real_profile_images(): void
    {
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));
        $view = OrderPhase5Source::prototypeListView();
        $ownerCell = file_get_contents(resource_path('views/components/orders/prototype-owner-cell.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/list.css'));

        $this->assertStringContainsString("'setupTemplate:id,task_pack_id,source_task_pack_item_id,title,is_required,sort_order,automation_key,color'", $service);
        $this->assertStringContainsString("'template:id,task_pack_id,title,is_required,sequence,color'", $service);
        $this->assertStringContainsString('sourceItem?->color', $service);
        $this->assertStringContainsString('OrderArtworkListState::resolve', $service);
        $this->assertStringContainsString("'active_task_color' => \$activeTaskColor", $service);
        $this->assertStringContainsString("'owner_avatar' => \$job->owner?->profileImageUrl()", $service);
        $this->assertStringContainsString("'stage_assignee_avatar' => \$stageAssignee?->profileImageUrl()", $service);

        $this->assertStringContainsString('MasterColor::taskRowStyle($rowColor)', $view);
        $this->assertStringContainsString("data_get($row, 'stage_filter_color')", $view);
        $masterColor = file_get_contents(app_path('Support/MasterColor.php'));
        $this->assertStringContainsString('--task-row-color:%s;--task-row-bg:', $masterColor);
        $this->assertStringContainsString(":src=\"data_get(\$row,'owner_avatar')\"", $view);
        $this->assertStringContainsString(":src=\"data_get(\$row,'stage_assignee_avatar')\"", $ownerCell);

        $this->assertStringContainsString('background-color:var(--task-row-bg,#fff)', $css);
        $this->assertStringContainsString('background-color:var(--task-row-hover-bg,var(--ft-theme-o-v5-hover))', $css);
        $this->assertStringContainsString('box-shadow:inset 4px 0 0 var(--task-row-color)', $css);
        $this->assertStringContainsString('.owner-delivery>.avatar img', $css);
    }
}
