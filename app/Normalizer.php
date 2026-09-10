<?php
declare(strict_types=1);
namespace Contacts;

final class Normalizer
{
    public const FIELDS = ['company' => 'Компания', 'address' => 'Адрес', 'phones' => 'Телефоны', 'email' => 'Почта', 'website' => 'Сайт'];

    public static function key(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($value))) ?? '';
    }

    public static function signature(array $headers): string
    {
        return hash('sha256', json_encode(array_map(self::key(...), $headers), JSON_UNESCAPED_UNICODE));
    }

    public static function guess(array $headers): array
    {
        $aliases = [
            'company' => ['компания', 'название', 'название компании', 'наименование', 'организация', 'наименование организации', 'наименование юридического лица', 'полное наименование', 'краткое наименование', 'company', 'company name', 'name'],
            'address' => ['адрес', 'юридический адрес', 'фактический адрес', 'город', 'населенный пункт', 'регион', 'область', 'улица', 'дом', 'address', 'city', 'region'],
            'phones' => ['телефон', 'телефоны', 'мобильный телефон', 'мобильный', 'контактный телефон', 'тел', 'phone', 'phones', 'mobile', 'whatsapp', 'viber'],
            'email' => ['email', 'e-mail', 'электронная почта', 'почта', 'emails', 'email адрес'],
            'website' => ['сайт', 'сайты', 'web', 'website', 'url', 'веб сайт', 'интернет сайт'],
        ];
        $result = array_fill_keys(array_keys(self::FIELDS), []);
        foreach ($headers as $i => $header) {
            $key = preg_replace('/\d+$/', '', self::key($header));
            foreach ($aliases as $field => $names) {
                if (in_array($key, array_map(self::key(...), $names), true)) {
                    $result[$field][] = $i;
                    break;
                }
            }
        }
        return $result;
    }

    public static function validateMapping(array $mapping, int $width): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $_) {
            $values = $mapping[$field] ?? [];
            if (!is_array($values) || count($values) > $width) {
                throw new \InvalidArgumentException('Некорректное соответствие колонок.');
            }
            $out[$field] = [];
            foreach ($values as $value) {
                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0 || (int)$value >= $width) {
                    throw new \InvalidArgumentException('Указана несуществующая колонка.');
                }
                $out[$field][] = (int)$value;
            }
            $out[$field] = array_values(array_unique($out[$field]));
        }
        if (!array_filter($out)) {
            throw new \InvalidArgumentException('Выберите хотя бы одну колонку для импорта.');
        }
        return $out;
    }

    public static function text(string $value): string
    {
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $value) ?? $value);
    }

    public static function phones(string $value): string
    {
        $value = str_replace(['‒', '–', '—', '−', '‑'], '-', $value);
        preg_match_all('/(?<!\d)(\+?\d[\d\s().\-]{4,}\d)(?:\s*(?:доб\.?|ext\.?|добавочный)\s*:?\s*(\d+))?/ui', $value, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $m) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if (strlen($digits) < 5 || strlen($digits) > 15) { continue; }
            if (strlen($digits) === 11 && $digits[0] === '8') { $digits = '7' . substr($digits, 1); }
            if (strlen($digits) === 10) { $digits = '7' . $digits; }
            $phone = (strlen($digits) >= 11 || str_starts_with(trim($m[1]), '+') ? '+' : '') . $digits;
            $out[] = $phone . (!empty($m[2]) ? ' доб. ' . $m[2] : '');
        }
        // Unrecognized values remain visible for manual review; no silent data loss.
        return implode('; ', array_unique($out)) ?: self::text($value);
    }

    public static function tel(string $phone): ?string
    {
        if (!preg_match('/^(\+?\d{5,15})(?: доб\. (\d+))?$/', $phone, $m)) { return null; }
        return 'tel:' . $m[1] . (!empty($m[2]) ? ';ext=' . $m[2] : '');
    }

    public static function emails(string $value): string
    {
        preg_match_all('/[a-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}/i', $value, $m);
        return implode('; ', array_unique(array_map('strtolower', $m[0]))) ?: self::text($value);
    }

    public static function websites(string $value): string
    {
        $parts = preg_split('/[\s,;]+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $part) && str_contains($part, '.')) { $part = 'https://' . $part; }
            $out[] = $part;
        }
        return implode('; ', array_unique($out));
    }

    public static function websiteLink(string $value): ?string
    {
        $url = parse_url($value);
        return is_array($url) && in_array(strtolower($url['scheme'] ?? ''), ['https', 'http'], true) && !empty($url['host']) && !isset($url['user']) ? $value : null;
    }

    public static function row(array $row, array $mapping): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $_) {
            $parts = [];
            foreach ($mapping[$field] ?? [] as $index) {
                $value = self::text((string)($row[$index] ?? ''));
                if ($value !== '') { $parts[] = $value; }
            }
            $value = implode(in_array($field, ['company', 'address'], true) ? ', ' : '; ', array_unique($parts));
            $out[$field] = match ($field) {
                'phones' => self::phones($value),
                'email' => self::emails($value),
                'website' => self::websites($value),
                default => $value,
            };
        }
        return $out;
    }
}
