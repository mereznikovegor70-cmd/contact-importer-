<?php
declare(strict_types=1);
namespace Contacts;

final class GoogleSource
{
    public static function exportUrl(string $input): string
    {
        $url = parse_url(trim($input));
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'docs.google.com' || isset($url['user']) || isset($url['port'])) {
            throw new \InvalidArgumentException('Укажите HTTPS-ссылку на docs.google.com/spreadsheets.');
        }
        parse_str($url['query'] ?? '', $query);
        parse_str($url['fragment'] ?? '', $fragment);
        $gid = $fragment['gid'] ?? $query['gid'] ?? '0';
        if (!is_string($gid) || !ctype_digit($gid)) { throw new \InvalidArgumentException('Некорректный gid листа Google.'); }
        if (preg_match('~^/spreadsheets/d/e/([a-zA-Z0-9_-]+)/pub(?:html)?/?$~', $url['path'] ?? '', $m)) {
            return 'https://docs.google.com/spreadsheets/d/e/' . $m[1] . '/pub?output=csv&single=true&gid=' . $gid;
        }
        if (!preg_match('~^/spreadsheets/d/([a-zA-Z0-9_-]+)(?:/.*)?$~', $url['path'] ?? '', $m)) { throw new \InvalidArgumentException('Некорректная ссылка Google-таблицы.'); }
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
    }

    public static function fetch(string $input, int $maxBytes): string
    {
        $url = self::exportUrl($input);
        for ($hop = 0; $hop < 5; $hop++) {
            $parts = parse_url($url);
            $host = strtolower($parts['host'] ?? '');
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || !($host === 'docs.google.com' || $host === 'googleusercontent.com' || str_ends_with($host, '.googleusercontent.com'))) {
                throw new \RuntimeException('Google перенаправил загрузку на недопустимый адрес. Проверьте доступ по ссылке.');
            }
            $ips = gethostbynamel($host) ?: [];
            if (!$ips) { throw new \RuntimeException('Не удалось определить адрес Google.'); }
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { throw new \RuntimeException('Недопустимый адрес сервера загрузки.'); }
            }
            $body = ''; $location = ''; $tooLarge = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$host . ':443:' . $ips[0]], CURLOPT_PROXY => '',
                CURLOPT_USERAGENT => 'ContactImporter/1.0',
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$location) {
                    if (stripos($header, 'Location:') === 0) { $location = trim(substr($header, 9)); }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                    if (strlen($body) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($tooLarge) { throw new \RuntimeException('Google-таблица превышает допустимый размер.'); }
            if ($ok === false) { error_log('Google download: ' . $curlError); throw new \InvalidArgumentException('Не удалось скачать Google-таблицу. Проверьте доступ хостинга к Google и сертификаты HTTPS.'); }
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = str_starts_with($location, '/') && !str_starts_with($location, '//') ? 'https://' . $host . $location : $location;
                continue;
            }
            if ($status !== 200 || stripos($type, 'text/html') !== false || preg_match('/^\s*<(?:!doctype|html)/i', $body)) {
                throw new \InvalidArgumentException('Google-таблица недоступна. Включите просмотр по ссылке или опубликуйте лист. Закрытую таблицу можно загрузить файлом.');
            }
            if ($body === '') { throw new \RuntimeException('Google вернул пустую таблицу.'); }
            return $body;
        }
        throw new \RuntimeException('Слишком много перенаправлений Google.');
    }
}
