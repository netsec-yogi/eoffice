<?php

namespace Tests\Feature\Platform;

use App\Support\SequenceGenerator;
use Tests\TestCase;

class SequenceGeneratorTest extends TestCase
{
    public function test_numbers_are_sequential_per_scope_and_formatted(): void
    {
        $seq = app(SequenceGenerator::class);
        $year = now()->format('Y');

        $this->assertSame("IT/{$year}/00001", $seq->format('IT', 'IT/{YYYY}/{SEQ:5}'));
        $this->assertSame("IT/{$year}/00002", $seq->format('IT', 'IT/{YYYY}/{SEQ:5}'));
        $this->assertSame('LV-'.substr($year, -2).'-1', $seq->format('LEAVE', 'LV-{YY}-{SEQ}'));
    }
}
