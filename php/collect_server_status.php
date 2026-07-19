<?php
declare(strict_types=1);

$dataDir = getenv('DASHBOARD_DATA_DIR') ?: __DIR__ . '/.dashboard-data';
$statusFile = $dataDir . '/server-status.json';
$cpuFile = $dataDir . '/server-status-cpu.json';

function cpu_totals(): ?array {
    $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || !isset($lines[0])) return null;
    $parts = preg_split('/\s+/', trim($lines[0]));
    if (!is_array($parts) || ($parts[0] ?? '') !== 'cpu' || count($parts) < 5) return null;
    $values = array_map('floatval', array_slice($parts, 1));
    return ['total' => array_sum($values), 'idle' => ($values[3] ?? 0) + ($values[4] ?? 0)];
}

function memory_status(): array {
    $values = [];
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/', $line, $matches)) $values[$matches[1]] = (int)$matches[2] * 1024;
    }
    $total = $values['MemTotal'] ?? 0;
    $available = $values['MemAvailable'] ?? 0;
    return ['total_bytes' => $total, 'used_bytes' => max(0, $total - $available)];
}

function docker_status(): array {
    $path = trim((string)@shell_exec('command -v docker 2>/dev/null'));
    if ($path === '') return ['available' => false, 'containers' => []];
    $lines = [];
    $exitCode = 1;
    exec(escapeshellarg($path) . ' ps -a --format "{{.Names}}\t{{.Status}}" 2>/dev/null', $lines, $exitCode);
    if ($exitCode !== 0) return ['available' => false, 'containers' => []];
    $containers = [];
    foreach ($lines as $line) {
        [$name, $status] = array_pad(explode("\t", $line, 2), 2, '');
        if ($name === '') continue;
        $containers[] = ['name' => $name, 'status' => $status, 'running' => strncmp($status, 'Up ', 3) === 0];
    }
    return ['available' => true, 'containers' => $containers];
}

if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
$cpu = cpu_totals();
$previous = json_decode((string)@file_get_contents($cpuFile), true);
$cpuPercent = null;
if (is_array($cpu) && is_array($previous) && is_numeric($previous['total'] ?? null) && is_numeric($previous['idle'] ?? null)) {
    $deltaTotal = $cpu['total'] - (float)$previous['total'];
    $deltaIdle = $cpu['idle'] - (float)$previous['idle'];
    if ($deltaTotal > 0) $cpuPercent = round(max(0, min(100, 100 * ($deltaTotal - $deltaIdle) / $deltaTotal)), 1);
}
if (is_array($cpu)) file_put_contents($cpuFile . '.tmp', json_encode($cpu), LOCK_EX) && rename($cpuFile . '.tmp', $cpuFile);

$totalDisk = @disk_total_space('/');
$freeDisk = @disk_free_space('/');
$load = sys_getloadavg() ?: [0, 0, 0];
$payload = [
    'updated_at' => date(DATE_ATOM),
    'cpu_percent' => $cpuPercent,
    'load' => ['one' => (float)($load[0] ?? 0), 'five' => (float)($load[1] ?? 0), 'fifteen' => (float)($load[2] ?? 0)],
    'memory' => memory_status(),
    'disk' => ['total_bytes' => $totalDisk === false ? 0 : $totalDisk, 'used_bytes' => $totalDisk === false || $freeDisk === false ? 0 : max(0, $totalDisk - $freeDisk)],
    'docker' => docker_status(),
];
file_put_contents($statusFile . '.tmp', json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
rename($statusFile . '.tmp', $statusFile);
chmod($statusFile, 0644);
