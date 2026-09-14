<?php

namespace App\Services\Market;

/**
 * Parses text pasted from the EVE client into name+quantity pairs.
 * Supported shapes (one item per line):
 *   - inventory copy: "Name<TAB>Qty<TAB>Group<TAB>..." (quantity may be empty)
 *   - "Name x3" / "3x Name" / "Name* 3"-style contract copies
 *   - bare "Name" (quantity 1)
 * Lines are merged case-insensitively by name.
 */
class PasteParser
{
    /**
     * @return array{items: array<string, int>, unparsed: list<string>}
     *         items: original-cased name => quantity
     */
    public function parse(string $text): array
    {
        $items = [];
        $names = [];
        $unparsed = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$name, $qty] = $this->parseLine($line);

            if ($name === null) {
                $unparsed[] = $line;

                continue;
            }

            $key = mb_strtolower($name);
            $names[$key] ??= $name;
            $items[$key] = ($items[$key] ?? 0) + $qty;
        }

        $byName = [];
        foreach ($items as $key => $qty) {
            $byName[$names[$key]] = $qty;
        }

        return ['items' => $byName, 'unparsed' => $unparsed];
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private function parseLine(string $line): array
    {
        // Inventory window copy: tab-separated, name first, quantity second.
        if (str_contains($line, "\t")) {
            $columns = array_map(trim(...), explode("\t", $line));
            $name = $columns[0];
            $qty = $this->toQuantity($columns[1] ?? '');

            return $name === '' ? [null, 0] : [$name, max(1, $qty)];
        }

        // "3x Name" / "3 x Name"
        if (preg_match('/^(\d[\d\s.,\']*)\s*x\s+(.+)$/iu', $line, $m)) {
            return [trim($m[2]), max(1, $this->toQuantity($m[1]))];
        }

        // "Name x3" / "Name x 3"
        if (preg_match('/^(.+?)\s+x\s*(\d[\d\s.,\']*)$/iu', $line, $m)) {
            return [trim($m[1]), max(1, $this->toQuantity($m[2]))];
        }

        // Bare name.
        return [$line, 1];
    }

    private function toQuantity(string $raw): int
    {
        return (int) preg_replace('/[^\d]/', '', $raw);
    }
}
