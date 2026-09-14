<?php

namespace Tests\Unit;

use App\Support\EastAfricaTime;
use PHPUnit\Framework\TestCase;

class EastAfricaTimeTest extends TestCase
{
    public function test_it_formats_the_same_instant_with_the_east_africa_offset(): void
    {
        $this->assertSame(
            '2026-08-26T15:30:45.123+03:00',
            EastAfricaTime::iso8601('2026-08-26T12:30:45.123Z'),
        );
    }
}
