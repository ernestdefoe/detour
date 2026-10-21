<?php

namespace Ernestdefoe\Detour;

/** Reading and writing the redirect CSV. */
final class Csv
{
    public const COLUMNS = ['type', 'source', 'target', 'status', 'mode', 'keep_query', 'external', 'lookup', 'run_id', 'preset', 'enabled', 'note'];

    /**
     * @param iterable<array<int|string, mixed>> $rows
     * @param list<string> $header
     */
    public static function write(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        assert($out !== false);

        fputcsv($out, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], array_values($row)), ',', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * A cell a spreadsheet will not run as a formula: one starting with
     * = + - @ or a tab gets a leading apostrophe, which read() removes again.
     */
    public static function cell(mixed $value): string
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        return preg_match('~^[=+\-@\t\r]~', $value) ? "'".$value : $value;
    }

    /**
     * @return list<array{line: int, cells: list<string>}>
     */
    public static function read(string $csv, int $limit): array
    {
        $in = fopen('php://temp', 'r+');
        assert($in !== false);
        fwrite($in, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
        rewind($in);

        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($in, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            $rows[] = ['line' => $line, 'cells' => array_map(fn ($c) => preg_replace("~^'(?=[=+\\-@\\t\\r])~", '', trim((string) $c)) ?? '', $cells)];
            if (count($rows) >= $limit) {
                break;
            }
        }

        fclose($in);

        return $rows;
    }
}
