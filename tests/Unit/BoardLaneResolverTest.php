<?php

namespace Tests\Unit;

use App\Support\BoardLaneResolver;
use PHPUnit\Framework\TestCase;

class BoardLaneResolverTest extends TestCase
{
    public function test_it_prepends_not_started_when_master_data_does_not_define_it(): void
    {
        $this->assertSame(
            ['Not Started', 'Ready', 'In Progress', 'Completed'],
            BoardLaneResolver::taskStatuses(['Ready', 'In Progress', 'Completed'])
        );
    }

    public function test_it_moves_the_configured_not_start_status_to_the_first_lane(): void
    {
        $this->assertSame(
            ['Not Start', 'Ready', 'In Progress', 'Completed'],
            BoardLaneResolver::taskStatuses(['Ready', 'In Progress', 'Not Start', 'Completed'])
        );
    }

    public function test_it_preserves_master_order_and_removes_blank_or_duplicate_labels(): void
    {
        $this->assertSame(
            ['Not Started', 'Ready', 'Blocked', 'Completed'],
            BoardLaneResolver::taskStatuses([' Ready ', '', 'Blocked', 'ready', 'Completed'])
        );
    }

    public function test_status_alias_checks_are_case_and_separator_insensitive(): void
    {
        $this->assertTrue(BoardLaneResolver::isNotStarted('NOT_STARTED'));
        $this->assertTrue(BoardLaneResolver::isNotStarted('not-start'));
        $this->assertTrue(BoardLaneResolver::isCompleted(' completed '));
        $this->assertTrue(BoardLaneResolver::taskStatusMatches('Not Started', 'Not Start'));
        $this->assertSame(['Not Start', 'Not Started'], BoardLaneResolver::databaseStatusValues('Not Start'));
    }

    public function test_duplicate_not_start_aliases_create_only_one_first_lane(): void
    {
        $this->assertSame(
            ['Not Start', 'Ready', 'Completed'],
            BoardLaneResolver::taskStatuses(['Ready', 'Not Start', 'Not Started', 'Completed'])
        );
    }
}
