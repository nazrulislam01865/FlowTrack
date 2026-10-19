<?php

namespace Tests\Unit;

use App\Support\ArtworkDocumentName;
use PHPUnit\Framework\TestCase;

class ArtworkDocumentNameTest extends TestCase
{
    public function test_it_embeds_the_version_before_the_real_extension(): void
    {
        $this->assertSame(
            '017dc3cc-ca94-4be2-83c9-e753f8c37c68 - Version 2.jpg',
            ArtworkDocumentName::versioned('017dc3cc-ca94-4be2-83c9-e753f8c37c68.jpg', 2),
        );
    }

    public function test_it_replaces_existing_flowtrack_version_or_archive_suffixes(): void
    {
        $this->assertSame(
            'Artwork Final - Version 3.ai',
            ArtworkDocumentName::versioned('Artwork Final - Version 2.ai', 3),
        );

        $this->assertSame(
            'Artwork Final - Version 4.pdf',
            ArtworkDocumentName::versioned('Artwork Final - Archived.pdf', 4),
        );
    }

    public function test_it_keeps_the_artwork_identity_but_uses_the_actual_replacement_extension(): void
    {
        $this->assertSame(
            'Front Artwork - Version 3.png',
            ArtworkDocumentName::versioned('Front Artwork - Version 2.jpg', 3, 'designer-export.png'),
        );
    }

    public function test_it_does_not_remove_archive_when_it_is_part_of_the_actual_name(): void
    {
        $this->assertSame(
            'Summer Archive Final - Version 2.cdr',
            ArtworkDocumentName::versioned('Summer Archive Final.cdr', 2),
        );
    }
}
