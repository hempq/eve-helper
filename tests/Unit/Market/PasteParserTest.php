<?php

namespace Tests\Unit\Market;

use App\Services\Market\PasteParser;
use PHPUnit\Framework\TestCase;

class PasteParserTest extends TestCase
{
    private PasteParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PasteParser;
    }

    public function test_inventory_copy_with_tabs(): void
    {
        $text = "Federation Navy Antimatter Charge M\t1 000\tHybrid Charge\t\t10 m3\n".
            "Damaged Artificial Neural Network\t42\tSalvaged Materials\n".
            "Gistii B-Type Small Shield Booster\t\tShield Booster"; // empty qty = 1

        $result = $this->parser->parse($text);

        $this->assertSame([
            'Federation Navy Antimatter Charge M' => 1000,
            'Damaged Artificial Neural Network' => 42,
            'Gistii B-Type Small Shield Booster' => 1,
        ], $result['items']);
        $this->assertSame([], $result['unparsed']);
    }

    public function test_x_notation_both_directions(): void
    {
        $result = $this->parser->parse("Tritanium x 1,500\n3x Compressed Veldspar\nPlex");

        $this->assertSame([
            'Tritanium' => 1500,
            'Compressed Veldspar' => 3,
            'Plex' => 1,
        ], $result['items']);
    }

    public function test_duplicate_lines_merge_case_insensitively(): void
    {
        $result = $this->parser->parse("Tritanium x2\ntritanium x3");

        $this->assertSame(['Tritanium' => 5], $result['items']);
    }

    public function test_blank_lines_are_ignored(): void
    {
        $result = $this->parser->parse("\n\nTritanium\n\n");

        $this->assertSame(['Tritanium' => 1], $result['items']);
    }
}
