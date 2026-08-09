<?php
declare(strict_types=1);

const CONFIG_FILE = __DIR__ . '/.dashboard.env';
const STATE_DIR = __DIR__ . '/.dashboard-data';
const FONT_FILE = __DIR__ . '/.dashboard-font.ttf';
const ICON_DIR = __DIR__ . '/assets';

function config(): array {
    $config = [];
    foreach (file(CONFIG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] === '#') continue;
        $position = strpos($line, '=');
        if ($position === false) continue;
        $config[substr($line, 0, $position)] = substr($line, $position + 1);
    }
    $devicesJson = trim($config['DASHBOARD_DEVICES_JSON'] ?? '[]', "'\"");
    $config['devices'] = json_decode($devicesJson, true);
    if (!is_array($config['devices']) || !$config['devices']) fail(500, 'Dashboard configuration is invalid');
    $serversJson = trim($config['DASHBOARD_SERVERS_JSON'] ?? '[{"id":"local","label":"服务器"}]', "'\"");
    $servers = json_decode($serversJson, true);
    $config['servers'] = [];
    foreach (is_array($servers) ? $servers : [] as $server) {
        $id = is_array($server) ? trim((string)($server['id'] ?? '')) : '';
        $label = is_array($server) ? trim((string)($server['label'] ?? '')) : '';
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $id) || $label === '' || strlen($label) > 64) continue;
        $config['servers'][] = ['id' => $id, 'label' => $label];
    }
    if (!$config['servers']) $config['servers'] = [['id' => 'local', 'label' => '服务器']];
    date_default_timezone_set($config['DASHBOARD_TIMEZONE'] ?? 'Asia/Shanghai');
    return $config;
}

function fail(int $status, string $message = ''): void {
    http_response_code($status);
    if ($message !== '') header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function json_response(array $value, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_UNESCAPED_UNICODE);
    exit;
}

function png_chunk(string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function render_grayscale_png($image): void {
    imagefilter($image, IMG_FILTER_GRAYSCALE);
    $width = imagesx($image); $height = imagesy($image); $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $row = "\0";
        for ($x = 0; $x < $width; $x++) {
            $row .= chr((imagecolorat($image, $x, $y) >> 16) & 0xff);
        }
        $raw .= $row;
    }
    $compressed = gzcompress($raw, 9);
    if ($compressed === false) { imagedestroy($image); fail(500, 'PNG encoding failed'); }
    $header = pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0);
    header('Content-Type: image/png'); header('Cache-Control: no-store, max-age=0');
    echo "\x89PNG\r\n\x1a\n" . png_chunk('IHDR', $header) . png_chunk('IDAT', $compressed) . png_chunk('IEND', '');
    imagedestroy($image); exit;
}

function default_state(): array {
    return ['quota' => [], 'weather' => [], 'weather_updated_at' => 0];
}

function load_state(): array {
    $content = @file_get_contents(STATE_DIR . '/state.json');
    $state = is_string($content) ? json_decode($content, true) : null;
    return is_array($state) ? array_merge(default_state(), $state) : default_state();
}

function update_state(callable $mutator): array {
    if (!is_dir(STATE_DIR)) mkdir(STATE_DIR, 0700, true);
    $lock = fopen(STATE_DIR . '/state.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) fail(500, 'State is unavailable');
    $state = load_state();
    $changed = $mutator($state);
    if ($changed) {
        $temporary = STATE_DIR . '/state.json.tmp';
        file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($temporary, STATE_DIR . '/state.json');
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $state;
}

function request_json(string $url): ?array {
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $payload = is_string($body) ? json_decode($body, true) : null;
    return $status === 200 && is_array($payload) ? $payload : null;
}

function weather_city_query(array $config): string {
    $city = trim((string)($config['DASHBOARD_CITY'] ?? ''));
    if ($city !== '') return $city;
    return trim((string)($config['DASHBOARD_CITY_LABEL'] ?? ''));
}

function normalize_city_name(string $name): string {
    $name = trim($name);
    return preg_replace('/市$/u', '', $name) ?: $name;
}

function geocode_city(string $city): ?array {
    if ($city === '') return null;
    $openMeteoQuery = http_build_query(['name' => $city, 'count' => 1, 'language' => 'zh', 'format' => 'json']);
    $payload = request_json('https://geocoding-api.open-meteo.com/v1/search?' . $openMeteoQuery);
    $result = is_array($payload['results'][0] ?? null) ? $payload['results'][0] : null;
    if ($result && is_numeric($result['latitude'] ?? null) && is_numeric($result['longitude'] ?? null)) {
        return ['latitude' => (float)$result['latitude'], 'longitude' => (float)$result['longitude'], 'name' => normalize_city_name((string)($result['name'] ?? $city))];
    }

    $nominatimQuery = http_build_query(['format' => 'jsonv2', 'limit' => 1, 'accept-language' => 'zh-CN', 'q' => $city]);
    $curl = curl_init('https://nominatim.openstreetmap.org/search?' . $nominatimQuery);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'RemoteEinkDashboard/1.0', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $fallback = is_string($body) ? json_decode($body, true) : null;
    $result = is_array($fallback[0] ?? null) ? $fallback[0] : null;
    if (!$result || $status !== 200 || !is_numeric($result['lat'] ?? null) || !is_numeric($result['lon'] ?? null)) return null;
    return ['latitude' => (float)$result['lat'], 'longitude' => (float)$result['lon'], 'name' => normalize_city_name((string)($result['name'] ?? $city))];
}

function request_weather(array $config): ?array {
    $cityQuery = weather_city_query($config);
    $useConfiguredLocation = !empty($config['DASHBOARD_USE_COORDINATES']) && is_numeric($config['DASHBOARD_LATITUDE'] ?? null) && is_numeric($config['DASHBOARD_LONGITUDE'] ?? null);
    $location = !$useConfiguredLocation && $cityQuery !== '' ? geocode_city($cityQuery) : null;
    if (!$useConfiguredLocation && $cityQuery !== '' && !$location) return null;
    $latitude = $useConfiguredLocation ? (float)$config['DASHBOARD_LATITUDE'] : ($location['latitude'] ?? ($config['DASHBOARD_LATITUDE'] ?? 0));
    $longitude = $useConfiguredLocation ? (float)$config['DASHBOARD_LONGITUDE'] : ($location['longitude'] ?? ($config['DASHBOARD_LONGITUDE'] ?? 0));
    $cityName = $useConfiguredLocation ? normalize_city_name((string)($config['DASHBOARD_CITY_LABEL'] ?? '未配置城市')) : ($location['name'] ?? normalize_city_name((string)($config['DASHBOARD_CITY_LABEL'] ?? '未配置城市')));
    $query = http_build_query([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'current' => 'temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m,wind_gusts_10m,uv_index',
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max,sunrise,sunset',
        'timezone' => $config['DASHBOARD_TIMEZONE'] ?? 'Asia/Shanghai',
    ]);
    $payload = request_json('https://api.open-meteo.com/v1/forecast?' . $query);
    if (!$payload) return null;
    $airQuery = http_build_query([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'current' => 'us_aqi,pm2_5',
        'timezone' => $config['DASHBOARD_TIMEZONE'] ?? 'Asia/Shanghai',
    ]);
    $air = request_json('https://air-quality-api.open-meteo.com/v1/air-quality?' . $airQuery);
    $airCurrent = is_array($air['current'] ?? null) ? $air['current'] : [];
    $current = $payload['current'] ?? [];
    $daily = $payload['daily'] ?? [];
    $forecast = [];
    $dates = is_array($daily['time'] ?? null) ? $daily['time'] : [];
    foreach (array_slice($dates, 0, 7) as $index => $date) {
        $forecast[] = [
            'date' => $date,
            'code' => $daily['weather_code'][$index] ?? null,
            'high' => $daily['temperature_2m_max'][$index] ?? null,
            'low' => $daily['temperature_2m_min'][$index] ?? null,
            'rain_probability' => $daily['precipitation_probability_max'][$index] ?? null,
            'wind' => $daily['wind_speed_10m_max'][$index] ?? null,
            'gust' => $daily['wind_gusts_10m_max'][$index] ?? null,
        ];
    }
    return [
        'city' => $cityName,
        'source_city' => $cityQuery,
        'location_key' => (string)($config['DASHBOARD_LOCATION_KEY'] ?? ''),
        'temperature' => $current['temperature_2m'] ?? null,
        'humidity' => $current['relative_humidity_2m'] ?? null,
        'wind' => $current['wind_speed_10m'] ?? null,
        'gust' => $current['wind_gusts_10m'] ?? null,
        'uv_index' => $current['uv_index'] ?? null,
        'aqi' => $airCurrent['us_aqi'] ?? null,
        'pm2_5' => $airCurrent['pm2_5'] ?? null,
        'code' => $current['weather_code'] ?? null,
        'high' => $daily['temperature_2m_max'][0] ?? null,
        'low' => $daily['temperature_2m_min'][0] ?? null,
        'sunrise' => $daily['sunrise'][0] ?? null,
        'sunset' => $daily['sunset'][0] ?? null,
        'forecast' => $forecast,
    ];
}

function state_with_weather(array $config): array {
    return update_state(function (&$state) use ($config) {
        $cachedWeather = is_array($state['weather'] ?? null) ? $state['weather'] : [];
        $cachedForecast = is_array($cachedWeather['forecast'] ?? null) ? $cachedWeather['forecast'] : [];
        $cityQuery = weather_city_query($config);
        if (time() - (int)($state['weather_updated_at'] ?? 0) < 900 && ($cachedWeather['source_city'] ?? '') === $cityQuery && count($cachedForecast) >= 7 && array_key_exists('aqi', $cachedWeather) && array_key_exists('uv_index', $cachedWeather) && array_key_exists('sunrise', $cachedWeather) && array_key_exists('sunset', $cachedWeather) && array_key_exists('wind', $cachedForecast[1] ?? []) && array_key_exists('rain_probability', $cachedForecast[1] ?? [])) return false;
        $weather = request_weather($config);
        if (!$weather) return false;
        $state['weather'] = $weather;
        $state['weather_updated_at'] = time();
        return true;
    });
}

function public_weather_city(array $config): string {
    $allowed = ['北京', '上海', '北海', '江门', '盐城', '泰安', '临海', '广州'];
    $requested = normalize_city_name((string)($_GET['city'] ?? ''));
    if (in_array($requested, $allowed, true)) return $requested;
    $configured = normalize_city_name(weather_city_query($config));
    return in_array($configured, $allowed, true) ? $configured : '北海';
}

function public_weather_for_city(array $config, string $city): array {
    $locations = [
        '北京' => [39.9042, 116.4074],
        '上海' => [31.2304, 121.4737],
        '北海' => [21.4733, 109.1202],
        '江门' => [22.5787, 113.0819],
        '盐城' => [33.3495, 120.1616],
        '泰安' => [36.2003, 117.0876],
        '临海' => [28.8583, 121.1444],
        '广州' => [23.1291, 113.2644],
    ];
    [$latitude, $longitude] = $locations[$city];
    $config['DASHBOARD_CITY'] = $city;
    $config['DASHBOARD_CITY_LABEL'] = $city;
    $config['DASHBOARD_LATITUDE'] = $latitude;
    $config['DASHBOARD_LONGITUDE'] = $longitude;
    $config['DASHBOARD_USE_COORDINATES'] = true;
    $config['DASHBOARD_LOCATION_KEY'] = $city;
    $state = update_state(function (&$state) use ($config, $city) {
        if (!is_array($state['public_weather'] ?? null)) $state['public_weather'] = [];
        $cached = is_array($state['public_weather'][$city] ?? null) ? $state['public_weather'][$city] : [];
        $weather = is_array($cached['weather'] ?? null) ? $cached['weather'] : [];
        $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : [];
        $fresh = time() - (int)($cached['updated_at'] ?? 0) < 900
            && ($weather['source_city'] ?? '') === $city
            && ($weather['location_key'] ?? '') === $city
            && count($forecast) >= 7
            && array_key_exists('aqi', $weather)
            && array_key_exists('uv_index', $weather)
            && array_key_exists('sunrise', $weather)
            && array_key_exists('sunset', $weather)
            && array_key_exists('wind', $forecast[1] ?? [])
            && array_key_exists('rain_probability', $forecast[1] ?? []);
        if ($fresh) return false;
        $weather = request_weather($config);
        if (!$weather) return false;
        $state['public_weather'][$city] = ['weather' => $weather, 'updated_at' => time()];
        return true;
    });
    $cached = is_array($state['public_weather'][$city] ?? null) ? $state['public_weather'][$city] : [];
    return is_array($cached['weather'] ?? null) ? $cached['weather'] : [];
}

function device(array $config, string $id): ?array {
    foreach ($config['devices'] as $candidate) {
        if (is_array($candidate) && ($candidate['id'] ?? '') === $id) return $candidate;
    }
    return null;
}

function weather_label($code): string {
    $labels = [0 => '晴', 1 => '晴间多云', 2 => '多云', 3 => '阴', 45 => '雾', 48 => '雾', 51 => '毛毛雨', 53 => '毛毛雨', 55 => '毛毛雨', 61 => '小雨', 63 => '中雨', 65 => '大雨', 80 => '阵雨', 81 => '阵雨', 82 => '强阵雨', 95 => '雷雨', 96 => '雷雨', 99 => '强雷雨'];
    return $labels[(int)$code] ?? '--';
}

function air_quality_label($aqi): string {
    if (!is_numeric($aqi)) return '--';
    $aqi = (int)round((float)$aqi);
    if ($aqi <= 50) return '优';
    if ($aqi <= 100) return '良';
    if ($aqi <= 150) return '轻度';
    if ($aqi <= 200) return '中度';
    if ($aqi <= 300) return '重度';
    return '严重';
}

function air_quality_tone($aqi): string {
    if (!is_numeric($aqi)) return 'unknown';
    $aqi = (int)round((float)$aqi);
    if ($aqi <= 50) return 'excellent';
    if ($aqi <= 100) return 'good';
    if ($aqi <= 150) return 'light';
    if ($aqi <= 200) return 'moderate';
    if ($aqi <= 300) return 'heavy';
    return 'severe';
}

function wind_level($speed): ?int {
    if (!is_numeric($speed)) return null;
    foreach ([1, 6, 12, 20, 29, 39, 50, 62, 75, 89, 103, 118] as $level => $upperLimit) {
        if ((float)$speed < $upperLimit) return $level;
    }
    return 12;
}

function uv_level_label($uvIndex): string {
    if (!is_numeric($uvIndex)) return '--';
    $uvIndex = (float)$uvIndex;
    if ($uvIndex <= 2) return '低';
    if ($uvIndex <= 5) return '中等';
    if ($uvIndex <= 7) return '高';
    if ($uvIndex <= 10) return '很高';
    return '极高';
}

function uv_level_tone($uvIndex): string {
    if (!is_numeric($uvIndex)) return 'unknown';
    $uvIndex = (float)$uvIndex;
    if ($uvIndex <= 2) return 'low';
    if ($uvIndex <= 5) return 'moderate';
    if ($uvIndex <= 7) return 'high';
    if ($uvIndex <= 10) return 'very-high';
    return 'extreme';
}

function weather_time_label($value): string {
    return is_string($value) && preg_match('/T(\d{2}:\d{2})$/', $value, $matches) ? $matches[1] : '--:--';
}

function weather_is_rainy($code): bool {
    return in_array((int)$code, [51, 53, 55, 61, 63, 65, 80, 81, 82, 95, 96, 99], true);
}

function weather_advice(array $weather, array $forecast): string {
    $tomorrow = is_array($forecast[1] ?? null) ? $forecast[1] : [];
    $rainy = weather_is_rainy($tomorrow['code'] ?? null);
    $heavyRain = in_array((int)($tomorrow['code'] ?? -1), [65, 82, 95, 96, 99], true);
    $windy = (is_numeric($tomorrow['wind'] ?? null) && (float)$tomorrow['wind'] >= 28)
        || (is_numeric($tomorrow['gust'] ?? null) && (float)$tomorrow['gust'] >= 38);
    $cooling = (is_numeric($weather['high'] ?? null) && is_numeric($tomorrow['high'] ?? null) && (float)$tomorrow['high'] <= (float)$weather['high'] - 2)
        || (is_numeric($weather['low'] ?? null) && is_numeric($tomorrow['low'] ?? null) && (float)$tomorrow['low'] <= (float)$weather['low'] - 2);

    if ($cooling && $rainy && $windy) return '明日降温有雨且风大，添衣带伞，注意防风。';
    if ($cooling && $rainy) return ($heavyRain ? '明日降温且雨势较强，记得添衣带伞。' : '明日降温有雨，记得添衣带伞。');
    if ($rainy && $windy) return ($heavyRain ? '明日雨势较强且风大，带伞并注意防风。' : '明日有雨且风大，带伞并注意防风。');
    if ($cooling && $windy) return '明日降温风大，记得添衣防风。';
    if ($rainy) return $heavyRain ? '明日雨势较强，出门带伞并留意路况。' : '明日有雨，出门记得带伞。';
    if ($windy) return '明日风力较大，出行注意防风。';
    if ($cooling) return '明日降温，早晚记得添衣。';
    if (weather_is_rainy($weather['code'] ?? null)) return '今天有雨，出门记得带伞。';
    if (is_numeric($weather['wind'] ?? null) && (float)$weather['wind'] >= 28) return '今天风力较大，出行注意防风。';
    if (is_numeric($weather['aqi'] ?? null) && (float)$weather['aqi'] > 100) return '空气质量欠佳，敏感人群减少久留户外。';
    if (is_numeric($weather['temperature'] ?? null) && (float)$weather['temperature'] >= 31) return '天气闷热，注意防晒并及时补水。';
    return '天气平稳，愿你从容出行。';
}

function quota_accounts(array $state): array {
    $quota = is_array($state['quota'] ?? null) ? $state['quota'] : [];
    $sources = is_array($quota['sources'] ?? null) ? $quota['sources'] : [];
    $ordered = array_values(array_unique(array_merge(['claude', 'deepseek', 'codex'], array_keys($sources))));
    $accounts = [];
    foreach ($ordered as $source) {
        $items = $sources[$source]['accounts'] ?? [];
        if (!is_array($items)) continue;
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['name'] ?? null) && is_string($item['summary'] ?? null)) {
                $item['source'] = $source;
                $accounts[] = $item;
            }
        }
    }
    return array_slice($accounts, 0, 5);
}

function draw_text($image, int $size, int $x, int $baseline, string $value, int $colour, string $align = 'left'): void {
    $fontScale = (float)($GLOBALS['dashboard_font_scale'] ?? 1);
    $size = max(1, (int)round($size * $fontScale));
    if (function_exists('imagettftext') && is_readable(FONT_FILE)) {
        $box = imagettfbbox($size, 0, FONT_FILE, $value);
        $left = min($box[0], $box[2], $box[4], $box[6]);
        $right = max($box[0], $box[2], $box[4], $box[6]);
        if ($align === 'right') $x -= $right;
        if ($align === 'center') $x -= (int)round(($left + $right) / 2);
        imagettftext($image, $size, 0, $x, $baseline, $colour, FONT_FILE, $value);
        return;
    }
    imagestring($image, 5, $x, $baseline - 14, $value, $colour);
}

function box($image, array $rect, int $colour): void {
    imagerectangle($image, $rect[0], $rect[1], $rect[2], $rect[3], $colour);
}

function calendar_box($image, array $rect, int $now, float $scale, int $black, int $grey, int $white): void {
    box($image, $rect, $grey);
    $x1 = $rect[0]; $y1 = $rect[1]; $x2 = $rect[2]; $y2 = $rect[3];
    $month = date('Y-m', $now);
    draw_text($image, max(16, (int)(22 * $scale)), $x1 + 18, $y1 + 36, $month, $black);
    $top = $y1 + (int)(68 * $scale);
    $cellWidth = ($x2 - $x1 - 24) / 7;
    $cellHeight = ($y2 - $top - 18) / 7;
    foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $column => $name) {
        draw_text($image, max(11, (int)(14 * $scale)), (int)($x1 + 12 + $column * $cellWidth + $cellWidth / 2), $top, $name, $grey, 'center');
    }
    $year = (int)date('Y', $now); $monthNumber = (int)date('n', $now); $today = (int)date('j', $now);
    $firstWeekday = (int)date('N', mktime(0, 0, 0, $monthNumber, 1, $year)) - 1;
    $days = (int)date('t', mktime(0, 0, 0, $monthNumber, 1, $year));
    for ($day = 1; $day <= $days; $day++) {
        $index = $firstWeekday + $day - 1; $row = intdiv($index, 7); $column = $index % 7;
        $cx = (int)($x1 + 12 + $column * $cellWidth + $cellWidth / 2);
        $cy = (int)($top + $cellHeight * ($row + 1) + $cellHeight / 2);
        if ($day === $today) {
            imagefilledellipse($image, $cx, $cy - 5, (int)min($cellWidth, $cellHeight) - 8, (int)min($cellWidth, $cellHeight) - 8, $black);
            $colour = $white;
        } else $colour = $column > 4 ? $grey : $black;
        draw_text($image, max(12, (int)(16 * $scale)), $cx, $cy + 1, (string)$day, $colour, 'center');
    }
}

function lunar_day_name(int $day): string {
    $names = [1 => '初一', 2 => '初二', 3 => '初三', 4 => '初四', 5 => '初五', 6 => '初六', 7 => '初七', 8 => '初八', 9 => '初九', 10 => '初十', 11 => '十一', 12 => '十二', 13 => '十三', 14 => '十四', 15 => '十五', 16 => '十六', 17 => '十七', 18 => '十八', 19 => '十九', 20 => '二十', 21 => '廿一', 22 => '廿二', 23 => '廿三', 24 => '廿四', 25 => '廿五', 26 => '廿六', 27 => '廿七', 28 => '廿八', 29 => '廿九', 30 => '三十'];
    return $names[$day] ?? '';
}

function lunar_info(int $timestamp): array {
    if (!class_exists('IntlCalendar')) return ['text' => '农历 --', 'short' => '', 'month' => 0, 'day' => 0];
    $calendar = IntlCalendar::createInstance('Asia/Shanghai', '@calendar=chinese');
    $calendar->setTime($timestamp * 1000);
    $monthNames = [1 => '正', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六', 7 => '七', 8 => '八', 9 => '九', 10 => '十', 11 => '冬', 12 => '腊'];
    $month = $calendar->get(IntlCalendar::FIELD_MONTH) + 1;
    $day = $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH);
    $leap = $calendar->get(IntlCalendar::FIELD_IS_LEAP_MONTH) === 1 ? '闰' : '';
    $short = lunar_day_name($day);
    $lunarYear = (int)date('Y', $timestamp);
    if ((int)date('n', $timestamp) <= 2 && $month >= 11) $lunarYear--;
    $stems = ['甲', '乙', '丙', '丁', '戊', '己', '庚', '辛', '壬', '癸'];
    $branches = ['子', '丑', '寅', '卯', '辰', '巳', '午', '未', '申', '酉', '戌', '亥'];
    $ganzhi = $stems[($lunarYear - 4) % 10] . $branches[($lunarYear - 4) % 12];
    return ['text' => $ganzhi . '年' . $leap . ($monthNames[$month] ?? '') . '月' . $short, 'short' => $short, 'month' => $month, 'day' => $day];
}

function days_until_lunar_new_year(int $timestamp): ?int {
    $today = strtotime(date('Y-m-d', $timestamp) . ' 12:00:00');
    for ($offset = 1; $offset <= 400; $offset++) {
        $lunar = lunar_info($today + $offset * 86400);
        if (($lunar['month'] ?? 0) === 1 && ($lunar['day'] ?? 0) === 1) return $offset;
    }
    return null;
}

function days_until_double_eleven(int $timestamp): int {
    $today = strtotime(date('Y-m-d', $timestamp) . ' 00:00:00');
    $target = strtotime(date('Y', $timestamp) . '-11-11 00:00:00');
    if ($target <= $today) $target = strtotime(((int)date('Y', $timestamp) + 1) . '-11-11 00:00:00');
    return (int)(($target - $today) / 86400);
}

function draw_progress($image, int $x, int $y, int $width, int $height, ?int $used, int $black, int $grey, int $white): void {
    imagerectangle($image, $x, $y, $x + $width, $y + $height, $grey);
    if ($used === null || $used <= 0) return;
    $fill = max(1, (int)round($width * min(100, $used) / 100));
    imagefilledrectangle($image, $x + 1, $y + 1, $x + $fill, $y + $height - 1, $black);
}

function draw_location_pin($image, int $x, int $y, int $size, int $black, int $white): void {
    $radius = max(4, intdiv($size, 2));
    imagefilledellipse($image, $x, $y - intdiv($radius, 3), $radius * 2, $radius * 2, $black);
    imagefilledpolygon($image, [$x - $radius, $y, $x + $radius, $y, $x, $y + (int)round($radius * 1.8)], 3, $black);
    imagefilledellipse($image, $x, $y - intdiv($radius, 3), $radius, $radius, $white);
}

function draw_sun_icon($image, int $x, int $y, int $size, int $black): void {
    $radius = max(4, intdiv($size, 3));
    imageellipse($image, $x, $y, $radius * 2, $radius * 2, $black);
    for ($angle = 0; $angle < 360; $angle += 45) {
        $r1 = $radius + 3; $r2 = $radius + 7;
        imageline($image, $x + (int)round(cos(deg2rad($angle)) * $r1), $y + (int)round(sin(deg2rad($angle)) * $r1), $x + (int)round(cos(deg2rad($angle)) * $r2), $y + (int)round(sin(deg2rad($angle)) * $r2), $black);
    }
}

function draw_moon_icon($image, int $x, int $y, int $size, int $black, int $white): void {
    $radius = max(5, intdiv($size, 2));
    imagefilledellipse($image, $x, $y, $radius * 2, $radius * 2, $black);
    $cut = max(2, (int)round($radius * .55));
    imagefilledellipse($image, $x + $cut, $y - $cut, $radius * 2, $radius * 2, $white);
}

function draw_weather_icon($image, int $x, int $y, int $size, $code, int $black, int $grey, int $white): void {
    $code = (int)$code;
    $rain = weather_is_rainy($code);
    $cloud = in_array($code, [1, 2, 3, 45, 48, 51, 53, 55, 61, 63, 65, 80, 81, 82, 95, 96, 99], true);
    if (!$cloud) {
        imageellipse($image, $x, $y, $size, $size, $black);
        for ($angle = 0; $angle < 360; $angle += 45) {
            $r1 = (int)($size * .7); $r2 = (int)($size * 1.05);
            imageline($image, $x + (int)(cos(deg2rad($angle)) * $r1), $y + (int)(sin(deg2rad($angle)) * $r1), $x + (int)(cos(deg2rad($angle)) * $r2), $y + (int)(sin(deg2rad($angle)) * $r2), $black);
        }
        return;
    }
    imageellipse($image, $x - (int)($size * .25), $y, (int)($size * .85), (int)($size * .65), $black);
    imageellipse($image, $x + (int)($size * .22), $y - (int)($size * .1), (int)($size * .95), (int)($size * .75), $black);
    imageline($image, $x - (int)($size * .7), $y + (int)($size * .3), $x + (int)($size * .75), $y + (int)($size * .3), $black);
    if ($rain) {
        for ($drop = -1; $drop <= 1; $drop++) imageline($image, $x + $drop * (int)($size * .35), $y + (int)($size * .52), $x + $drop * (int)($size * .35) - 4, $y + (int)($size * .78), $grey);
    }
}

function draw_service_icon($image, string $source, int $x, int $y, int $black, int $white, int $sizeOffset = 0): void {
    $files = ['claude' => 'claudecode.png', 'deepseek' => 'deepseek.png', 'codex' => 'openai.png'];
    $path = ICON_DIR . '/' . ($files[$source] ?? $files['codex']);
    $icon = is_readable($path) ? @imagecreatefrompng($path) : false;
    if ($icon !== false) {
        $sizes = ['claude' => 68, 'deepseek' => 60, 'codex' => 80];
        $size = max(1, ($sizes[$source] ?? 60) + $sizeOffset);
        imagecopyresampled($image, $icon, $x - (int)($size / 2), $y - (int)($size / 2), 0, 0, $size, $size, imagesx($icon), imagesy($icon));
        imagedestroy($icon);
        return;
    }
    $fallbackSize = max(1, 28 + $sizeOffset);
    imageellipse($image, $x, $y, $fallbackSize, $fallbackSize, $black);
}

function account_metric(array $account, string $key): ?array {
    $metric = $account[$key] ?? null;
    if (!is_array($metric) || !is_numeric($metric['used'] ?? null)) return null;
    return ['used' => max(0, min(100, (int)$metric['used'])), 'reset_at' => is_numeric($metric['reset_at'] ?? null) ? (int)$metric['reset_at'] : null];
}

function codex_display_name(string $name): string {
    $names = ['Codex 1' => 'Codex A', 'Codex 2' => 'Codex B', 'Codex 3' => 'Codex C'];
    return $names[$name] ?? $name;
}

function reset_time_label(?int $timestamp, int $maxAheadSeconds): string {
    $now = time();
    if (!$timestamp || $timestamp <= $now || $timestamp > $now + $maxAheadSeconds) return '--';
    return date('m-d H:i', $timestamp);
}

function html_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iphone_weather_emoji($code): string {
    $code = (int)$code;
    if (in_array($code, [95, 96, 99], true)) return '⛈️';
    if (weather_is_rainy($code)) return '🌧️';
    if (in_array($code, [45, 48], true)) return '🌫️';
    if (in_array($code, [1, 2], true)) return '🌤️';
    if ($code === 3) return '☁️';
    return '☀️';
}

function iphone_metric_html(string $label, ?array $metric, int $maxAheadSeconds): string {
    $used = is_array($metric) && is_numeric($metric['used'] ?? null) ? max(0, min(100, (int)$metric['used'])) : null;
    $width = $used ?? 0;
    $percentage = $used === null ? '--' : $used . '%';
    $reset = reset_time_label(is_array($metric) ? ($metric['reset_at'] ?? null) : null, $maxAheadSeconds);
    return '<div class="metric-row">'
        . '<span class="metric-label">' . html_escape($label) . '</span>'
        . '<span class="metric-track"><i style="width:' . $width . '%"></i></span>'
        . '<strong>' . html_escape($percentage) . '</strong>'
        . '<small>' . html_escape($reset) . '</small>'
        . '</div>';
}

function server_status_file(string $id): string {
    return STATE_DIR . ($id === 'local' ? '/server-status.json' : '/server-status-' . $id . '.json');
}

function server_statuses(array $config): array {
    $statuses = [];
    foreach ($config['servers'] ?? [] as $server) {
        if (!is_array($server)) continue;
        $id = (string)($server['id'] ?? '');
        $content = @file_get_contents(server_status_file($id));
        $status = is_string($content) ? json_decode($content, true) : null;
        $statuses[] = [
            'id' => $id,
            'label' => (string)($server['label'] ?? $id),
            'status' => is_array($status) ? $status : [],
        ];
    }
    return $statuses;
}

function server_status(array $config): array {
    $statuses = server_statuses($config);
    return is_array($statuses[0]['status'] ?? null) ? $statuses[0]['status'] : [];
}

function status_gib($bytes): string {
    if (!is_numeric($bytes) || (float)$bytes < 0) return '--';
    return number_format((float)$bytes / 1073741824, 1) . ' GB';
}

function status_percent($used, $total): int {
    if (!is_numeric($used) || !is_numeric($total) || (float)$total <= 0) return 0;
    return max(0, min(100, (int)round((float)$used * 100 / (float)$total)));
}

function iphone_status_metric_html(string $label, ?float $percentage, string $value): string {
    $percentage = $percentage === null ? 0 : max(0, min(100, (int)round($percentage)));
    return '<div class="server-metric"><div><span>' . html_escape($label) . '</span><b>' . html_escape($value) . '</b></div><i><em style="width:' . $percentage . '%"></em></i></div>';
}

function iphone_server_html(string $label, array $server): string {
    $serverUpdatedAt = is_string($server['updated_at'] ?? null) ? strtotime($server['updated_at']) : false;
    $serverFresh = is_int($serverUpdatedAt) && $serverUpdatedAt > time() - 180;
    $cpuPercent = is_numeric($server['cpu_percent'] ?? null) ? max(0, min(100, (float)$server['cpu_percent'])) : null;
    $memory = is_array($server['memory'] ?? null) ? $server['memory'] : [];
    $memoryUsed = is_numeric($memory['used_bytes'] ?? null) ? (float)$memory['used_bytes'] : null;
    $memoryTotal = is_numeric($memory['total_bytes'] ?? null) ? (float)$memory['total_bytes'] : null;
    $disk = is_array($server['disk'] ?? null) ? $server['disk'] : [];
    $diskUsed = is_numeric($disk['used_bytes'] ?? null) ? (float)$disk['used_bytes'] : null;
    $diskTotal = is_numeric($disk['total_bytes'] ?? null) ? (float)$disk['total_bytes'] : null;
    $load = is_array($server['load'] ?? null) ? $server['load'] : [];
    $docker = is_array($server['docker'] ?? null) ? $server['docker'] : [];
    $containers = is_array($docker['containers'] ?? null) ? $docker['containers'] : [];

    $containerHtml = '';
    foreach (array_slice($containers, 0, 8) as $container) {
        if (!is_array($container)) continue;
        $name = is_string($container['name'] ?? null) ? $container['name'] : '未命名容器';
        $status = is_string($container['status'] ?? null) ? $container['status'] : '--';
        $running = !empty($container['running']);
        $containerHtml .= '<li><i class="' . ($running ? 'running' : 'stopped') . '"></i><span>' . html_escape($name) . '</span><small>' . html_escape($status) . '</small></li>';
    }
    $dockerHtml = '';
    if (!empty($docker['available'])) {
        if ($containerHtml === '') $containerHtml = '<li><i class="stopped"></i><span>Docker 容器</span><small>暂无</small></li>';
        $dockerHtml = '<div class="docker-head"><b>Docker 容器</b><span>' . count($containers) . ' 个</span></div><ul class="container-list">' . $containerHtml . '</ul>';
    }

    $loadText = is_numeric($load['one'] ?? null) && is_numeric($load['five'] ?? null) && is_numeric($load['fifteen'] ?? null)
        ? number_format((float)$load['one'], 2) . ' / ' . number_format((float)$load['five'], 2) . ' / ' . number_format((float)$load['fifteen'], 2)
        : '--';
    $serverUpdatedText = $serverFresh ? '更新 ' . date('H:i', $serverUpdatedAt) : '等待采集';
    $serverStateText = $serverFresh ? '正常' : '采集中';
    $cpuValue = $cpuPercent === null ? '--' : round($cpuPercent) . '%';
    $memoryValue = $memoryUsed === null || $memoryTotal === null ? '--' : status_gib($memoryUsed) . ' / ' . status_gib($memoryTotal);
    $diskValue = $diskUsed === null || $diskTotal === null ? '--' : status_gib($diskUsed) . ' / ' . status_gib($diskTotal);
    return '<section class="section server"><div class="section-head"><h2>' . html_escape($label) . '</h2><span class="status-pill ' . ($serverFresh ? 'healthy' : 'waiting') . '"><i></i>' . $serverStateText . '</span></div>'
        . '<div class="server-meta"><span>最近采集：' . html_escape($serverUpdatedText) . '</span><span>负载：' . html_escape($loadText) . '</span></div>'
        . '<div class="server-metrics">'
        . iphone_status_metric_html('CPU', $cpuPercent, $cpuValue)
        . iphone_status_metric_html('内存', $memoryUsed === null || $memoryTotal === null ? null : status_percent($memoryUsed, $memoryTotal), $memoryValue)
        . iphone_status_metric_html('磁盘', $diskUsed === null || $diskTotal === null ? null : status_percent($diskUsed, $diskTotal), $diskValue)
        . '</div>' . $dockerHtml . '</section>';
}

function widget_quota_metric(?array $metric, int $maxAheadSeconds): array {
    $used = is_array($metric) && is_numeric($metric['used'] ?? null) ? max(0, min(100, (int)$metric['used'])) : null;
    return [
        'used' => $used,
        'reset' => reset_time_label(is_array($metric) ? ($metric['reset_at'] ?? null) : null, $maxAheadSeconds),
    ];
}

function widget_server_metric($used, $total): array {
    $known = is_numeric($used) && is_numeric($total) && (float)$total > 0;
    return [
        'percentage' => $known ? status_percent($used, $total) : null,
        'label' => $known ? status_gib($used) . ' / ' . status_gib($total) : '--',
    ];
}

function widget_payload(array $device, array $config): array {
    $state = state_with_weather($config);
    $weather = is_array($state['weather'] ?? null) ? $state['weather'] : [];
    $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : [];
    $forecastPayload = [];
    foreach ([1 => '明天', 2 => '后天'] as $index => $label) {
        $entry = is_array($forecast[$index] ?? null) ? $forecast[$index] : [];
        $forecastPayload[] = [
            'label' => $label,
            'emoji' => iphone_weather_emoji($entry['code'] ?? null),
            'condition' => weather_label($entry['code'] ?? null),
            'high' => is_numeric($entry['high'] ?? null) ? round((float)$entry['high']) : null,
            'low' => is_numeric($entry['low'] ?? null) ? round((float)$entry['low']) : null,
            'rain_probability' => is_numeric($entry['rain_probability'] ?? null) ? round((float)$entry['rain_probability']) : null,
        ];
    }

    $server = server_status($config);
    $serverUpdatedAt = is_string($server['updated_at'] ?? null) ? strtotime($server['updated_at']) : false;
    $serverFresh = is_int($serverUpdatedAt) && $serverUpdatedAt > time() - 180;
    $cpuPercent = is_numeric($server['cpu_percent'] ?? null) ? max(0, min(100, (int)round((float)$server['cpu_percent']))) : null;
    $memory = is_array($server['memory'] ?? null) ? $server['memory'] : [];
    $disk = is_array($server['disk'] ?? null) ? $server['disk'] : [];
    $docker = is_array($server['docker'] ?? null) ? $server['docker'] : [];
    $containers = is_array($docker['containers'] ?? null) ? $docker['containers'] : [];
    $containerPayload = [];
    foreach (array_slice($containers, 0, 4) as $container) {
        if (!is_array($container)) continue;
        $containerPayload[] = [
            'name' => substr((string)($container['name'] ?? '未命名容器'), 0, 48),
            'status' => substr((string)($container['status'] ?? '--'), 0, 48),
            'running' => !empty($container['running']),
        ];
    }

    $accounts = [];
    foreach (quota_accounts($state) as $account) {
        $accounts[] = [
            'source' => (string)($account['source'] ?? ''),
            'name' => substr(codex_display_name((string)($account['name'] ?? '--')), 0, 32),
            'plan' => substr((string)($account['plan'] ?? ''), 0, 20),
            'summary' => substr((string)($account['summary'] ?? '--'), 0, 80),
            'five_hour' => widget_quota_metric(account_metric($account, 'five_hour'), 18300),
            'seven_day' => widget_quota_metric(account_metric($account, 'seven_day'), 605100),
            'fable' => widget_quota_metric(account_metric($account, 'fable'), 605100),
        ];
    }

    return [
        'generated_at' => date(DATE_ATOM),
        'refresh_minutes' => max(1, (int)($config['DASHBOARD_REFRESH_MINUTES'] ?? 15)),
        'weather' => [
            'city' => (string)($weather['city'] ?? $config['DASHBOARD_CITY_LABEL'] ?? '北海'),
            'emoji' => iphone_weather_emoji($weather['code'] ?? null),
            'condition' => weather_label($weather['code'] ?? null),
            'temperature' => is_numeric($weather['temperature'] ?? null) ? round((float)$weather['temperature']) : null,
            'high' => is_numeric($weather['high'] ?? null) ? round((float)$weather['high']) : null,
            'low' => is_numeric($weather['low'] ?? null) ? round((float)$weather['low']) : null,
            'humidity' => is_numeric($weather['humidity'] ?? null) ? round((float)$weather['humidity']) : null,
            'wind_level' => wind_level($weather['wind'] ?? null),
            'aqi' => is_numeric($weather['aqi'] ?? null) ? (int)round((float)$weather['aqi']) : null,
            'aqi_label' => air_quality_label($weather['aqi'] ?? null),
            'uv_index' => is_numeric($weather['uv_index'] ?? null) ? (float)$weather['uv_index'] : null,
            'uv_level' => uv_level_label($weather['uv_index'] ?? null),
            'forecast' => $forecastPayload,
            'advice' => weather_advice($weather, $forecast),
        ],
        'server' => [
            'fresh' => $serverFresh,
            'state' => $serverFresh ? '正常' : '采集中',
            'updated' => $serverFresh ? date('H:i', $serverUpdatedAt) : '--',
            'cpu' => ['percentage' => $cpuPercent, 'label' => $cpuPercent === null ? '--' : $cpuPercent . '%'],
            'memory' => widget_server_metric($memory['used_bytes'] ?? null, $memory['total_bytes'] ?? null),
            'disk' => widget_server_metric($disk['used_bytes'] ?? null, $disk['total_bytes'] ?? null),
            'docker' => [
                'available' => !empty($docker['available']),
                'count' => count($containers),
                'containers' => $containerPayload,
            ],
        ],
        'accounts' => $accounts,
    ];
}

function month_calendar_payload(int $timestamp): array {
    $year = (int)date('Y', $timestamp);
    $month = (int)date('n', $timestamp);
    $today = (int)date('j', $timestamp);
    $firstDay = mktime(12, 0, 0, $month, 1, $year);
    $firstWeekday = (int)date('w', $firstDay);
    $days = (int)date('t', $timestamp);
    $cells = [];
    $count = max(35, (int)(ceil(($firstWeekday + $days) / 7) * 7));
    for ($index = 0; $index < $count; $index++) {
        $day = $index - $firstWeekday + 1;
        if ($day < 1 || $day > $days) {
            $cells[] = null;
            continue;
        }
        $lunar = lunar_info(mktime(12, 0, 0, $month, $day, $year));
        $cells[] = ['day' => $day, 'lunar' => $lunar['short'] ?? '', 'today' => $day === $today];
    }
    return [
        'year' => $year,
        'month' => $month,
        'label' => date('Y年n月', $timestamp),
        'day' => $today,
        'weekday' => ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', $timestamp)],
        'lunar' => lunar_info($timestamp)['text'] ?? '农历 --',
        'cells' => $cells,
    ];
}

function public_calendar_weather_payload(array $config): array {
    $weather = public_weather_for_city($config, public_weather_city($config));
    $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : [];
    $forecastPayload = [];
    foreach (array_slice($forecast, 1, 6) as $index => $entry) {
        if (!is_array($entry)) continue;
        $date = is_string($entry['date'] ?? null) ? $entry['date'] : '';
        $dayTimestamp = $date === '' ? null : strtotime($date . ' 12:00:00');
        $label = $index === 0 ? '明天' : ($index === 1 ? '后天' : ($dayTimestamp ? '周' . ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', $dayTimestamp)] : '--'));
        $forecastPayload[] = [
            'label' => $label,
            'emoji' => iphone_weather_emoji($entry['code'] ?? null),
            'condition' => weather_label($entry['code'] ?? null),
            'high' => is_numeric($entry['high'] ?? null) ? round((float)$entry['high']) : null,
            'low' => is_numeric($entry['low'] ?? null) ? round((float)$entry['low']) : null,
            'rain_probability' => is_numeric($entry['rain_probability'] ?? null) ? round((float)$entry['rain_probability']) : null,
            'wind_level' => wind_level($entry['wind'] ?? null),
        ];
    }
    $now = time();
    return [
        'generated_at' => date(DATE_ATOM, $now),
        'refresh_minutes' => 15,
        'calendar' => month_calendar_payload($now),
        'weather' => [
            'city' => (string)($weather['city'] ?? $config['DASHBOARD_CITY_LABEL'] ?? '北海'),
            'emoji' => iphone_weather_emoji($weather['code'] ?? null),
            'condition' => weather_label($weather['code'] ?? null),
            'temperature' => is_numeric($weather['temperature'] ?? null) ? round((float)$weather['temperature']) : null,
            'high' => is_numeric($weather['high'] ?? null) ? round((float)$weather['high']) : null,
            'low' => is_numeric($weather['low'] ?? null) ? round((float)$weather['low']) : null,
            'humidity' => is_numeric($weather['humidity'] ?? null) ? round((float)$weather['humidity']) : null,
            'wind_level' => wind_level($weather['wind'] ?? null),
            'aqi' => is_numeric($weather['aqi'] ?? null) ? (int)round((float)$weather['aqi']) : null,
            'aqi_label' => air_quality_label($weather['aqi'] ?? null),
            'uv_index' => is_numeric($weather['uv_index'] ?? null) ? (float)$weather['uv_index'] : null,
            'uv_level' => uv_level_label($weather['uv_index'] ?? null),
            'sunrise' => weather_time_label($weather['sunrise'] ?? null),
            'sunset' => weather_time_label($weather['sunset'] ?? null),
            'forecast' => $forecastPayload,
            'advice' => weather_advice($weather, $forecast),
        ],
    ];
}

function render_public_calendar_weather_payload(array $config): void {
    header('Cache-Control: public, max-age=300');
    json_response(public_calendar_weather_payload($config));
}

function render_public_calendar_weather_viewer(array $config): void {
    $payload = public_calendar_weather_payload($config);
    $calendar = $payload['calendar'];
    $weather = $payload['weather'];
    $calendarHtml = '';
    foreach ($calendar['cells'] as $cell) {
        if (!is_array($cell)) {
            $calendarHtml .= '<span class="calendar-day blank"></span>';
            continue;
        }
        $calendarHtml .= '<span class="calendar-day' . (!empty($cell['today']) ? ' today' : '') . '"><b>' . html_escape($cell['day']) . '</b><small>' . html_escape($cell['lunar']) . '</small></span>';
    }
    $forecastHtml = '';
    foreach ($weather['forecast'] as $entry) {
        $rain = $entry['rain_probability'] === null ? '--' : $entry['rain_probability'] . '%';
        $wind = $entry['wind_level'] === null ? '--' : $entry['wind_level'] . '级';
        $forecastHtml .= '<article class="forecast"><span>' . html_escape($entry['label']) . '</span><b>' . html_escape($entry['emoji']) . '</b><small class="forecast-condition">' . html_escape($entry['condition']) . '</small><strong>' . html_escape($entry['low'] ?? '--') . '° / ' . html_escape($entry['high'] ?? '--') . '°</strong><em><span>降雨 ' . html_escape($rain) . '</span><span>风力 ' . html_escape($wind) . '</span></em></article>';
    }
    $temperature = $weather['temperature'] === null ? '--' : $weather['temperature'];
    $high = $weather['high'] === null ? '--' : $weather['high'];
    $low = $weather['low'] === null ? '--' : $weather['low'];
    $humidity = $weather['humidity'] === null ? '--' : $weather['humidity'] . '%';
    $wind = $weather['wind_level'] === null ? '--' : $weather['wind_level'] . '级';
    $air = (string)$weather['aqi_label'];
    $airTone = air_quality_tone($weather['aqi'] ?? null);
    $uvTone = uv_level_tone($weather['uv_index'] ?? null);
    $lunarText = (string)$calendar['lunar'];
    $lunarHtml = html_escape($lunarText);
    if (preg_match('/^(.+?年)(.+)$/u', $lunarText, $lunarParts)) {
        $lunarHtml = html_escape($lunarParts[1]) . '<br>' . html_escape($lunarParts[2]);
    }
    $refreshMilliseconds = $payload['refresh_minutes'] * 60000;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo <<<'HTML'
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#eff5ff"><title>日历天气</title><style>
:root{color-scheme:light;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Helvetica Neue",sans-serif;color:#16253e;background:#eaf1fc}*{box-sizing:border-box}body{margin:0;min-height:100svh;background:radial-gradient(circle at 85% 0,#cee4ff 0,transparent 26rem),linear-gradient(160deg,#f8fbff,#edf3fb 55%,#e3ecfb)}.shell{width:min(100%,440px);min-height:100svh;margin:auto;padding:calc(env(safe-area-inset-top) + 18px) 14px calc(env(safe-area-inset-bottom) + 24px)}.topline{display:flex;justify-content:space-between;align-items:baseline;padding:0 5px 13px;color:#65758d;font-size:12px}.topline b{color:#182845;font-size:15px}.panel{border:1px solid #dbe5f3;border-radius:24px;background:#fff;box-shadow:0 10px 26px #24426a12}.calendar-panel{display:grid;grid-template-columns:130px minmax(0,1fr);gap:7px;padding:15px}.date-summary{padding:8px 4px 4px;text-align:center}.date-summary strong{display:block;margin:9px 0 1px;font-size:59px;line-height:.92;letter-spacing:-.07em}.date-summary b{display:block;font-size:18px}.date-summary span{display:block;margin-top:8px;color:#677891;font-size:12px;line-height:1.45}.month-title{padding:4px 0 7px;text-align:center;font-size:14px;font-weight:750}.weekdays,.calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;text-align:center}.weekdays{color:#77869b;font-size:10px}.calendar-grid{margin-top:5px}.calendar-day{position:relative;display:grid;place-items:center;min-height:31px;color:#24344e}.calendar-day b{font-size:13px;line-height:1}.calendar-day small{display:block;margin-top:2px;color:#8290a3;font-size:8px;line-height:1}.calendar-day.today:before{position:absolute;inset:1px 0;border:1.5px solid #3f6ea9;border-radius:9px;content:""}.calendar-day.today small{color:#3f6ea9}.weather-panel{margin-top:13px;overflow:hidden;background:linear-gradient(135deg,#1a3157,#315f96 59%,#4a9bc1);color:#fff;box-shadow:0 15px 28px #1d3d6922}.weather-head{display:flex;justify-content:space-between;align-items:center;padding:17px 18px 5px}.city{font-size:14px;font-weight:750}.updated{color:#d3e9ff;font-size:11px}.current{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;padding:6px 18px 16px}.weather-icon{font-size:47px;line-height:1}.temperature{font-size:50px;font-weight:800;letter-spacing:-.07em;line-height:.9}.temperature small{margin-left:3px;font-size:17px;letter-spacing:0}.condition{margin-top:6px;color:#d8e9ff;font-size:13px;font-weight:650}.range{font-size:15px;font-weight:700;white-space:nowrap}.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;padding:0 14px 16px}.metrics span{padding:9px 7px;border-radius:12px;background:#ffffff18;color:#d5e7fd;font-size:10px;line-height:1.35}.metrics b{display:block;color:#fff;font-size:13px;white-space:nowrap}.sun{display:flex;justify-content:space-between;margin:0 16px 16px;padding:10px 3px 0;border-top:1px solid #ffffff3a;color:#d8e9ff;font-size:11px}.sun b{color:#fff}.forecast-panel{margin-top:13px;padding:16px 0 14px}.section-title{display:flex;justify-content:space-between;align-items:baseline;padding:0 17px 12px}.section-title h2{margin:0;font-size:16px}.section-title span{color:#77879e;font-size:11px}.forecasts{display:grid;grid-template-columns:repeat(6,minmax(78px,1fr));gap:8px;overflow-x:auto;padding:0 16px 4px;scrollbar-width:none}.forecasts::-webkit-scrollbar{display:none}.forecast{min-width:78px;padding:10px 7px;border-radius:15px;background:#f1f6fc;color:#61718a;text-align:center}.forecast>span{font-size:11px;font-weight:700}.forecast b{display:block;margin:5px 0 3px;font-size:24px;line-height:1}.forecast strong{display:block;color:#1d2f4d;font-size:16px}.forecast small,.forecast em{display:block;margin-top:3px;font-size:10px;font-style:normal;line-height:1.35}.forecast em{color:#7186a2}.advice{margin:12px 16px 1px;padding:10px 11px;border-radius:13px;background:#edf7f5;color:#3b716b;font-size:12px;line-height:1.5}.footer{padding:13px 2px 0;text-align:center;color:#7d8ca2;font-size:11px}@media(max-width:380px){.shell{padding-left:10px;padding-right:10px}.calendar-panel{grid-template-columns:119px minmax(0,1fr);padding:12px}.date-summary strong{font-size:54px}.calendar-day{min-height:29px}.metrics{gap:5px;padding-left:11px;padding-right:11px}.metrics span{padding:8px 5px;font-size:9px}.metrics b{font-size:12px}.current{padding-left:14px;padding-right:14px;gap:9px}.weather-icon{font-size:42px}.temperature{font-size:45px}.forecast-panel{padding-top:14px}.forecasts{grid-template-columns:repeat(3,minmax(0,1fr));overflow:visible;padding-bottom:0}.forecast{min-width:0}.forecast:nth-child(n+4){margin-top:1px}}</style></head><body><main class="shell">
HTML;
    echo '<style>
    @font-face{font-family:"Shunfeng";src:url("/assets/dashboard-font.ttf?v=1") format("truetype");font-display:swap}:root{font-family:"Shunfeng",-apple-system,BlinkMacSystemFont,"PingFang SC",sans-serif}
    body{background:radial-gradient(circle at 82% -6%,#d7e8ff 0,transparent 27rem),linear-gradient(160deg,#f9fbff 0%,#eff4fb 56%,#e6effb 100%)}
    .panel{border-color:#dce7f4;border-radius:22px;box-shadow:0 8px 22px #24426a0f}
    .calendar-panel{grid-template-columns:122px minmax(0,1fr);gap:13px;align-items:stretch;padding:12px 16px}
    .date-summary{padding:5px 2px;text-align:center;color:#20344f}.date-summary strong{margin:7px 0 2px;font-size:76px}.date-summary b{font-size:23px}.date-summary span{color:#20344f;font-size:15px}.date-summary .date-month{margin:0;font-size:26px;font-weight:750;letter-spacing:-.06em;white-space:nowrap}.date-summary>span:not(.date-month){font-size:20px;line-height:1.1}.calendar-detail{min-width:0;padding-left:12px;border-left:1px solid #e7eef7}.weekdays{padding-top:1px;font-size:17px}.calendar-grid{margin-top:2px}.calendar-day{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0;min-height:36px;line-height:1}.calendar-day b{font-size:17px;line-height:.9}.calendar-day small{margin-top:0;font-size:13px;line-height:.95}
    .weather-panel{background:linear-gradient(135deg,#fff,#f7faff);color:#16253e;box-shadow:0 8px 22px #24426a0f}.weather-head{justify-content:space-between;min-height:35px;padding:10px 19px 2px}.city{color:#244b76;font-size:19px;letter-spacing:.01em;white-space:nowrap}.updated{color:#7889a0;font-size:14px}.current{grid-template-columns:repeat(2,minmax(0,1fr));gap:0;padding:5px 14px 18px;text-align:center}.weather-now,.current-center{display:flex;flex-direction:column;align-items:center;justify-content:center}.weather-icon{font-size:58px}.temperature{font-size:68px}.temperature small{font-size:22px}.condition{margin-top:4px;color:#6a7b92;font-size:27px}.range{display:block;color:#20344f;font-size:30px;line-height:1.2;text-align:center}.metrics{grid-template-columns:repeat(2,1fr);gap:7px;padding:0 14px 16px}.metrics span{display:flex;align-items:center;justify-content:space-between;gap:0;padding:10px 9px;border:1px solid #e4ecf6;background:#f2f6fb;color:#6a7c94;font-size:25px;line-height:1.25;white-space:nowrap}.metrics b{display:inline;color:#1f3450;font-size:29px}.metrics b.air-excellent{color:#167d6a}.metrics b.air-good{color:#4d8e39}.metrics b.air-light{color:#b78219}.metrics b.air-moderate{color:#c46322}.metrics b.air-heavy{color:#bd4052}.metrics b.air-severe{color:#7b3f8f}.metrics b.air-unknown{color:#1f3450}.metrics b.uv-low{color:#9a8bd3}.metrics b.uv-moderate{color:#856fc1}.metrics b.uv-high{color:#704eb2}.metrics b.uv-very-high{color:#5e3a95}.metrics b.uv-extreme{color:#48276f}.metrics b.uv-unknown{color:#1f3450}.sun{margin:0 17px 17px;border-color:#e6edf6;color:#6a7c94;font-size:21px}.sun b{color:#1f3450}
    .forecast-panel{padding:16px 0 15px}.section-title{padding-bottom:11px}.forecasts{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;overflow:visible;padding:0 16px 3px}.forecast{min-width:0;padding:13px 5px;border:1px solid #edf2f8;border-radius:14px;background:#f3f7fc}.forecast:nth-child(n+4){margin-top:0}.forecast>span{font-size:25px}.forecast b{font-size:45px}.forecast .forecast-condition{display:block;margin-top:3px;color:#1d2f4d;font-size:21px;line-height:1.2}.forecast strong{margin-top:4px;font-size:25px;line-height:1.2;letter-spacing:-.06em;white-space:nowrap}.forecast em{font-size:21px;line-height:1.35}.forecast em span{display:block;margin-top:2px}.forecast-panel .advice{margin:0 16px 12px;border:1px solid #daf0ec;background:linear-gradient(135deg,#edf8f6,#f4faf9);font-size:27px;line-height:1.45}.footer{font-size:15px}
    @media(max-width:380px){.shell{padding-left:10px;padding-right:10px}.calendar-panel{grid-template-columns:120px minmax(0,1fr);gap:10px;padding:12px}.calendar-detail{padding-left:9px}.date-summary strong{font-size:72px}.date-summary .date-month{font-size:26px}.date-summary>span:not(.date-month){font-size:20px}.calendar-day{min-height:36px}.metrics{gap:7px;padding-left:11px;padding-right:11px}.metrics span{padding:9px 9px;font-size:25px}.metrics b{font-size:29px}.current{padding-left:14px;padding-right:14px;gap:0}.weather-icon{font-size:56px}.temperature{font-size:66px}.condition{font-size:27px}.range{font-size:30px}.forecast-panel{padding-top:14px}.forecast{padding-left:4px;padding-right:4px}.forecast>span{font-size:25px}.forecast b{font-size:45px}.forecast .forecast-condition{font-size:21px}.forecast strong{font-size:25px}.forecast em{font-size:21px}}
    </style>';
    echo '<style>.metrics b.air-excellent{color:#15803D}.metrics b.air-good{color:#5C8A3A}.metrics b.air-light{color:#B7791F}.metrics b.air-moderate{color:#C05621}.metrics b.air-heavy{color:#C53030}.metrics b.air-severe{color:#7F1D1D}.metrics b.uv-low{color:#A78BFA}.metrics b.uv-moderate{color:#8B5CF6}.metrics b.uv-high{color:#7C3AED}.metrics b.uv-very-high{color:#5B21B6}.metrics b.uv-extreme{color:#4C1D3D}</style>';
    echo '<section class="panel calendar-panel"><div class="date-summary"><span class="date-month">' . html_escape($calendar['label']) . '</span><strong>' . html_escape($calendar['day']) . '</strong><b>星期' . html_escape($calendar['weekday']) . '</b><span>' . $lunarHtml . '</span></div><div class="calendar-detail"><div class="weekdays"><span>日</span><span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span></div><div class="calendar-grid">' . $calendarHtml . '</div></div></section>';
    echo '<section class="panel weather-panel"><div class="weather-head"><span class="city">📍 ' . html_escape($weather['city']) . '</span><span class="updated">每 ' . html_escape($payload['refresh_minutes']) . ' 分钟刷新</span></div><div class="current"><div class="weather-now"><span class="weather-icon">' . html_escape($weather['emoji']) . '</span><span class="condition">' . html_escape($weather['condition']) . '</span></div><div class="current-center"><div class="temperature">' . html_escape($temperature) . '<small>°C</small></div><span class="range">' . html_escape($low) . '° / ' . html_escape($high) . '°</span></div></div><div class="metrics"><span>湿度:<b>' . html_escape($humidity) . '</b></span><span>风力:<b>' . html_escape($wind) . '</b></span><span>空气:<b class="air-' . html_escape($airTone) . '">' . html_escape($air) . '</b></span><span>紫外线:<b class="uv-' . html_escape($uvTone) . '">' . html_escape($weather['uv_level']) . '</b></span></div><div class="sun"><span>☀ 日出 <b>' . html_escape($weather['sunrise']) . '</b></span><span>☾ 日落 <b>' . html_escape($weather['sunset']) . '</b></span></div></section>';
    echo '<section class="panel forecast-panel"><div class="advice">☂ ' . html_escape($weather['advice']) . '</div><div class="forecasts">' . $forecastHtml . '</div></section>';
    echo '<footer class="footer">天气日历 · 更新于 ' . html_escape(date('H:i')) . '</footer></main><script>setInterval(function(){location.reload();},' . $refreshMilliseconds . ');document.addEventListener("visibilitychange",function(){if(!document.hidden)location.reload();});</script></body></html>';
    exit;
}

function render_widget_payload(array $device, array $config): void {
    json_response(widget_payload($device, $config));
}

function render_iphone_viewer(array $device, string $id, string $token, array $config): void {
    $state = state_with_weather($config);
    $weather = is_array($state['weather'] ?? null) ? $state['weather'] : [];
    $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : [];
    $now = time();
    $lunar = lunar_info($now);
    $refreshMinutes = max(1, (int)($config['DASHBOARD_REFRESH_MINUTES'] ?? 15));
    $temperature = is_numeric($weather['temperature'] ?? null) ? round((float)$weather['temperature']) : '--';
    $low = is_numeric($weather['low'] ?? null) ? round((float)$weather['low']) : '--';
    $high = is_numeric($weather['high'] ?? null) ? round((float)$weather['high']) : '--';
    $wind = wind_level($weather['wind'] ?? null);
    $aqi = is_numeric($weather['aqi'] ?? null) ? (int)round((float)$weather['aqi']) : null;
    $uvLevel = uv_level_label($weather['uv_index'] ?? null);
    $city = $weather['city'] ?? $config['DASHBOARD_CITY_LABEL'] ?? '北海';
    $battery = $state['device_status'][$device['id']]['battery'] ?? null;
    $batteryText = is_numeric($battery) ? max(0, min(100, (int)$battery)) . '%' : '—';

    $forecastHtml = '';
    foreach ([1 => '明天', 2 => '后天'] as $index => $caption) {
        $entry = is_array($forecast[$index] ?? null) ? $forecast[$index] : [];
        $entryHigh = is_numeric($entry['high'] ?? null) ? round((float)$entry['high']) : '--';
        $entryLow = is_numeric($entry['low'] ?? null) ? round((float)$entry['low']) : '--';
        $rain = is_numeric($entry['rain_probability'] ?? null) ? round((float)$entry['rain_probability']) . '%' : '--';
        $forecastHtml .= '<div class="forecast-card">'
            . '<span>' . $caption . '</span>'
            . '<b>' . iphone_weather_emoji($entry['code'] ?? null) . '</b>'
            . '<strong>' . $entryHigh . '° / ' . $entryLow . '°</strong>'
            . '<small>' . html_escape(weather_label($entry['code'] ?? null)) . ' · 降雨 ' . $rain . '</small>'
            . '</div>';
    }

    $claudeHtml = '';
    $deepseekHtml = '';
    $codexHtml = '';
    foreach (quota_accounts($state) as $account) {
        $source = (string)($account['source'] ?? '');
        if ($source === 'claude') {
            $claudeHtml .= '<article class="ai-account claude">'
                . '<div class="account-heading"><img src="/assets/claudecode.png" alt=""><div><b>Claude Code</b><span>订阅额度</span></div></div>'
                . iphone_metric_html('5H', account_metric($account, 'five_hour'), 18300)
                . iphone_metric_html('7D', account_metric($account, 'seven_day'), 605100)
                . iphone_metric_html('Fable', account_metric($account, 'fable'), 605100)
                . '</article>';
            continue;
        }
        if ($source === 'deepseek') {
            $balance = preg_replace('/^Balance\s*/i', '', (string)$account['summary']);
            $deepseekHtml .= '<article class="ai-account deepseek">'
                . '<div class="account-heading"><img src="/assets/deepseek.png" alt=""><div><b>DeepSeek</b><span>官方 API 余额</span></div></div>'
                . '<strong class="balance">' . html_escape($balance) . '</strong>'
                . '</article>';
            continue;
        }
        if ($source === 'codex') {
            $plan = trim((string)($account['plan'] ?? ''));
            $codexHtml .= '<article class="ai-account codex">'
                . '<div class="account-heading"><img src="/assets/openai.png" alt=""><div><b>' . html_escape(codex_display_name((string)$account['name'])) . '</b><span>' . html_escape($plan === '' ? 'Codex' : $plan) . '</span></div></div>'
                . iphone_metric_html('5H', account_metric($account, 'five_hour'), 18300)
                . iphone_metric_html('7D', account_metric($account, 'seven_day'), 605100)
                . '</article>';
        }
    }
    if ($claudeHtml === '') $claudeHtml = '<article class="ai-account claude empty"><b>Claude Code</b><span>暂未接入</span></article>';
    if ($deepseekHtml === '') $deepseekHtml = '<article class="ai-account deepseek empty"><b>DeepSeek</b><span>暂未接入</span></article>';
    if ($codexHtml === '') $codexHtml = '<article class="ai-account codex empty"><b>Codex</b><span>暂未接入</span></article>';

    $serverHtml = '';
    foreach (server_statuses($config) as $serverEntry) {
        $serverHtml .= iphone_server_html((string)$serverEntry['label'], (array)$serverEntry['status']);
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#13223f"><title>今日看板</title><style>';
    echo <<<'CSS'
:root{color-scheme:light;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Helvetica Neue",sans-serif;background:#eaf0fb;color:#14213d}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 86% 0,#d7e8ff 0,transparent 27rem),linear-gradient(160deg,#eef4ff,#edf0f8 52%,#e5edff);min-height:100svh}.shell{width:min(100%,430px);min-height:100svh;margin:0 auto;padding:calc(env(safe-area-inset-top) + 20px) 18px calc(env(safe-area-inset-bottom) + 28px)}.topline{display:flex;justify-content:space-between;align-items:center;margin:0 3px 16px;color:#53647e;font-size:12px;font-weight:650;letter-spacing:.02em}.topline b{color:#14213d;font-size:14px}.hero{position:relative;overflow:hidden;border-radius:28px;padding:23px 22px 20px;background:linear-gradient(135deg,#172847,#253f71 57%,#3976a9);color:#fff;box-shadow:0 18px 34px #263d6b35}.hero:after{content:"";position:absolute;width:180px;height:180px;right:-65px;top:-70px;border-radius:50%;background:#73c6e6;opacity:.24}.city{position:relative;z-index:1;display:inline-flex;gap:7px;align-items:center;padding:6px 10px;border-radius:99px;background:#ffffff20;color:#e2f3ff;font-size:12px}.now{position:relative;z-index:1;display:flex;align-items:center;gap:14px;margin:14px 0 9px}.now .emoji{font-size:53px;line-height:1}.now strong{font-size:52px;letter-spacing:-.06em;line-height:.9}.now strong small{font-size:17px;letter-spacing:0;margin-left:3px}.now p{margin:5px 0 0;font-size:15px;font-weight:650}.range{position:relative;z-index:1;color:#d1e5ff;font-size:13px}.stats{position:relative;z-index:1;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:19px}.stats span{padding:10px 8px;border-radius:14px;background:#ffffff17;color:#d4e4ff;font-size:11px;line-height:1.45}.stats b{display:block;color:#fff;font-size:15px}.section{margin-top:16px;background:#fff;border:1px solid #dce5f2;border-radius:24px;box-shadow:0 8px 20px #1b315a0b}.section-head{display:flex;justify-content:space-between;align-items:baseline;padding:18px 18px 0}.section-head h2{margin:0;font-size:17px;letter-spacing:-.02em}.section-head span{font-size:12px;color:#74839b}.forecasts{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:14px 16px 17px}.forecast-card{padding:12px 10px;border-radius:17px;background:#f2f6fc;color:#5a6b83}.forecast-card>span{font-size:12px;font-weight:650}.forecast-card b{display:block;margin:5px 0 4px;font-size:27px;line-height:1}.forecast-card strong{display:block;color:#1d2e4a;font-size:14px}.forecast-card small{display:block;margin-top:4px;font-size:11px}.advice{display:flex;gap:10px;align-items:flex-start;margin:0 16px 17px;padding:12px 13px;border-radius:14px;background:#edf7f5;color:#3c6f69;font-size:12px;line-height:1.55}.advice b{font-size:15px;line-height:1}.server{overflow:hidden}.status-pill{display:inline-flex;gap:5px;align-items:center;font-weight:650}.status-pill i,.container-list li>i{width:7px;height:7px;border-radius:50%;background:#94a3b8}.status-pill.healthy{color:#188466}.status-pill.healthy i,.container-list .running{background:#25b68b}.status-pill.waiting{color:#a8741b}.server-meta{display:flex;justify-content:space-between;gap:12px;padding:12px 17px 2px;color:#718199;font-size:11px}.server-metrics{display:grid;gap:11px;padding:12px 17px 15px}.server-metric>div{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;font-size:12px}.server-metric span{color:#687991}.server-metric b{color:#2a3c58;font-size:12px}.server-metric>i{display:block;height:7px;border-radius:99px;overflow:hidden;background:#e6edf6}.server-metric em{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#4d77c5,#7ac8b3)}.docker-head{display:flex;justify-content:space-between;align-items:center;padding:13px 17px 9px;border-top:1px solid #edf1f7}.docker-head b{font-size:13px}.docker-head span{color:#76879e;font-size:11px}.container-list{margin:0;padding:0 17px 14px;list-style:none}.container-list li{display:grid;grid-template-columns:8px minmax(0,1fr) auto;gap:8px;align-items:center;padding:9px 0;border-top:1px solid #eff3f8}.container-list li>i{width:7px;height:7px}.container-list .stopped{background:#e3a23c}.ai{padding-bottom:4px}.ai-title{padding-bottom:12px}.ai-title span{color:#647492}.ai-account{margin:0 14px 12px;padding:14px;border:1px solid #e2eaf4;border-radius:18px;background:#fbfcff}.account-heading{display:flex;align-items:center;gap:10px;margin-bottom:12px}.account-heading img{width:29px;height:29px;object-fit:contain}.account-heading b,.empty b{display:block;font-size:15px}.account-heading span,.empty span{display:block;margin-top:2px;color:#7f8da3;font-size:11px}.metric-row{display:grid;grid-template-columns:26px minmax(0,1fr) 35px 67px;gap:7px;align-items:center;margin-top:8px}.metric-label,.metric-row small{font-size:11px;color:#718199}.metric-row strong{font-size:12px;text-align:right;color:#30425e}.metric-row small{text-align:right;white-space:nowrap}.metric-track{height:7px;border-radius:99px;overflow:hidden;background:#e5ebf4}.metric-track i{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#3c67b9,#79b9cb)}.claude .metric-track i{background:linear-gradient(90deg,#d67347,#f0aa68)}.deepseek{display:flex;align-items:center;justify-content:space-between}.deepseek .account-heading{margin:0}.balance{color:#145b7e;font-size:20px;letter-spacing:-.03em}.empty{display:flex;justify-content:space-between;align-items:center}.footer{padding:5px 3px 0;text-align:center;color:#8391a5;font-size:11px}@media (min-width:700px){body{padding:24px}.shell{border-radius:34px;box-shadow:0 24px 60px #26385b25;min-height:calc(100svh - 48px)}}@media (prefers-color-scheme:dark){:root{color-scheme:dark;background:#101725;color:#edf3ff}body{background:radial-gradient(circle at 86% 0,#284b78 0,transparent 27rem),#101725}.section{background:#172136;border-color:#2a3852;box-shadow:none}.section-head h2,.forecast-card strong,.server-metric b,.container-list span{color:#e7efff}.section-head span,.server-meta,.docker-head span,.container-list small{color:#9eacc3}.forecast-card{background:#202d44;color:#aebbd0}.advice{background:#173438;color:#b8e1d9}.server-metric>i{background:#2b3850}.docker-head,.container-list li{border-color:#293851}.ai-account{background:#1a273d;border-color:#2d3b53}.metric-track{background:#2b3850}.metric-row strong{color:#dce7fb}.footer{color:#8795ae}}
CSS;
    echo '</style></head><body><main class="shell">';
    echo '<div class="topline"><b>' . html_escape(date('Y年n月j日', $now)) . '</b><span>' . html_escape($lunar['text'] ?? '农历 --') . '</span><span>刷新 ' . $refreshMinutes . ' 分钟</span></div>';
    echo '<section class="hero"><span class="city">📍 ' . html_escape($city) . '</span><div class="now"><span class="emoji">' . iphone_weather_emoji($weather['code'] ?? null) . '</span><div><strong>' . $temperature . '<small>°C</small></strong><p>' . html_escape(weather_label($weather['code'] ?? null)) . '</p></div></div><div class="range">今日 ' . $low . '° / ' . $high . '°</div><div class="stats"><span>湿度<b>' . html_escape($weather['humidity'] ?? '--') . '%</b></span><span>风力<b>' . html_escape($wind === null ? '--' : $wind) . '级</b></span><span>空气质量<b>' . html_escape(air_quality_label($aqi)) . ' ' . html_escape($aqi ?? '--') . '</b></span><span>紫外线<b>' . html_escape($uvLevel) . '</b></span></div></section>';
    echo '<section class="section"><div class="section-head"><h2>未来天气</h2><span>' . html_escape($city) . '</span></div><div class="forecasts">' . $forecastHtml . '</div><div class="advice"><b>☂</b><span>' . html_escape(weather_advice($weather, $forecast)) . '</span></div></section>';
    echo $serverHtml;
    echo '<section class="section ai"><div class="section-head ai-title"><h2>AI 余额</h2><span>且用且珍惜 ^_^</span></div>' . $claudeHtml . $deepseekHtml . $codexHtml . '</section>';
    echo '<div class="footer">更新于 ' . html_escape(date('H:i', $now)) . ' · 设备电量 ' . html_escape($batteryText) . '</div></main><script>setInterval(function(){location.reload();},' . ($refreshMinutes * 60000) . ');document.addEventListener("visibilitychange",function(){if(!document.hidden)location.reload();});</script></body></html>';
    exit;
}

function render_landscape_frame(array $device, array $state, array $config, int $width, int $height): void {
    // KO1's 7-inch horizontal screen benefits from a larger, high-contrast type scale.
    // Keep the frame geometry fixed so every label remains inside its existing panel.
    $GLOBALS['dashboard_font_scale'] = 1.2;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255); $black = imagecolorallocate($image, 0, 0, 0); $grey = imagecolorallocate($image, 100, 100, 100); $light = imagecolorallocate($image, 210, 210, 210);
    imagefill($image, 0, 0, $white);
    $scale = min($width / 1440, $height / 1080); $p = static function (float $value) use ($scale): int { return (int)round($value * $scale); };
    $now = time(); $weather = is_array($state['weather'] ?? null) ? $state['weather'] : [];
    $margin = $p(38); $right = $width - $margin; $top = $p(105); $bottom = $p(475); $split = $p(815);
    $battery = $state['device_status'][$device['id']]['battery'] ?? null;
    $batteryText = is_numeric($battery) ? max(0, min(100, (int)$battery)) . '%' : '--';
    $refresh = max(1, (int)($config['DASHBOARD_REFRESH_MINUTES'] ?? 15));
    draw_text($image, $p(21), $margin, $p(61), '更新于：' . date('Y年m月d日 H:i', $now), $grey);
    draw_text($image, $p(19), $p(500), $p(61), '刷新：' . $refresh . '分钟', $grey);
    draw_text($image, $p(19), $split + $p(15), $p(61), '城市：' . ($weather['city'] ?? $config['DASHBOARD_CITY_LABEL'] ?? '北海'), $grey);
    draw_text($image, $p(19), $right, $p(61), '电量：' . $batteryText, $grey, 'right');
    imageline($image, $margin, $p(82), $right, $p(82), $black);

    $calendarRect = [$margin, $top, $split - $p(15), $bottom]; $weatherRect = [$split + $p(15), $top, $right, $bottom]; $aiRect = [$margin, $p(493), $right, $height - $margin];
    box($image, $calendarRect, $grey); box($image, $weatherRect, $grey); box($image, $aiRect, $grey);

    $lunar = lunar_info($now); $year = (int)date('Y', $now); $month = (int)date('n', $now); $day = (int)date('j', $now);
    $calendarDividerX = $calendarRect[0] + $p(238);
    $dateColumnCenter = (int)(($calendarRect[0] + $calendarDividerX) / 2);
    draw_text($image, $p(24), $dateColumnCenter, $top + $p(42), date('Y年m月', $now), $black, 'center');
    draw_text($image, $p(92), $dateColumnCenter, $top + $p(165), (string)$day, $black, 'center');
    draw_text($image, $p(24), $dateColumnCenter, $top + $p(207), '星期' . ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', $now)], $black, 'center');
    draw_text($image, $p(19), $dateColumnCenter, $top + $p(242), $lunar['text'], $grey, 'center');
    $springFestivalDays = days_until_lunar_new_year($now);
    imageline($image, $dateColumnCenter - $p(92), $top + $p(263), $dateColumnCenter + $p(92), $top + $p(263), $light);
    $countdownLeft = $calendarRect[0] + $p(18); $countdownRight = $calendarDividerX - $p(18);
    draw_text($image, $p(17), $countdownLeft, $top + $p(294), '距双十一：', $black);
    draw_text($image, $p(19), $countdownRight, $top + $p(296), days_until_double_eleven($now) . '天', $black, 'right');
    draw_text($image, $p(17), $countdownLeft, $top + $p(336), '距春节：', $black);
    draw_text($image, $p(19), $countdownRight, $top + $p(338), ($springFestivalDays === null ? '--' : $springFestivalDays) . '天', $black, 'right');
    $gridX = $calendarRect[0] + $p(250); $gridY = $top + $p(34); $gridWidth = $calendarRect[2] - $gridX - $p(16); $cellWidth = $gridWidth / 7;
    imageline($image, $calendarDividerX, $top + $p(72), $calendarDividerX, $bottom - $p(28), $light);
    foreach (['一', '二', '三', '四', '五', '六', '日'] as $column => $name) draw_text($image, $p(20), (int)($gridX + $column * $cellWidth + $cellWidth / 2), $gridY + $p(6), $name, $grey, 'center');
    $firstWeekday = (int)date('N', mktime(0, 0, 0, $month, 1, $year)) - 1; $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    for ($currentDay = 1; $currentDay <= $days; $currentDay++) {
        $index = $firstWeekday + $currentDay - 1; $row = intdiv($index, 7); $column = $index % 7; $cx = (int)($gridX + $column * $cellWidth + $cellWidth / 2); $cy = $gridY + $p(51 + $row * 51);
        $colour = $column > 4 ? $grey : $black;
        if ($currentDay === $day) {
            imagesetthickness($image, max(1, $p(2)));
            imagerectangle($image, $cx - $p(25), $cy - $p(27), $cx + $p(25), $cy + $p(21), $black);
            imagesetthickness($image, 1);
            $colour = $black;
        }
        draw_text($image, $p(21), $cx, $cy, (string)$currentDay, $colour, 'center');
        $lunarDay = lunar_info(mktime(12, 0, 0, $month, $currentDay, $year));
        draw_text($image, $p(13), $cx, $cy + $p(18), $lunarDay['short'], $currentDay === $day ? $black : $grey, 'center');
    }

    $weatherWidth = $weatherRect[2] - $weatherRect[0];
    $currentWeatherRight = $weatherRect[0] + (int)round($weatherWidth * .50);
    $forecastCellWidth = ($weatherRect[2] - $currentWeatherRight) / 2;
    $currentWeatherCenter = (int)(($weatherRect[0] + $currentWeatherRight) / 2);
    $weatherContentBottom = $top + $p(292);
    $currentTemperature = is_numeric($weather['temperature'] ?? null) ? round((float)$weather['temperature']) : '--';
    draw_weather_icon($image, $currentWeatherCenter - $p(70), $top + $p(56), $p(50), $weather['code'] ?? null, $black, $grey, $white);
    $weatherIconCenter = $currentWeatherCenter - $p(70);
    $weatherCenter = $currentWeatherCenter + $p(38);
    draw_text($image, $p(19), $weatherIconCenter, $top + $p(132), weather_label($weather['code'] ?? null), $black, 'center');
    draw_text($image, $p(56), $weatherCenter + $p(30), $top + $p(94), $currentTemperature . '°', $black, 'center');
    draw_text($image, $p(18), $weatherCenter + $p(30), $top + $p(132), (is_numeric($weather['low'] ?? null) ? round((float)$weather['low']) : '--') . '° / ' . (is_numeric($weather['high'] ?? null) ? round((float)$weather['high']) : '--') . '°', $grey, 'center');
    $aqi = is_numeric($weather['aqi'] ?? null) ? (int)round((float)$weather['aqi']) : null;
    $currentWindLevel = wind_level($weather['wind'] ?? null);
    $humidity = ($weather['humidity'] ?? '--') . '%'; $air = air_quality_label($aqi); $wind = ($currentWindLevel ?? '--') . '级'; $uv = uv_level_label($weather['uv_index'] ?? null);
    $currentWeatherDetails = [
        ['湿度', $humidity, 165],
        ['空气', $air, 193],
        ['风力', $wind, 221],
        ['紫外线', $uv, 249],
    ];
    $detailLabelAxis = $currentWeatherCenter - $p(8); $detailValueAxis = $currentWeatherCenter + $p(6);
    foreach ($currentWeatherDetails as [$label, $value, $baseline]) {
        draw_text($image, $p(17), $detailLabelAxis, $top + $p($baseline), $label, $grey, 'right');
        draw_text($image, $p(18), $detailValueAxis, $top + $p($baseline), $value, $black);
    }
    $sunLineY = $top + $p(283);
    $sunriseX = $weatherRect[0] + $p(22);
    draw_sun_icon($image, $sunriseX, $sunLineY - $p(5), $p(12), $black);
    draw_text($image, $p(15), $sunriseX + $p(18), $sunLineY, weather_time_label($weather['sunrise'] ?? null), $grey);
    $sunsetTime = weather_time_label($weather['sunset'] ?? null); $sunsetRight = $currentWeatherRight - $p(22);
    $sunsetFontSize = max(1, (int)round($p(15) * (float)($GLOBALS['dashboard_font_scale'] ?? 1)));
    $sunsetBox = imagettfbbox($sunsetFontSize, 0, FONT_FILE, $sunsetTime);
    $sunsetWidth = max($sunsetBox[0], $sunsetBox[2], $sunsetBox[4], $sunsetBox[6]) - min($sunsetBox[0], $sunsetBox[2], $sunsetBox[4], $sunsetBox[6]);
    draw_moon_icon($image, $sunsetRight - $sunsetWidth - $p(18), $sunLineY - $p(5), $p(12), $black, $white);
    draw_text($image, $p(15), $sunsetRight, $sunLineY, $sunsetTime, $grey, 'right');
    $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : [];
    imageline($image, $currentWeatherRight, $top + $p(56), $currentWeatherRight, $weatherContentBottom, $light);
    foreach ([1 => '明天', 2 => '后天'] as $index => $caption) {
        $entry = is_array($forecast[$index] ?? null) ? $forecast[$index] : [];
        $cellStart = $currentWeatherRight + (int)round(($index - 1) * $forecastCellWidth);
        $cellCenter = (int)round($cellStart + $forecastCellWidth / 2);
        if ($index > 1) imageline($image, $cellStart, $top + $p(56), $cellStart, $weatherContentBottom, $light);
        draw_text($image, $p(20), $cellCenter, $top + $p(58), $caption, $black, 'center');
        draw_weather_icon($image, $cellCenter, $top + $p(105), $p(36), $entry['code'] ?? null, $black, $grey, $white);
        $low = is_numeric($entry['low'] ?? null) ? round((float)$entry['low']) : '--'; $high = is_numeric($entry['high'] ?? null) ? round((float)$entry['high']) : '--';
        draw_text($image, $p(18), $cellCenter, $top + $p(172), weather_label($entry['code'] ?? null), $black, 'center');
        draw_text($image, $p(20), $cellCenter, $top + $p(203), $low . '° / ' . $high . '°', $black, 'center');
        $rainProbability = is_numeric($entry['rain_probability'] ?? null) ? (int)round((float)$entry['rain_probability']) . '%' : '--';
        draw_text($image, $p(15), $cellCenter, $top + $p(238), '降雨 ' . $rainProbability, $grey, 'center');
        $forecastWindLevel = wind_level($entry['wind'] ?? null);
        draw_text($image, $p(15), $cellCenter, $top + $p(267), '风力 ' . ($forecastWindLevel ?? '--') . '级', $grey, 'center');
    }
    imageline($image, $weatherRect[0] + $p(22), $weatherContentBottom, $weatherRect[2] - $p(22), $weatherContentBottom, $light);
    draw_text($image, $p(19), $weatherRect[0] + $p(10), $top + $p(344), weather_advice($weather, $forecast), $grey);

    draw_text($image, $p(26), $aiRect[0] + $p(24), $aiRect[1] + $p(42), 'AI 余额', $black);
    draw_text($image, $p(16), $aiRect[2] - $p(24), $aiRect[1] + $p(40), '且用且珍惜 ^_^', $grey, 'right');
    imageline($image, $aiRect[0], $aiRect[1] + $p(58), $aiRect[2], $aiRect[1] + $p(58), $grey);
    $divider = $aiRect[0] + $p(455);
    imageline($image, $divider, $aiRect[1] + $p(58), $divider, $aiRect[3], $grey);
    $leftAccounts = []; $codexAccounts = [];
    foreach (quota_accounts($state) as $account) {
        if (($account['source'] ?? '') === 'codex') $codexAccounts[] = $account; else $leftAccounts[] = $account;
    }
    $leftAccountCenter = (int)(($aiRect[0] + $divider) / 2);
    $aiContentTop = $aiRect[1] + $p(76); $aiContentBottom = $aiRect[3];
    $leftDividerY = $aiContentTop + (int)round(($aiContentBottom - $aiContentTop) * .64);
    foreach ($leftAccounts as $index => $account) {
        $source = (string)($account['source'] ?? 'claude');
        $baseline = $source === 'claude' ? $aiContentTop + $p(54) : $leftDividerY + $p(76);
        $rowLine = $leftDividerY;
        if ($index < count($leftAccounts) - 1) imageline($image, $aiRect[0], $rowLine, $divider, $rowLine, $grey);
        $serviceIconX = $leftAccountCenter - $p(74);
        $serviceNameX = $leftAccountCenter - $p(18);
        $identityBaseline = $source === 'deepseek' ? $baseline - $p(5) : $baseline;
        draw_service_icon($image, $source, $serviceIconX, $identityBaseline - $p(8), $black, $white);
        $name = $source === 'claude' ? 'Claude Code' : 'DeepSeek';
        draw_text($image, $p(21), $serviceNameX, $identityBaseline, $name, $black);
        if ($source === 'deepseek') {
            $balance = preg_replace('/^Balance\\s*/i', '', (string)$account['summary']);
            draw_text($image, $p(27), $leftAccountCenter, $baseline + $p(58), $balance, $black, 'center');
            continue;
        }
        $claudeMetrics = [
            ['5H', account_metric($account, 'five_hour') ?? ['used' => 0]],
            ['7D', account_metric($account, 'seven_day') ?? ['used' => 0]],
            ['Fable', account_metric($account, 'fable') ?? ['used' => 0]],
        ];
        foreach ($claudeMetrics as $metricIndex => [$label, $metric]) {
            $metricBaseline = $baseline + $p(45 + $metricIndex * 63);
            $used = $metric['used'] ?? 0;
            $claudeProgressX = $aiRect[0] + $p(18);
            draw_text($image, $p(14), $claudeProgressX, $metricBaseline, $label, $grey);
            draw_progress($image, $claudeProgressX, $metricBaseline + $p(10), $p(180), $p(19), $used, $black, $grey, $white);
            draw_text($image, $p(19), $claudeProgressX + $p(195), $metricBaseline + $p(29), $used . '%', $black);
            draw_text($image, $p(19), $divider - $p(20), $metricBaseline, reset_time_label($metric['reset_at'] ?? null, $metricIndex === 0 ? 18300 : 605100), $black, 'right');
            draw_text($image, $p(14), $divider - $p(20), $metricBaseline + $p(29), '重置', $grey, 'right');
        }
    }
    $codexRowHeight = ($aiContentBottom - $aiContentTop) / max(1, count($codexAccounts));
    $codexShift = $p(34);
    foreach ($codexAccounts as $index => $account) {
        $rowCenter = (int)round($aiContentTop + ($index + .5) * $codexRowHeight); $baseline = $rowCenter - $p(5); $source = 'codex';
        if ($index < count($codexAccounts) - 1) {
            $rowBottom = (int)round($aiContentTop + ($index + 1) * $codexRowHeight);
            imageline($image, $divider + $p(18), $rowBottom, $aiRect[2] - $p(18), $rowBottom, $light);
        }
        draw_service_icon($image, $source, $divider + $p(28) + $codexShift, $baseline + $p(3), $black, $white);
        $codexName = (string)$account['name'];
        if ($codexName === 'Codex 1') $codexName = 'Codex A';
        elseif ($codexName === 'Codex 2') $codexName = 'Codex B';
        elseif ($codexName === 'Codex 3') $codexName = 'Codex C';
        $codexNameX = $divider + $p(68) + $codexShift;
        draw_text($image, $p(21), $codexNameX, $baseline, $codexName, $black);
        $codexNameFontSize = max(1, (int)round($p(21) * (float)($GLOBALS['dashboard_font_scale'] ?? 1)));
        $codexNameBox = imagettfbbox($codexNameFontSize, 0, FONT_FILE, $codexName);
        $codexNameWidth = max($codexNameBox[0], $codexNameBox[2], $codexNameBox[4], $codexNameBox[6]) - min($codexNameBox[0], $codexNameBox[2], $codexNameBox[4], $codexNameBox[6]);
        draw_text($image, $p(19), $codexNameX + $codexNameWidth, $baseline + $p(30), (string)($account['plan'] ?? '—'), $grey, 'right');
        $fiveHourMetric = account_metric($account, 'five_hour') ?? [];
        $sevenDayMetric = account_metric($account, 'seven_day') ?? [];
        $codexProgressX = $divider + $p(300) + $codexShift;
        foreach ([['5H', null, $fiveHourMetric, 18300], ['7D', $sevenDayMetric['used'] ?? null, $sevenDayMetric, 605100]] as $metricIndex => [$label, $used, $metric, $maxAheadSeconds]) {
            $metricBaseline = $rowCenter + $p($metricIndex === 0 ? -50 : 13) + ($index === 0 ? 0 : $p(18));
            draw_text($image, $p(14), $codexProgressX, $metricBaseline, $label, $grey);
            draw_progress($image, $codexProgressX, $metricBaseline + $p(10), $p(180), $p(19), $used, $black, $grey, $white);
            if ($metricIndex === 1) draw_text($image, $p(19), $codexProgressX + $p(195), $metricBaseline + $p(29), $used === null ? '--' : $used . '%', $black);
            draw_text($image, $p(19), $aiRect[2] - $p(20), $metricBaseline, reset_time_label($metric['reset_at'] ?? null, $maxAheadSeconds), $black, 'right');
            draw_text($image, $p(14), $aiRect[2] - $p(20), $metricBaseline + $p(29), '重置', $grey, 'right');
        }
    }
    header('Content-Type: image/png'); header('Cache-Control: no-store, max-age=0');
    imagepng($image, null, 9); imagedestroy($image); exit;
}

function render_phone_frame(array $device, array $state, array $config, int $width, int $height): void {
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255); $black = imagecolorallocate($image, 0, 0, 0); $grey = imagecolorallocate($image, 100, 100, 100); $light = imagecolorallocate($image, 210, 210, 210);
    imagefill($image, 0, 0, $white);
    $scale = min($width / 720, $height / 1440); $p = static function (float $value) use ($scale): int { return (int)round($value * $scale); };
    $f = static function (float $value) use ($p): int { return $p($value + 7); };
    $now = time(); $weather = is_array($state['weather'] ?? null) ? $state['weather'] : [];
    $margin = $p(16); $right = $width - $margin;
    $battery = $state['device_status'][$device['id']]['battery'] ?? null;
    $batteryText = is_numeric($battery) ? max(0, min(100, (int)$battery)) . '%' : '--';
    $refresh = max(1, (int)($config['DASHBOARD_REFRESH_MINUTES'] ?? 15));
    draw_text($image, $f(12), $margin, $p(35), '更新于:' . date('Y年m月d日 H:i', $now), $grey);
    draw_text($image, $f(11), $p(440), $p(35), '刷新:' . $refresh . '分钟', $grey, 'center');
    draw_text($image, $f(11), $right, $p(35), '电量' . $batteryText, $grey, 'right');
    imageline($image, $margin, $p(52), $right, $p(52), $black);

    $calendarRect = [$margin, $p(66), $right, $p(426)];
    $weatherRect = [$margin, $p(440), $p(386), $p(841)];
    $serviceRect = [$p(400), $p(440), $right, $p(841)];
    $codexRect = [$margin, $p(899), $right, $height - $margin];
    box($image, $calendarRect, $grey); box($image, $weatherRect, $grey); box($image, $serviceRect, $grey); box($image, $codexRect, $grey);

    $calendarX = $calendarRect[0]; $calendarY = $calendarRect[1]; $calendarBottom = $calendarRect[3];
    $lunar = lunar_info($now); $year = (int)date('Y', $now); $month = (int)date('n', $now); $day = (int)date('j', $now);
    $calendarDividerX = $p(229); $dateCenter = (int)(($calendarX + $calendarDividerX) / 2);
    draw_text($image, $f(17), $dateCenter, $calendarY + $p(40), date('Y年m月', $now), $black, 'center');
    draw_text($image, $f(66), $dateCenter, $calendarY + $p(131), (string)$day, $black, 'center');
    draw_text($image, $f(18), $dateCenter, $calendarY + $p(175), '星期' . ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', $now)], $black, 'center');
    draw_text($image, $f(13), $dateCenter, $calendarY + $p(212), $lunar['text'], $grey, 'center');
    imageline($image, $dateCenter - $p(76), $calendarY + $p(236), $dateCenter + $p(76), $calendarY + $p(236), $light);
    $lunarBox = imagettfbbox($f(13), 0, FONT_FILE, $lunar['text']);
    $lunarWidth = max($lunarBox[0], $lunarBox[2], $lunarBox[4], $lunarBox[6]) - min($lunarBox[0], $lunarBox[2], $lunarBox[4], $lunarBox[6]);
    $countdownLeft = $dateCenter - (int)round($lunarWidth / 2); $countdownRight = $dateCenter + (int)round($lunarWidth / 2);
    $springFestivalDays = days_until_lunar_new_year($now);
    draw_text($image, $f(12), $countdownLeft, $calendarY + $p(277), '距双十一：', $black);
    draw_text($image, $f(14), $countdownRight, $calendarY + $p(279), days_until_double_eleven($now) . '天', $black, 'right');
    draw_text($image, $f(12), $countdownLeft, $calendarY + $p(326), '距春节：', $black);
    draw_text($image, $f(14), $countdownRight, $calendarY + $p(328), ($springFestivalDays === null ? '--' : $springFestivalDays) . '天', $black, 'right');
    imageline($image, $calendarDividerX, $calendarY + $p(42), $calendarDividerX, $calendarBottom - $p(16), $light);

    $firstWeekday = (int)date('N', mktime(0, 0, 0, $month, 1, $year)) - 1; $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $rowCount = intdiv($firstWeekday + $days - 1, 7) + 1; $rowSpacing = 55;
    $gridX = $p(243); $gridY = $calendarY + $p($rowCount <= 5 ? 20 : 13); $gridWidth = $right - $gridX - $p(8); $cellWidth = $gridWidth / 7;
    foreach (['一', '二', '三', '四', '五', '六', '日'] as $column => $name) draw_text($image, $f(11), (int)($gridX + $column * $cellWidth + $cellWidth / 2), $gridY + $p(12), $name, $grey, 'center');
    for ($currentDay = 1; $currentDay <= $days; $currentDay++) {
        $index = $firstWeekday + $currentDay - 1; $row = intdiv($index, 7); $column = $index % 7;
        $cx = (int)($gridX + $column * $cellWidth + $cellWidth / 2); $cy = $gridY + $p(45 + $row * $rowSpacing); $colour = $column > 4 ? $grey : $black;
        if ($currentDay === $day) {
            imagesetthickness($image, max(1, $p(2)));
            imagerectangle($image, $cx - $p(24), $cy - $p(26), $cx + $p(24), $cy + $p(27), $black);
            imagesetthickness($image, 1); $colour = $black;
        }
        draw_text($image, $f(14), $cx, $cy, (string)$currentDay, $colour, 'center');
        $lunarDay = lunar_info(mktime(12, 0, 0, $month, $currentDay, $year));
        draw_text($image, $f(8), $cx, $cy + $p(19), $lunarDay['short'], $currentDay === $day ? $black : $grey, 'center');
    }

    $weatherX = $weatherRect[0]; $weatherY = $weatherRect[1]; $weatherRight = $weatherRect[2];
    $cityX = $weatherX + $p(30); $temperatureX = $weatherX + $p(122); $detailX = $weatherX + $p(220);
    $currentTemperature = is_numeric($weather['temperature'] ?? null) ? round((float)$weather['temperature']) : '--';
    $aqi = is_numeric($weather['aqi'] ?? null) ? (int)round((float)$weather['aqi']) : null;
    $windLevel = wind_level($weather['wind'] ?? null);
    $weatherIconCenterX = $cityX + $p(24); $temperatureCenterX = $temperatureX + $p(31);
    $cityName = (string)($weather['city'] ?? $config['DASHBOARD_CITY_LABEL'] ?? '北海');
    $cityNameBox = imagettfbbox($f(15), 0, FONT_FILE, $cityName);
    $cityNameWidth = max($cityNameBox[0], $cityNameBox[2], $cityNameBox[4], $cityNameBox[6]) - min($cityNameBox[0], $cityNameBox[2], $cityNameBox[4], $cityNameBox[6]);
    $cityGroupCenterX = (int)round(($weatherIconCenterX + $temperatureCenterX) / 2); $cityGroupWidth = $p(13) + $p(8) + $cityNameWidth; $cityGroupLeft = $cityGroupCenterX - (int)round($cityGroupWidth / 2);
    draw_location_pin($image, $cityGroupLeft + $p(7), $weatherY + $p(21), $p(13), $black, $white);
    draw_text($image, $f(15), $cityGroupLeft + $p(20), $weatherY + $p(32), $cityName, $black);
    draw_weather_icon($image, $weatherIconCenterX, $weatherY + $p(77), $p(40), $weather['code'] ?? null, $black, $grey, $white);
    draw_text($image, $f(16), $weatherIconCenterX, $weatherY + $p(145), weather_label($weather['code'] ?? null), $black, 'center');
    draw_text($image, $f(47), $temperatureCenterX, $weatherY + $p(109), $currentTemperature . '°', $black, 'center');
    $todayLow = is_numeric($weather['low'] ?? null) ? round((float)$weather['low']) : '--'; $todayHigh = is_numeric($weather['high'] ?? null) ? round((float)$weather['high']) : '--';
    draw_text($image, $f(12), $temperatureCenterX, $weatherY + $p(144), $todayLow . '° / ' . $todayHigh . '°', $grey, 'center');
    imageline($image, $weatherX + $p(207), $weatherY + $p(48), $weatherX + $p(207), $weatherY + $p(153), $grey);
    $detailValueX = $weatherRight - $p(18);
    draw_text($image, $f(11), $detailX, $weatherY + $p(58), '空气：', $grey);
    draw_text($image, $f(12), $detailValueX, $weatherY + $p(58), air_quality_label($aqi), $black, 'right');
    draw_text($image, $f(11), $detailX, $weatherY + $p(89), '湿度：', $grey);
    draw_text($image, $f(12), $detailValueX, $weatherY + $p(89), ($weather['humidity'] ?? '--') . '%', $black, 'right');
    draw_text($image, $f(11), $detailX, $weatherY + $p(120), '风力：', $grey);
    draw_text($image, $f(12), $detailValueX, $weatherY + $p(120), ($windLevel ?? '--') . '级', $black, 'right');
    draw_text($image, $f(11), $detailX, $weatherY + $p(151), '紫外线：', $grey);
    draw_text($image, $f(12), $detailValueX, $weatherY + $p(151), uv_level_label($weather['uv_index'] ?? null), $black, 'right');
    $forecastTop = $weatherY + $p(199); $sunLineY = $forecastTop - $p(13);
    $sunriseLeftX = $dateCenter - (int)round($lunarWidth / 2);
    draw_sun_icon($image, $sunriseLeftX + $p(12), $sunLineY - $p(6), $p(15), $black);
    draw_text($image, $f(11), $sunriseLeftX + $p(26), $sunLineY, weather_time_label($weather['sunrise'] ?? null), $grey);
    $sunsetTime = weather_time_label($weather['sunset'] ?? null); $sunsetRight = $weatherRight - $p(18);
    $sunsetBox = imagettfbbox($f(11), 0, FONT_FILE, $sunsetTime);
    $sunsetWidth = max($sunsetBox[0], $sunsetBox[2], $sunsetBox[4], $sunsetBox[6]) - min($sunsetBox[0], $sunsetBox[2], $sunsetBox[4], $sunsetBox[6]);
    draw_moon_icon($image, $sunsetRight - $sunsetWidth - $p(10), $sunLineY - $p(6), $p(15), $black, $white);
    draw_text($image, $f(11), $sunsetRight, $sunLineY, $sunsetTime, $grey, 'right');
    $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : []; $forecastWidth = ($weatherRight - $weatherX - $p(20)) / 2; $forecastDividerY = $forecastTop - $p(3);
    imageline($image, $weatherX, $forecastDividerY, $weatherRight, $forecastDividerY, $grey);
    foreach ([1 => '明天', 2 => '后天'] as $index => $caption) {
        $entry = is_array($forecast[$index] ?? null) ? $forecast[$index] : [];
        $cellStart = $weatherX + $p(10) + (int)round(($index - 1) * $forecastWidth); $cellCenter = (int)round($cellStart + $forecastWidth / 2);
        if ($index > 1) imageline($image, $cellStart, $forecastDividerY, $cellStart, $weatherRect[3], $grey);
        draw_text($image, $f(15), $cellCenter, $forecastTop + $p(33), $caption, $black, 'center');
        draw_weather_icon($image, $cellCenter, $forecastTop + $p(56), $p(28), $entry['code'] ?? null, $black, $grey, $white);
        $low = is_numeric($entry['low'] ?? null) ? round((float)$entry['low']) : '--'; $high = is_numeric($entry['high'] ?? null) ? round((float)$entry['high']) : '--';
        draw_text($image, $f(13), $cellCenter, $forecastTop + $p(104), weather_label($entry['code'] ?? null), $black, 'center');
        draw_text($image, $f(14), $cellCenter, $forecastTop + $p(130), $low . '° / ' . $high . '°', $black, 'center');
        $rainProbability = is_numeric($entry['rain_probability'] ?? null) ? (int)round((float)$entry['rain_probability']) . '%' : '--'; $forecastWindLevel = wind_level($entry['wind'] ?? null);
        draw_text($image, $f(10), $cellCenter, $forecastTop + $p(157), '降雨 ' . $rainProbability, $grey, 'center');
        draw_text($image, $f(10), $cellCenter, $forecastTop + $p(185), '风力 ' . ($forecastWindLevel ?? '--') . '级', $grey, 'center');
    }

    $claudeAccount = null; $deepseekAccount = null; $codexAccounts = [];
    foreach (quota_accounts($state) as $account) {
        if (($account['source'] ?? '') === 'codex') $codexAccounts[] = $account;
        elseif (($account['source'] ?? '') === 'deepseek') $deepseekAccount = $account;
        else $claudeAccount = $account;
    }
    $serviceX = $serviceRect[0]; $serviceRight = $serviceRect[2]; $serviceCenter = (int)(($serviceX + $serviceRight) / 2); $claudeBaseline = $weatherY + $p(56);
    draw_service_icon($image, 'claude', $serviceCenter - $p(78), $claudeBaseline - $p(7), $black, $white);
    draw_text($image, $f(17), $serviceCenter - $p(25), $claudeBaseline, 'Claude Code', $black);
    $claudeMetrics = [['5H', account_metric($claudeAccount ?? [], 'five_hour') ?? ['used' => 0]], ['7D', account_metric($claudeAccount ?? [], 'seven_day') ?? ['used' => 0]], ['Fable', account_metric($claudeAccount ?? [], 'fable') ?? ['used' => 0]]];
    foreach ($claudeMetrics as $metricIndex => [$label, $metric]) {
        $metricBaseline = $weatherY + $p(107 + $metricIndex * 60); $used = $metric['used'] ?? 0; $progressX = $serviceX + $p(16);
        draw_text($image, $f(11), $progressX, $metricBaseline, $label, $grey);
        draw_progress($image, $progressX, $metricBaseline + $p(10), $p(128), $p(16), $used, $black, $grey, $white);
        draw_text($image, $f(15), $progressX + $p(138), $metricBaseline + $p(30), $used . '%', $black);
        draw_text($image, $f(13), $serviceRight - $p(12), $metricBaseline, reset_time_label($metric['reset_at'] ?? null, $metricIndex === 0 ? 18300 : 605100), $black, 'right');
        draw_text($image, $f(11), $serviceRight - $p(12), $metricBaseline + $p(30), '重置', $grey, 'right');
    }
    imageline($image, $serviceX, $weatherY + $p(272), $serviceRight, $weatherY + $p(272), $grey);
    $deepseekBaseline = $weatherY + $p(318);
    draw_service_icon($image, 'deepseek', $serviceCenter - $p(66), $deepseekBaseline - $p(8), $black, $white);
    draw_text($image, $f(17), $serviceCenter - $p(17), $deepseekBaseline, 'DeepSeek', $black);
    $balance = preg_replace('/^Balance\\s*/i', '', (string)($deepseekAccount['summary'] ?? '¥0.00'));
    draw_text($image, $f(19), $serviceCenter, $weatherY + $p(380), $balance, $black, 'center');

    draw_text($image, $f(14), $margin + $p(16), $p(884), weather_advice($weather, $forecast), $grey);

    $codexX = $codexRect[0]; $codexY = $codexRect[1]; $codexRight = $codexRect[2]; $codexBottom = $codexRect[3]; $codexRowHeight = ($codexBottom - $codexY) / max(1, count($codexAccounts));
    foreach ($codexAccounts as $index => $account) {
        $rowTop = (int)round($codexY + $index * $codexRowHeight); $rowCenter = (int)round($rowTop + $codexRowHeight / 2);
        if ($index < count($codexAccounts) - 1) imageline($image, $codexX + $p(12), (int)round($rowTop + $codexRowHeight), $codexRight - $p(12), (int)round($rowTop + $codexRowHeight), $light);
        draw_service_icon($image, 'codex', $codexX + $p(58), $rowCenter - $p(7), $black, $white);
        $codexName = (string)$account['name'];
        if ($codexName === 'Codex 1') $codexName = 'Codex A';
        elseif ($codexName === 'Codex 2') $codexName = 'Codex B';
        elseif ($codexName === 'Codex 3') $codexName = 'Codex C';
        $codexNameX = $codexX + $p(105);
        draw_text($image, $f(17), $codexNameX, $rowCenter, $codexName, $black);
        $codexNameBox = imagettfbbox($f(17), 0, FONT_FILE, $codexName);
        $codexNameWidth = max($codexNameBox[0], $codexNameBox[2], $codexNameBox[4], $codexNameBox[6]) - min($codexNameBox[0], $codexNameBox[2], $codexNameBox[4], $codexNameBox[6]);
        draw_text($image, $f(14), $codexNameX + $codexNameWidth, $rowCenter + $p(29), (string)($account['plan'] ?? '—'), $grey, 'right');
        $fiveHourMetric = account_metric($account, 'five_hour') ?? []; $sevenDayMetric = account_metric($account, 'seven_day') ?? []; $progressX = $codexX + $p(235);
        foreach ([['5H', null, $fiveHourMetric, 18300], ['7D', $sevenDayMetric['used'] ?? null, $sevenDayMetric, 605100]] as $metricIndex => [$label, $used, $metric, $maxAheadSeconds]) {
            $metricBaseline = $rowCenter + $p($metricIndex === 0 ? -41 : 24);
            draw_text($image, $f(11), $progressX, $metricBaseline, $label, $grey);
            draw_progress($image, $progressX, $metricBaseline + $p(10), $p(195), $p(16), $used, $black, $grey, $white);
            if ($metricIndex === 1) draw_text($image, $f(15), $progressX + $p(207), $metricBaseline + $p(30), $used === null ? '--' : $used . '%', $black);
            draw_text($image, $f(15), $codexRight - $p(12), $metricBaseline, reset_time_label($metric['reset_at'] ?? null, $maxAheadSeconds), $black, 'right');
            draw_text($image, $f(11), $codexRight - $p(12), $metricBaseline + $p(30), '重置', $grey, 'right');
        }
    }
    header('Content-Type: image/png'); header('Cache-Control: no-store, max-age=0');
    imagepng($image, null, 9); imagedestroy($image); exit;
}

function render_portrait_frame(array $device, array $state, array $config, int $width, int $height): void {
    // KPW3 is viewed at arm's length on a high-density 6-inch panel.
    $fontScale = 1.4;
    $GLOBALS['dashboard_font_scale'] = $fontScale;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255); $black = imagecolorallocate($image, 0, 0, 0); $grey = imagecolorallocate($image, 100, 100, 100); $light = imagecolorallocate($image, 210, 210, 210);
    imagefill($image, 0, 0, $white);
    $scale = min($width / 1072, $height / 1448); $p = static function (float $value) use ($scale): int { return (int)round($value * $scale); };
    $now = time(); $weather = is_array($state['weather'] ?? null) ? $state['weather'] : [];
    $margin = $p(26); $right = $width - $margin;
    $battery = $state['device_status'][$device['id']]['battery'] ?? null;
    $batteryText = is_numeric($battery) ? max(0, min(100, (int)$battery)) . '%' : '--';
    $refresh = max(1, (int)($config['DASHBOARD_REFRESH_MINUTES'] ?? 15));
    draw_text($image, $p(17), $margin, $p(47), '更新于：' . date('Y年m月d日 H:i', $now), $grey);
    draw_text($image, $p(15), (int)($width * .59), $p(47), '刷新：' . $refresh . '分钟', $grey, 'center');
    draw_text($image, $p(15), $right, $p(47), '电量：' . $batteryText, $grey, 'right');

    $calendarRect = [$margin, $p(74), $right, $p(460)];
    $weatherRect = [$margin, $p(480), $p(566), $p(920)];
    $serviceRect = [$p(586), $p(480), $right, $p(920)];
    $codexRect = [$margin, $p(940), $right, $height - $margin];
    box($image, $calendarRect, $grey); box($image, $weatherRect, $grey); box($image, $serviceRect, $grey); box($image, $codexRect, $grey);

    $calendarX = $calendarRect[0]; $calendarY = $calendarRect[1]; $calendarRight = $calendarRect[2]; $calendarBottom = $calendarRect[3];
    $lunar = lunar_info($now); $year = (int)date('Y', $now); $month = (int)date('n', $now); $day = (int)date('j', $now);
    $calendarDividerX = $calendarX + $p(282); $dateCenter = (int)(($calendarX + $calendarDividerX) / 2);
    draw_text($image, $p(22), $dateCenter, $calendarY + $p(48), date('Y年m月', $now), $black, 'center');
    draw_text($image, $p(82), $dateCenter, $calendarY + $p(167), (string)$day, $black, 'center');
    draw_text($image, $p(22), $dateCenter, $calendarY + $p(207), '星期' . ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', $now)], $black, 'center');
    draw_text($image, $p(19), $dateCenter, $calendarY + $p(245), $lunar['text'], $grey, 'center');
    imageline($image, $dateCenter - $p(106), $calendarY + $p(264), $dateCenter + $p(106), $calendarY + $p(264), $light);
    $springFestivalDays = days_until_lunar_new_year($now);
    $countdownLeft = $calendarX + $p(20); $countdownRight = $calendarDividerX - $p(20);
    draw_text($image, $p(16), $countdownLeft, $calendarY + $p(301), '距离双十一：', $black);
    draw_text($image, $p(18), $countdownRight, $calendarY + $p(303), days_until_double_eleven($now) . '天', $black, 'right');
    draw_text($image, $p(16), $countdownLeft, $calendarY + $p(346), '距离春节：', $black);
    draw_text($image, $p(18), $countdownRight, $calendarY + $p(348), ($springFestivalDays === null ? '--' : $springFestivalDays) . '天', $black, 'right');
    imageline($image, $calendarDividerX, $calendarY + $p(54), $calendarDividerX, $calendarBottom - $p(22), $light);
    $firstWeekday = (int)date('N', mktime(0, 0, 0, $month, 1, $year)) - 1; $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    $calendarRows = (int)ceil(($firstWeekday + $days) / 7);
    $gridX = $calendarX + $p(302); $gridY = $calendarY + $p($calendarRows >= 6 ? 35 : 48); $gridWidth = $calendarRight - $gridX - $p(18); $cellWidth = $gridWidth / 7;
    foreach (['一', '二', '三', '四', '五', '六', '日'] as $column => $name) draw_text($image, $p(23), (int)($gridX + $column * $cellWidth + $cellWidth / 2), $gridY + $p(4), $name, $grey, 'center');
    $calendarRowGap = $calendarRows >= 6 ? 53 : 59;
    for ($currentDay = 1; $currentDay <= $days; $currentDay++) {
        $index = $firstWeekday + $currentDay - 1; $row = intdiv($index, 7); $column = $index % 7;
        $cx = (int)($gridX + $column * $cellWidth + $cellWidth / 2); $cy = $gridY + $p(46 + $row * $calendarRowGap); $colour = $column > 4 ? $grey : $black;
        if ($currentDay === $day) {
            imagesetthickness($image, max(1, $p(2)));
            imagerectangle($image, $cx - $p(30), $cy - $p(31), $cx + $p(30), $cy + $p(24), $black);
            imagesetthickness($image, 1);
            $colour = $black;
        }
        draw_text($image, $p(21), $cx, $cy, (string)$currentDay, $colour, 'center');
        $lunarDay = lunar_info(mktime(12, 0, 0, $month, $currentDay, $year));
        draw_text($image, $p(12), $cx, $cy + $p(19), $lunarDay['short'], $currentDay === $day ? $black : $grey, 'center');
    }

    $weatherX = $weatherRect[0]; $weatherY = $weatherRect[1]; $weatherRight = $weatherRect[2];
    $currentTemperature = is_numeric($weather['temperature'] ?? null) ? round((float)$weather['temperature']) : '--';
    $cityName = (string)($weather['city'] ?? $config['DASHBOARD_CITY_LABEL'] ?? '北海');
    draw_location_pin($image, $weatherX + $p(36), $weatherY + $p(25), $p(16), $black, $white);
    draw_text($image, $p(18), $weatherX + $p(58), $weatherY + $p(38), $cityName, $black);

    $weatherNowCenter = $weatherX + $p(92);
    $temperatureCenter = $weatherX + $p(245);
    $detailLabelX = $weatherX + $p(349);
    $detailValueX = $weatherRight - $p(20);
    draw_weather_icon($image, $weatherNowCenter, $weatherY + $p(88), $p(52), $weather['code'] ?? null, $black, $grey, $white);
    draw_text($image, $p(18), $weatherNowCenter, $weatherY + $p(160), weather_label($weather['code'] ?? null), $black, 'center');
    draw_text($image, $p(50), $temperatureCenter, $weatherY + $p(106), $currentTemperature . '°', $black, 'center');
    draw_text($image, $p(21), $temperatureCenter, $weatherY + $p(145), (is_numeric($weather['low'] ?? null) ? round((float)$weather['low']) : '--') . '° / ' . (is_numeric($weather['high'] ?? null) ? round((float)$weather['high']) : '--') . '°', $grey, 'center');
    $aqi = is_numeric($weather['aqi'] ?? null) ? (int)round((float)$weather['aqi']) : null;
    $currentWindLevel = wind_level($weather['wind'] ?? null);
    $currentDetails = [
        ['湿度', ($weather['humidity'] ?? '--') . '%'],
        ['风力', ($currentWindLevel ?? '--') . '级'],
        ['空气', air_quality_label($aqi)],
        ['紫外线', uv_level_label($weather['uv_index'] ?? null)],
    ];
    foreach ($currentDetails as $detailIndex => [$label, $value]) {
        $detailBaseline = $weatherY + $p(62 + $detailIndex * 41);
        draw_text($image, $p(17), $detailLabelX - $p(5), $detailBaseline - $p(3), $label, $grey);
        draw_text($image, $p(20), $detailValueX, $detailBaseline, $value, $black, 'right');
    }
    draw_sun_icon($image, $weatherX + $p(38), $weatherY + $p(201), $p(13), $black);
    $sunriseText = '日出 ' . weather_time_label($weather['sunrise'] ?? null); $sunriseTextX = $weatherX + $p(56);
    draw_text($image, $p(14), $sunriseTextX, $weatherY + $p(208), $sunriseText, $grey);
    $sunriseBox = imagettfbbox((int)round($p(14 * $fontScale)), 0, FONT_FILE, $sunriseText);
    $sunriseWidth = max($sunriseBox[0], $sunriseBox[2], $sunriseBox[4], $sunriseBox[6]) - min($sunriseBox[0], $sunriseBox[2], $sunriseBox[4], $sunriseBox[6]);
    $sunsetIconX = $sunriseTextX + $sunriseWidth + $p(22);
    draw_moon_icon($image, $sunsetIconX, $weatherY + $p(201), $p(13), $black, $white);
    draw_text($image, $p(14), $sunsetIconX + $p(18), $weatherY + $p(208), '日落 ' . weather_time_label($weather['sunset'] ?? null), $grey);

    $forecast = is_array($weather['forecast'] ?? null) ? $weather['forecast'] : [];
    $forecastTop = $weatherY + $p(215); $forecastBottom = $weatherY + $p(392); $forecastWidth = ($weatherRight - $weatherX - $p(28)) / 2;
    imageline($image, $weatherX, $forecastTop, $weatherRight, $forecastTop, $grey);
    foreach ([1 => '明天', 2 => '后天'] as $index => $caption) {
        $entry = is_array($forecast[$index] ?? null) ? $forecast[$index] : [];
        $cellStart = $weatherX + $p(14) + (int)round(($index - 1) * $forecastWidth); $cellCenter = (int)round($cellStart + $forecastWidth / 2);
        $forecastLeftCenter = (int)round($cellStart + $forecastWidth * 0.25); $forecastRightCenter = (int)round($cellStart + $forecastWidth * 0.72);
        if ($index > 1) imageline($image, $cellStart, $forecastTop + $p(16), $cellStart, $forecastBottom, $light);
        draw_text($image, $p(20), $cellCenter, $forecastTop + $p(34), $caption, $black, 'center');
        draw_weather_icon($image, $forecastLeftCenter, $forecastTop + $p(69), $p(33), $entry['code'] ?? null, $black, $grey, $white);
        $low = is_numeric($entry['low'] ?? null) ? round((float)$entry['low']) : '--'; $high = is_numeric($entry['high'] ?? null) ? round((float)$entry['high']) : '--';
        draw_text($image, $p(18), $forecastLeftCenter, $forecastTop + $p(135), weather_label($entry['code'] ?? null), $black, 'center');
        draw_text($image, $p(17), $forecastRightCenter, $forecastTop + $p(80), $low . '° / ' . $high . '°', $black, 'center');
        $rainProbability = is_numeric($entry['rain_probability'] ?? null) ? (int)round((float)$entry['rain_probability']) . '%' : '--';
        $forecastWindLevel = wind_level($entry['wind'] ?? null);
        draw_text($image, $p(15), $forecastRightCenter, $forecastTop + $p(119), '降雨 ' . $rainProbability, $grey, 'center');
        draw_text($image, $p(15), $forecastRightCenter, $forecastTop + $p(154), '风力 ' . ($forecastWindLevel ?? '--') . '级', $grey, 'center');
    }
    draw_text($image, $p(15), $weatherX + $p(25), $weatherY + $p(420), weather_advice($weather, $forecast), $grey);

    $claudeAccount = null; $deepseekAccount = null; $codexAccounts = [];
    foreach (quota_accounts($state) as $account) {
        if (($account['source'] ?? '') === 'codex') $codexAccounts[] = $account;
        elseif (($account['source'] ?? '') === 'deepseek') $deepseekAccount = $account;
        else $claudeAccount = $account;
    }
    $serviceX = $serviceRect[0]; $serviceRight = $serviceRect[2]; $serviceCenter = (int)(($serviceX + $serviceRight) / 2);
    $metricGroupGap = 65;
    $claudeBaseline = $weatherY + $p(60);
    $claudeNameBox = imagettfbbox($p(20 * $fontScale), 0, FONT_FILE, 'Claude Code');
    $claudeNameWidth = max($claudeNameBox[0], $claudeNameBox[2], $claudeNameBox[4], $claudeNameBox[6]) - min($claudeNameBox[0], $claudeNameBox[2], $claudeNameBox[4], $claudeNameBox[6]);
    $claudeGroupWidth = $p(86) + $claudeNameWidth; $claudeGroupLeft = $serviceCenter - (int)round($claudeGroupWidth / 2);
    draw_service_icon($image, 'claude', $claudeGroupLeft + $p(34), $claudeBaseline - $p(7), $black, $white, $p(3));
    draw_text($image, $p(20), $claudeGroupLeft + $p(86), $claudeBaseline, 'Claude Code', $black);
    $claudeMetrics = [
        ['5H', account_metric($claudeAccount ?? [], 'five_hour') ?? ['used' => 0]],
        ['7D', account_metric($claudeAccount ?? [], 'seven_day') ?? ['used' => 0]],
        ['Fable', account_metric($claudeAccount ?? [], 'fable') ?? ['used' => 0]],
    ];
    foreach ($claudeMetrics as $metricIndex => [$label, $metric]) {
        $metricBaseline = $weatherY + $p(117 + $metricIndex * $metricGroupGap); $used = $metric['used'] ?? 0; $progressX = $serviceX + $p(22);
        draw_text($image, $p(13), $progressX, $metricBaseline, $label, $grey);
        draw_progress($image, $progressX, $metricBaseline + $p(10), $p(175), $p(18), $used, $black, $grey, $white);
        draw_text($image, $p(18), $progressX + $p(188), $metricBaseline + $p(28), $used . '%', $black);
        draw_text($image, $p(16), $serviceRight - $p(18), $metricBaseline, reset_time_label($metric['reset_at'] ?? null, $metricIndex === 0 ? 18300 : 605100), $black, 'right');
        draw_text($image, $p(13), $serviceRight - $p(18), $metricBaseline + $p(28), '重置', $grey, 'right');
    }
    $serviceSeparatorY = $weatherY + $p(300);
    imageline($image, $serviceX, $serviceSeparatorY, $serviceRight, $serviceSeparatorY, $grey);
    $deepseekBaseline = $weatherY + $p(354);
    $deepseekNameBox = imagettfbbox($p(20 * $fontScale), 0, FONT_FILE, 'DeepSeek');
    $deepseekNameWidth = max($deepseekNameBox[0], $deepseekNameBox[2], $deepseekNameBox[4], $deepseekNameBox[6]) - min($deepseekNameBox[0], $deepseekNameBox[2], $deepseekNameBox[4], $deepseekNameBox[6]);
    $deepseekGroupWidth = $p(78) + $deepseekNameWidth; $deepseekGroupLeft = $serviceCenter - (int)round($deepseekGroupWidth / 2);
    draw_service_icon($image, 'deepseek', $deepseekGroupLeft + $p(30), $deepseekBaseline - $p(8), $black, $white, $p(3));
    draw_text($image, $p(20), $deepseekGroupLeft + $p(78), $deepseekBaseline, 'DeepSeek', $black);
    $balance = preg_replace('/^Balance\\s*/i', '', (string)($deepseekAccount['summary'] ?? '¥0.00'));
    draw_text($image, $p(23), $serviceCenter, $weatherY + $p(415), $balance, $black, 'center');

    $codexX = $codexRect[0]; $codexY = $codexRect[1]; $codexRight = $codexRect[2]; $codexBottom = $codexRect[3];
    $codexRowHeight = ($codexBottom - $codexY) / max(1, count($codexAccounts));
    foreach ($codexAccounts as $index => $account) {
        $rowTop = (int)round($codexY + $index * $codexRowHeight); $rowCenter = (int)round($rowTop + $codexRowHeight / 2);
        if ($index < count($codexAccounts) - 1) imageline($image, $codexX + $p(18), (int)round($rowTop + $codexRowHeight), $codexRight - $p(18), (int)round($rowTop + $codexRowHeight), $light);
        $identityBaseline = $rowCenter - $p(8);
        draw_service_icon($image, 'codex', $codexX + $p(74), $identityBaseline + $p(3), $black, $white, $p(3));
        $displayName = (string)$account['name'];
        if ($displayName === 'Codex 1') $displayName = 'Codex A';
        elseif ($displayName === 'Codex 2') $displayName = 'Codex B';
        elseif ($displayName === 'Codex 3') $displayName = 'Codex C';
        $nameX = $codexX + $p(126);
        draw_text($image, $p(20), $nameX, $identityBaseline, $displayName, $black);
        $nameBox = imagettfbbox((int)round($p(20 * $fontScale)), 0, FONT_FILE, $displayName);
        $nameRight = $nameX + max($nameBox[0], $nameBox[2], $nameBox[4], $nameBox[6]) - min($nameBox[0], $nameBox[2], $nameBox[4], $nameBox[6]);
        draw_text($image, $p(17), $nameRight, $identityBaseline + $p(28), (string)($account['plan'] ?? '—'), $grey, 'right');
        $fiveHourMetric = account_metric($account, 'five_hour') ?? []; $sevenDayMetric = account_metric($account, 'seven_day') ?? [];
        $progressX = $codexX + $p(365);
        foreach ([['5H', null, $fiveHourMetric, 18300], ['7D', $sevenDayMetric['used'] ?? null, $sevenDayMetric, 605100]] as $metricIndex => [$label, $used, $metric, $maxAheadSeconds]) {
            $metricBaseline = $rowCenter + $p(-41 + $metricIndex * $metricGroupGap);
            draw_text($image, $p(13), $progressX, $metricBaseline, $label, $grey);
            draw_progress($image, $progressX, $metricBaseline + $p(10), $p(260), $p(18), $used, $black, $grey, $white);
            if ($metricIndex === 1) draw_text($image, $p(18), $progressX + $p(274), $metricBaseline + $p(28), $used === null ? '--' : $used . '%', $black);
            draw_text($image, $p(18), $codexRight - $p(18), $metricBaseline, reset_time_label($metric['reset_at'] ?? null, $maxAheadSeconds), $black, 'right');
            draw_text($image, $p(13), $codexRight - $p(18), $metricBaseline + $p(28), '重置', $grey, 'right');
        }
    }
    render_grayscale_png($image);
}

function render_frame(array $device, array $state, array $config): void {
    $rotated = ($device['layout'] ?? '') === 'landscape' && in_array((int)($device['rotate'] ?? 0), [90, 270], true);
    $width = (int)($rotated ? $device['frame_height'] : $device['frame_width']);
    $height = (int)($rotated ? $device['frame_width'] : $device['frame_height']);
    if (($device['layout'] ?? '') === 'landscape' && $width >= 1000) render_landscape_frame($device, $state, $config, $width, $height);
    if (($device['layout'] ?? '') === 'portrait' && $width >= 650 && $width < 900 && $height >= 1200) render_phone_frame($device, $state, $config, $width, $height);
    if (($device['layout'] ?? '') === 'portrait' && $width >= 900 && $height >= 1200) render_portrait_frame($device, $state, $config, $width, $height);
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255); $black = imagecolorallocate($image, 0, 0, 0); $grey = imagecolorallocate($image, 90, 90, 90);
    imagefill($image, 0, 0, $white);
    $scale = min($width / 800, $height / 600); $margin = max(18, (int)(26 * $scale)); $now = time();
    $isLandscape = ($device['layout'] ?? '') === 'landscape';
    $calendarScale = $isLandscape ? min($scale, 1.25) : $scale;
    $panelScale = $isLandscape ? min($scale, 1.1) : $scale;
    draw_text($image, max(18, (int)(28 * $scale)), $margin, $margin + 24, 'E-INK DASHBOARD', $black);
    draw_text($image, max(16, (int)(22 * $scale)), $width - $margin, $margin + 24, date('H:i', $now), $black, 'right');
    imageline($image, $margin, $margin + 42, $width - $margin, $margin + 42, $grey);
    if (!$isLandscape) {
        $calendar = [$margin, (int)($height * .11), $width - $margin, (int)($height * .57)];
        $weather = [$margin, (int)($height * .60), $width - $margin, (int)($height * .74)];
        $quota = [$margin, (int)($height * .77), $width - $margin, $height - $margin];
    } else {
        $calendar = [$margin, (int)($height * .14), (int)($width * .58), $height - $margin];
        $weather = [(int)($width * .61), (int)($height * .17), $width - $margin, (int)($height * .48)];
        $quota = [(int)($width * .61), (int)($height * .53), $width - $margin, $height - $margin];
    }
    calendar_box($image, $calendar, $now, $calendarScale, $black, $grey, $white);
    box($image, $weather, $grey);
    $weatherState = is_array($state['weather'] ?? null) ? $state['weather'] : [];
    draw_text($image, max(14, (int)(19 * $panelScale)), $weather[0] + 18, $weather[1] + 32, (string)($weatherState['city'] ?? 'Weather'), $black);
    $temperature = is_numeric($weatherState['temperature'] ?? null) ? round((float)$weatherState['temperature']) . ' C' : '-- C';
    draw_text($image, max(20, (int)(30 * $panelScale)), $weather[0] + 18, $weather[1] + 72, $temperature, $black);
    draw_text($image, max(13, (int)(17 * $panelScale)), $weather[0] + 150, $weather[1] + 72, weather_label($weatherState['code'] ?? null), $black);
    $low = is_numeric($weatherState['low'] ?? null) ? round((float)$weatherState['low']) : '--'; $high = is_numeric($weatherState['high'] ?? null) ? round((float)$weatherState['high']) : '--';
    draw_text($image, max(10, (int)(13 * $panelScale)), $weather[0] + 18, $weather[3] - 14, 'Today ' . $low . '-' . $high . ' C  Humidity ' . ($weatherState['humidity'] ?? '--') . '%', $grey);
    box($image, $quota, $grey);
    draw_text($image, max(14, (int)(19 * $panelScale)), $quota[0] + 18, $quota[1] + 32, 'AI REMAINING', $black);
    $accounts = quota_accounts($state); $rowHeight = max(24, (int)(($quota[3] - $quota[1] - 52) / max(1, count($accounts))));
    foreach ($accounts as $index => $account) {
        $baseline = $quota[1] + 58 + $index * $rowHeight;
        $summary = substr($account['summary'], 0, $isLandscape ? 34 : 42);
        draw_text($image, max(10, (int)(14 * $panelScale)), $quota[0] + 18, $baseline, substr($account['name'], 0, 16), $black);
        draw_text($image, max(10, (int)(14 * $panelScale)), $quota[2] - 18, $baseline, $summary, $black, 'right');
    }
    $updated = (int)($state['weather_updated_at'] ?? 0);
    draw_text($image, max(9, (int)(12 * $scale)), $width - $margin, $height - 8, 'Weather ' . ($updated ? date('H:i', $updated) : '--:--'), $grey, 'right');
    if (!empty($device['rotate'])) {
        $rotatedImage = imagerotate($image, (int)$device['rotate'], $white);
        imagedestroy($image); $image = $rotatedImage;
    }
    header('Content-Type: image/png'); header('Cache-Control: no-store, max-age=0');
    imagepng($image, null, 9); imagedestroy($image); exit;
}

function render_viewer(array $device, string $id, string $token, array $config): void {
    if (($device['layout'] ?? '') === 'responsive' || strpos((string)($device['id'] ?? ''), 'iphone') === 0) {
        render_iphone_viewer($device, $id, $token, $config);
    }
    $framePath = '/frame/' . rawurlencode($id) . '/' . rawurlencode($token) . '.png';
    $refreshMilliseconds = max(1, (int)($config['DASHBOARD_REFRESH_MINUTES'] ?? 15)) * 60000;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,height=device-height,initial-scale=1,maximum-scale=1,user-scalable=no">';
    echo '<title>E-ink dashboard</title><style>html,body{margin:0;width:100%;height:100%;background:#fff;overflow:hidden}img{display:block;width:100%;height:100%;object-fit:contain}</style></head><body><img id="frame" alt="E-ink dashboard"><script>(function(){var image=document.getElementById("frame"),path=' . json_encode($framePath) . ';function refresh(){image.src=path+"?ts="+new Date().getTime();}refresh();setInterval(refresh,' . json_encode($refreshMilliseconds) . ');document.addEventListener("visibilitychange",function(){if(!document.hidden)refresh();});}());</script></body></html>';
    exit;
}

function clean_metric($value): ?array {
    if (!is_array($value) || !is_numeric($value['used'] ?? null)) return null;
    $metric = ['used' => max(0, min(100, (int)round((float)$value['used'])) )];
    if (is_numeric($value['reset_at'] ?? null) && (int)$value['reset_at'] > 0) $metric['reset_at'] = (int)$value['reset_at'];
    return $metric;
}

function ingest_quota(array $config): void {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!hash_equals('Bearer ' . ($config['DASHBOARD_INGEST_TOKEN'] ?? ''), $authorization)) fail(401);
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || !isset($payload['accounts']) || !is_array($payload['accounts']) || count($payload['accounts']) < 1 || count($payload['accounts']) > 5) json_response(['error' => 'accounts must contain 1 to 5 entries'], 400);
    $source = $payload['source'] ?? 'default';
    if (!is_string($source) || !preg_match('/^[a-z0-9_-]{1,32}$/', $source)) json_response(['error' => 'invalid source'], 400);
    $accounts = [];
    foreach ($payload['accounts'] as $account) {
        if (!is_array($account) || !is_string($account['name'] ?? null) || !is_string($account['summary'] ?? null) || trim($account['name']) === '' || trim($account['summary']) === '') json_response(['error' => 'invalid account'], 400);
        $entry = ['name' => substr(trim($account['name']), 0, 32), 'summary' => substr(trim($account['summary']), 0, 80)];
        if (is_string($account['plan'] ?? null) && trim($account['plan']) !== '') $entry['plan'] = substr(trim($account['plan']), 0, 20);
        $fiveHour = clean_metric($account['five_hour'] ?? null);
        $sevenDay = clean_metric($account['seven_day'] ?? null);
        $fable = clean_metric($account['fable'] ?? null);
        if ($fiveHour) $entry['five_hour'] = $fiveHour;
        if ($sevenDay) $entry['seven_day'] = $sevenDay;
        if ($fable) $entry['fable'] = $fable;
        $accounts[] = $entry;
    }
    update_state(function (&$state) use ($source, $accounts) {
        if (!is_array($state['quota'] ?? null)) $state['quota'] = [];
        if (!is_array($state['quota']['sources'] ?? null)) $state['quota']['sources'] = [];
        $state['quota']['sources'][$source] = ['accounts' => $accounts, 'updated_at' => date(DATE_ATOM)];
        $state['quota']['updated_at'] = date(DATE_ATOM);
        return true;
    });
    json_response(['ok' => true]);
}

function clean_status_number($value, float $minimum, ?float $maximum = null): ?float {
    if (!is_numeric($value)) return null;
    $number = (float)$value;
    if ($number < $minimum || ($maximum !== null && $number > $maximum)) return null;
    return $number;
}

function clean_server_status(array $payload): ?array {
    $cpu = $payload['cpu_percent'] ?? null;
    $cpuPercent = $cpu === null ? null : clean_status_number($cpu, 0, 100);
    if ($cpu !== null && $cpuPercent === null) return null;

    $load = is_array($payload['load'] ?? null) ? $payload['load'] : [];
    $loadOne = clean_status_number($load['one'] ?? null, 0);
    $loadFive = clean_status_number($load['five'] ?? null, 0);
    $loadFifteen = clean_status_number($load['fifteen'] ?? null, 0);
    if ($loadOne === null || $loadFive === null || $loadFifteen === null) return null;

    $memory = is_array($payload['memory'] ?? null) ? $payload['memory'] : [];
    $memoryTotal = clean_status_number($memory['total_bytes'] ?? null, 1);
    $memoryUsed = clean_status_number($memory['used_bytes'] ?? null, 0);
    $disk = is_array($payload['disk'] ?? null) ? $payload['disk'] : [];
    $diskTotal = clean_status_number($disk['total_bytes'] ?? null, 1);
    $diskUsed = clean_status_number($disk['used_bytes'] ?? null, 0);
    if ($memoryTotal === null || $memoryUsed === null || $memoryUsed > $memoryTotal || $diskTotal === null || $diskUsed === null || $diskUsed > $diskTotal) return null;

    $docker = is_array($payload['docker'] ?? null) ? $payload['docker'] : [];
    $containers = [];
    foreach (array_slice(is_array($docker['containers'] ?? null) ? $docker['containers'] : [], 0, 16) as $container) {
        if (!is_array($container)) continue;
        $name = trim((string)($container['name'] ?? ''));
        $status = trim((string)($container['status'] ?? ''));
        if ($name === '' || strlen($name) > 64 || strlen($status) > 96) continue;
        $containers[] = ['name' => $name, 'status' => $status, 'running' => !empty($container['running'])];
    }

    return [
        'updated_at' => date(DATE_ATOM),
        'cpu_percent' => $cpuPercent,
        'load' => ['one' => $loadOne, 'five' => $loadFive, 'fifteen' => $loadFifteen],
        'memory' => ['total_bytes' => $memoryTotal, 'used_bytes' => $memoryUsed],
        'disk' => ['total_bytes' => $diskTotal, 'used_bytes' => $diskUsed],
        'docker' => ['available' => !empty($docker['available']), 'containers' => $containers],
    ];
}

function ingest_server_status(array $config, string $serverId): void {
    $token = (string)($config['DASHBOARD_SERVER_STATUS_TOKEN'] ?? '');
    if ($token === '') fail(404);
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!hash_equals('Bearer ' . $token, $authorization)) fail(401);
    $allowedIds = array_column($config['servers'] ?? [], 'id');
    if (!in_array($serverId, $allowedIds, true) || $serverId === 'local') fail(404);
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) > 65536) json_response(['error' => 'invalid payload'], 400);
    $payload = json_decode($body, true);
    $status = is_array($payload) ? clean_server_status($payload) : null;
    if ($status === null) json_response(['error' => 'invalid server status'], 400);
    if (!is_dir(STATE_DIR) && !mkdir(STATE_DIR, 0700, true)) fail(500);
    $statusFile = server_status_file($serverId);
    $temporary = $statusFile . '.tmp';
    if (file_put_contents($temporary, json_encode($status, JSON_UNESCAPED_UNICODE), LOCK_EX) === false || !rename($temporary, $statusFile)) fail(500);
    chmod($statusFile, 0644);
    json_response(['ok' => true]);
}

$config = config();
$route = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($route === '/') render_public_calendar_weather_viewer($config);
if ($route === '/calendar-weather.json') render_public_calendar_weather_payload($config);
if ($route === '/health') json_response(['ok' => true]);
if ($route === '/v1/ingest/quota' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ingest_quota($config);
if (preg_match('#^/v1/ingest/server-status/([a-z0-9_-]+)$#', $route, $matches) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ingest_server_status($config, $matches[1]);
if (preg_match('#^/widget/([a-z0-9_-]+)/([a-f0-9]+)\\.json$#', $route, $matches)) {
    $device = device($config, $matches[1]);
    if (!$device || !hash_equals((string)($device['token'] ?? ''), $matches[2])) fail(404);
    render_widget_payload($device, $config);
}
if (preg_match('#^/viewer/([a-z0-9_-]+)/([a-f0-9]+)$#', $route, $matches)) {
    $device = device($config, $matches[1]);
    if (!$device || !hash_equals((string)($device['token'] ?? ''), $matches[2])) fail(404);
    render_viewer($device, $matches[1], $matches[2], $config);
}
if (preg_match('#^/frame/([a-z0-9_-]+)/([a-f0-9]+)\\.png$#', $route, $matches)) {
    $device = device($config, $matches[1]);
    if (!$device || !hash_equals((string)($device['token'] ?? ''), $matches[2])) fail(404);
    if (isset($_GET['battery']) && is_numeric($_GET['battery'])) {
        $battery = max(0, min(100, (int)$_GET['battery']));
        update_state(function (&$state) use ($device, $battery) {
            if (!is_array($state['device_status'] ?? null)) $state['device_status'] = [];
            $state['device_status'][$device['id']] = ['battery' => $battery, 'updated_at' => date(DATE_ATOM)];
            return true;
        });
    }
    render_frame($device, state_with_weather($config), $config);
}
fail(404);
