<?php

namespace App\Domain\Crm\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ParseLeadImportCsv
{
    /** @param array<string, mixed> $input
     * @return array{headers: list<string>, rows: list<array<string, string>>, settings: array<string, mixed>, source_hash: string}
     */
    public function parse(UploadedFile $file, array $input): array
    {
        $settings = Validator::make($input, [
            'encoding' => ['sometimes', 'in:UTF-8,Windows-1252'],
            'delimiter' => ['sometimes', 'in:comma,semicolon,tab'],
            'has_header' => ['sometimes', 'boolean'],
            'skip_empty_columns' => ['sometimes', 'boolean'],
            'name_format' => ['sometimes', 'in:first_last,last_first'],
        ])->validate() + ['encoding' => 'UTF-8', 'delimiter' => 'comma', 'has_header' => true, 'skip_empty_columns' => false, 'name_format' => 'first_last'];
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt']])->validate();
        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || str_contains($raw, "\0") || ! mb_check_encoding($raw, $settings['encoding'])) {
            throw ValidationException::withMessages(['file' => 'Upload a CSV in the selected encoding without binary content.']);
        }
        $decoded = mb_convert_encoding($raw, 'UTF-8', $settings['encoding']);
        if ($decoded === false) {
            throw ValidationException::withMessages(['file' => 'Could not decode the CSV.']);
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Could not create import buffer.');
        }
        $delimiter = match ($settings['delimiter']) {
            'semicolon' => ';', 'tab' => "\t", default => ','
        };
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $decoded) ?? $decoded);
            rewind($stream);
            $first = fgetcsv($stream, null, $delimiter, '"', '');
            if (! $first || count($first) > 100) {
                throw ValidationException::withMessages(['file' => 'Use between 1 and 100 columns.']);
            }
            $headers = $settings['has_header'] ? array_map(fn ($cell) => trim((string) $cell), $first)
                : array_map(fn ($index) => 'Column '.$index, range(1, count($first)));
            if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                throw ValidationException::withMessages(['file' => 'Every column needs a unique header.']);
            }
            if (! $settings['has_header']) {
                rewind($stream);
            }
            $rows = [];
            $rowNumbers = [];
            $sourceRow = $settings['has_header'] ? 1 : 0;
            while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $sourceRow++;
                if ($cells === [null]) {
                    continue;
                }
                if (count($rows) >= 1000 || count($cells) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => 'Use at most 1,000 rows with the same number of columns.']);
                }
                $rowNumbers[] = $sourceRow;
                $rows[] = array_combine($headers, array_map(fn ($cell) => trim((string) $cell), $cells));
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The CSV has no data rows.']);
            }
            if ($settings['skip_empty_columns']) {
                $headers = array_values(array_filter($headers, fn ($header) => collect($rows)->contains(fn ($row) => $row[$header] !== '')));
                if ($headers === []) {
                    throw ValidationException::withMessages(['file' => 'The CSV has no populated columns.']);
                }
                $rows = array_map(fn ($row) => array_intersect_key($row, array_flip($headers)), $rows);
            }
        } finally {
            fclose($stream);
        }

        $settings['row_numbers'] = $rowNumbers;

        return ['headers' => $headers, 'rows' => $rows, 'settings' => $settings, 'source_hash' => hash('sha256', $raw)];
    }
}
