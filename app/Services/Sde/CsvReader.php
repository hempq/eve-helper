<?php

namespace App\Services\Sde;

use Generator;
use RuntimeException;

/**
 * Streams a (possibly bz2-compressed) CSV file as associative rows keyed by
 * the header line. Fuzzwork dumps mark NULLs as "None" or "\N"; both are
 * normalized to PHP null.
 */
class CsvReader
{
    /**
     * @return Generator<int, array<string, string|null>>
     */
    public function rows(string $path): Generator
    {
        $handle = str_ends_with($path, '.bz2')
            ? fopen('compress.bzip2://'.$path, 'r')
            : fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file: {$path}");
        }

        try {
            $headerLine = fgets($handle);

            if ($headerLine === false) {
                throw new RuntimeException("CSV file is empty: {$path}");
            }

            // Fuzzwork dumps start with a UTF-8 BOM; it must go before CSV
            // parsing, or a quoted first header field keeps its quotes.
            if (str_starts_with($headerLine, "\xEF\xBB\xBF")) {
                $headerLine = substr($headerLine, 3);
            }

            $header = str_getcsv(trim($headerLine), escape: '\\');

            while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                if ($row === [null]) {
                    continue; // blank line
                }

                yield array_combine($header, array_map(
                    fn (?string $value): ?string => in_array($value, [null, '', 'None', '\\N'], true) ? null : $value,
                    $row,
                ));
            }
        } finally {
            fclose($handle);
        }
    }
}
