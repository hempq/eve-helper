<?php

namespace App\Services\Farm;

/**
 * Parses text copied from the in-game probe scanner window (tab-separated;
 * the same format Tripwire/Pathfinder/Wanderer ingest):
 *   VOB-799<TAB>Cosmic Signature<TAB>Data Site<TAB>Local Serpentis Mainframe<TAB>100.0%<TAB>7.03 AU
 * Unscanned rows carry empty category/name columns.
 */
class ProbeScanParser
{
    /**
     * @return list<array{sigId: string, group: string, category: ?string,
     *   name: ?string, signal: ?float}>
     */
    public function parse(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (! str_contains($line, "\t")) {
                continue;
            }

            $columns = array_map(trim(...), explode("\t", $line));

            if (! preg_match('/^[A-Z]{3}-\d{3}$/', $columns[0] ?? '')) {
                continue;
            }

            $signal = null;
            foreach ($columns as $column) {
                if (preg_match('/^(\d+(?:[.,]\d+)?)\s*%$/', $column, $m)) {
                    $signal = (float) str_replace(',', '.', $m[1]);
                    break;
                }
            }

            $rows[] = [
                'sigId' => $columns[0],
                'group' => $columns[1] ?? 'Cosmic Signature',
                'category' => ($columns[2] ?? '') !== '' ? $columns[2] : null,
                'name' => ($columns[3] ?? '') !== '' ? $columns[3] : null,
                'signal' => $signal,
            ];
        }

        return $rows;
    }
}
