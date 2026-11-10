<?php

namespace App\Support;

final class LawDjenClassName
{
    private static ?array $catalog = null;

    public static function format(mixed $value): ?string
    {
        if (! is_string($value)) return null;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') return null;

        if (self::$catalog === null) {
            $data = json_decode((string) file_get_contents(database_path('seeders/data/cnj-tpu-2026-09-12.json')), true) ?: [];
            self::$catalog = [];
            foreach ($data['items']['class'] ?? [] as $option) {
                if (! is_string($option['name'] ?? null)) continue;
                $name = trim(preg_replace('/\s+/u', ' ', $option['name']) ?? $option['name']);
                self::$catalog[mb_strtolower($name, 'UTF-8')] = $name;
            }
        }
        $known = self::$catalog[mb_strtolower($value, 'UTF-8')] ?? null;
        if ($known !== null) return $known;

        preg_match_all('/\p{L}/u', $value, $letters);
        $upper = count(array_filter($letters[0], fn (string $letter): bool => $letter === mb_strtoupper($letter, 'UTF-8') && $letter !== mb_strtolower($letter, 'UTF-8')));
        if (count($letters[0]) === 0 || $upper / count($letters[0]) < 0.7) return $value;

        $result = mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
        $result = preg_replace_callback('/(?<=\s)(?:Da|Das|De|Do|Dos|E|Em|Para|Por)(?=\s|$)/u', fn ($match) => mb_strtolower($match[0], 'UTF-8'), $result) ?? $result;
        return preg_replace_callback('/\b(?:Cnj|Djen|Stf|Stj|Oab|Eca|Mp|Cpp|Cpc|Tjba|Trf|Trt|Tre|Lei|Ii|Iii|Iv|Vi|Vii|Viii|Ix)\b/u', fn ($match) => mb_strtolower($match[0], 'UTF-8') === 'lei' ? 'Lei' : mb_strtoupper($match[0], 'UTF-8'), $result) ?? $result;
    }

    public static function metadata(array $metadata): array
    {
        if (array_key_exists('suggested_case_class', $metadata)) $metadata['suggested_case_class'] = self::format($metadata['suggested_case_class']);
        if (is_array($metadata['process_data'] ?? null) && array_key_exists('case_class', $metadata['process_data'])) {
            $metadata['process_data']['case_class'] = self::format($metadata['process_data']['case_class']);
        }
        if (is_array($metadata['publications'] ?? null)) {
            foreach ($metadata['publications'] as &$publication) {
                if (is_array($publication) && array_key_exists('case_class', $publication)) $publication['case_class'] = self::format($publication['case_class']);
            }
            unset($publication);
        }
        return $metadata;
    }
}
