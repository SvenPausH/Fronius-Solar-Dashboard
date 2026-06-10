#!/usr/bin/env php
<?php
// =============================================================================
//  FRONIUS → INFLUXDB
//  Liest alle Wechselrichter-Daten aus und schreibt sie in eine InfluxDB.
//  Unterstützt optionale Modbus-TCP-Abfrage für per-MPPT-String-Daten.
//
//  Aufruf:
//    php fronius_influx.php              Normaler Lauf
//    php fronius_influx.php --dry-run    Nur Ausgabe, nichts schreiben
//    php fronius_influx.php --verbose    Mit Statusmeldungen
//    php fronius_influx.php --no-modbus  Modbus für diesen Lauf deaktivieren
//    php fronius_influx.php --help       Hilfe anzeigen
//
//  Cron (alle 5 Minuten):
//    */5 * * * * /usr/bin/php /var/www/fronius/fronius_influx.php >> /var/log/fronius.log 2>&1
//
//  Geschriebene Measurements:
//    fronius_inverter       → AC, DC, Energie, Phasen, Leistungsfluss
//    fronius_inverter_mppt  → per-MPPT: UDC, IDC, PDC (Modbus) oder DC-Gesamt (Fallback)
// =============================================================================

// ── Optionen parsen ────────────────────────────────────────────────────────
$opts       = getopt('', ['dry-run', 'verbose', 'no-modbus', 'help']);
$dry_run    = isset($opts['dry-run']);
$verbose    = isset($opts['verbose']) || $dry_run;
$no_modbus  = isset($opts['no-modbus']);
$help       = isset($opts['help']);

if ($help) {
    echo <<<HELP
Fronius → InfluxDB Exporter
============================
Liest Echtzeit-Daten von Fronius Wechselrichtern (Solar API v1 + optional Modbus TCP)
und schreibt sie im InfluxDB Line Protocol in InfluxDB v1 oder v2.

Verwendung:
  php fronius_influx.php [Optionen]

Optionen:
  --dry-run     Zeigt Line-Protocol-Zeilen an, schreibt nichts in InfluxDB
  --verbose     Ausführliche Statusmeldungen
  --no-modbus   Modbus TCP für diesen Lauf deaktivieren (überschreibt config.php)
  --help        Diese Hilfe anzeigen

Measurements:
  fronius_inverter       AC, DC-gesamt, Energie, Leistungsfluss, Phasen
                         Tags: inverter_id, inverter_name, ip
  fronius_inverter_mppt  Per-MPPT-Tracker-Daten (Modbus) oder DC-Gesamt (Fallback)
                         Tags: inverter_id, inverter_name, mppt_id, mppt_label, source

Konfiguration: config.php im selben Verzeichnis.
Modbus aktivieren: 'modbus' => ['enabled' => true] pro Wechselrichter in config.php

HELP;
    exit(0);
}

// ── Konfiguration laden ────────────────────────────────────────────────────
$cfg_path = __DIR__ . '/config.php';
if (!file_exists($cfg_path)) {
    fwrite(STDERR, "FEHLER: config.php nicht gefunden in " . __DIR__ . "\n");
    exit(1);
}
$config      = require $cfg_path;
$inverters   = $config['inverters'];
$api_timeout = $config['api_timeout'] ?? 5;
$influx_cfg  = $config['influxdb'];
$measurement = $influx_cfg['measurement'] ?? 'fronius_inverter';

// =============================================================================
//  REST API — Helfer
// =============================================================================

function fronius_get(string $ip, string $path, int $timeout = 5): ?array
{
    $url = "http://{$ip}/solar_api/v1/{$path}";
    $ctx = stream_context_create(['http' => [
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $json = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $json : null;
}

function val(?array $data, array $keys, $default = null)
{
    foreach ($keys as $k) {
        if (!is_array($data) || !array_key_exists($k, $data)) return $default;
        $data = $data[$k];
    }
    return $data ?? $default;
}

function unit_val(?array $data, array $keys): ?float
{
    $node = val($data, $keys);
    if (is_array($node) && isset($node['Value'])) return (float)$node['Value'];
    if (is_numeric($node)) return (float)$node;
    return null;
}

// =============================================================================
//  MODBUS TCP — Helfer (SunSpec Model 160 für per-MPPT-Daten)
// =============================================================================

function mb_open(string $ip, int $port, int $timeout): mixed
{
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!$sock) return null;
    stream_set_timeout($sock, $timeout);
    return $sock;
}

function mb_close(mixed $sock): void { if ($sock) @fclose($sock); }

function mb_sock_read(mixed $sock, int $n): ?string
{
    $buf  = '';
    $dead = microtime(true) + 2.0;
    while (strlen($buf) < $n) {
        if (microtime(true) > $dead) return null;
        $chunk = @fread($sock, $n - strlen($buf));
        if ($chunk === false || $chunk === '') { usleep(2_000); continue; }
        $buf .= $chunk;
    }
    return strlen($buf) === $n ? $buf : null;
}

function mb_read(mixed $sock, int $unit, int $addr, int $count): ?array
{
    if (!$sock || $count < 1 || $count > 125) return null;
    static $tid = 0;
    $tid = ($tid + 1) & 0xFFFF;

    $req = pack('nnnCCnn', $tid, 0x0000, 0x0006, $unit, 0x03, $addr, $count);
    if (@fwrite($sock, $req) === false) return null;

    $hdr = mb_sock_read($sock, 9);
    if (!$hdr) return null;
    if (ord($hdr[7]) & 0x80) return null;

    $byte_count = ord($hdr[8]);
    $payload    = mb_sock_read($sock, $byte_count);
    if (!$payload) return null;

    $regs = [];
    for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($payload); $i++) {
        $regs[] = unpack('n', substr($payload, $i * 2, 2))[1];
    }
    return count($regs) === $count ? $regs : null;
}

function mb_i16(int $v): int { return $v > 0x7FFF ? $v - 0x10000 : $v; }

function mb_scale(int $raw, int $sf): ?float
{
    if ($raw === 0xFFFF || $sf === -32768) return null;
    return round($raw * (10 ** mb_i16($sf)), max(0, -mb_i16($sf)));
}

function sunspec_discover(mixed $sock, int $unit): array
{
    foreach ([40000, 0] as $base) {
        $id = mb_read($sock, $unit, $base, 2);
        if ($id && $id[0] === 0x5375 && $id[1] === 0x6E53) {
            return sunspec_scan($sock, $unit, $base + 2);
        }
    }
    return [];
}

function sunspec_scan(mixed $sock, int $unit, int $pos): array
{
    $models = [];
    for ($i = 0; $i < 40; $i++) {
        $hdr = mb_read($sock, $unit, $pos, 2);
        if (!$hdr) break;
        [$model_id, $model_len] = $hdr;
        if ($model_id === 0xFFFF) break;
        if ($model_id > 0 && $model_len > 0) {
            $models[$model_id] = ['data_addr' => $pos + 2, 'len' => $model_len];
        }
        $pos += 2 + $model_len;
    }
    return $models;
}

function sunspec_read_mppt(mixed $sock, int $unit, array $models): array
{
    if (!isset($models[160])) return [];

    $addr = $models[160]['data_addr'];
    $len  = $models[160]['len'];

    $hdr = mb_read($sock, $unit, $addr, 8);
    if (!$hdr || count($hdr) < 8) return [];

    $dca_sf      = mb_i16($hdr[0]);
    $dcv_sf      = mb_i16($hdr[1]);
    $dcw_sf      = mb_i16($hdr[2]);
    $n           = $hdr[6];
    $module_size = (int)(($len - 8) / max($n, 1));

    if ($n === 0 || $n > 16 || $module_size < 12) return [];

    $raw = mb_read($sock, $unit, $addr + 8, min($n * $module_size, 125));
    if (!$raw) return [];

    $result = [];
    for ($i = 0; $i < $n; $i++) {
        $o = $i * $module_size;
        if ($o + 11 >= count($raw)) break;

        $label = '';
        for ($c = 1; $c <= 8 && ($o + $c) < count($raw); $c++) {
            $w = $raw[$o + $c];
            $h = ($w >> 8) & 0xFF;
            $l = $w & 0xFF;
            if ($h > 0x1F) $label .= chr($h);
            if ($l > 0x1F) $label .= chr($l);
        }
        $label = trim($label) ?: 'MPPT ' . ($i + 1);

        $result[$i + 1] = [
            'idc'   => mb_scale($raw[$o + 9],  $dca_sf),
            'udc'   => mb_scale($raw[$o + 10], $dcv_sf),
            'pdc'   => mb_scale($raw[$o + 11], $dcw_sf),
            'label' => $label,
        ];
    }
    return $result;
}

// =============================================================================
//  INFLUXDB — Line Protocol Helfer
// =============================================================================

function lp_tag(string $v): string
{
    return str_replace([' ', ',', '='], ['\ ', '\,', '\='], $v);
}

function build_line(string $measurement, array $tags, array $fields, ?int $ts = null): ?string
{
    $m = str_replace([' ', ','], ['\ ', '\,'], $measurement);

    $tag_str = '';
    foreach ($tags as $k => $v) {
        if ($v === null || $v === '') continue;
        $tag_str .= ',' . lp_tag($k) . '=' . lp_tag((string)$v);
    }

    $field_parts = [];
    foreach ($fields as $k => $v) {
        if ($v === null) continue;
        $fk = str_replace([' ', ',', '='], ['\ ', '\,', '\='], $k);
        if (is_float($v))    $field_parts[] = $fk . '=' . sprintf('%.4f', $v);
        elseif (is_int($v))  $field_parts[] = $fk . '=' . $v . 'i';
        elseif (is_bool($v)) $field_parts[] = $fk . '=' . ($v ? 'true' : 'false');
        else                 $field_parts[] = $fk . '=' . '"' . addslashes((string)$v) . '"';
    }
    if (empty($field_parts)) return null;

    $line = $m . $tag_str . ' ' . implode(',', $field_parts);
    if ($ts !== null) $line .= ' ' . $ts;
    return $line;
}

// =============================================================================
//  INFLUXDB — Schreiben
// =============================================================================

function influx_write(array $cfg, string $body, bool $dry_run, bool $verbose): bool
{
    $version = (int)($cfg['version'] ?? 2);
    $base    = rtrim($cfg['host'], '/') . ':' . ($cfg['port'] ?? 8086);

    if ($version === 1) {
        $v1      = $cfg['v1'];
        $db      = urlencode($v1['database'] ?? 'solar');
        $url     = "{$base}/write?db={$db}&precision=ns";
        $headers = ['Content-Type: text/plain; charset=utf-8'];
        if (!empty($v1['username']) && !empty($v1['password'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($v1['username'] . ':' . $v1['password']);
        }
    } else {
        $v2      = $cfg['v2'];
        $org     = urlencode($v2['org']    ?? '');
        $bkt     = urlencode($v2['bucket'] ?? 'solar');
        $url     = "{$base}/api/v2/write?org={$org}&bucket={$bkt}&precision=ns";
        $headers = [
            'Content-Type: text/plain; charset=utf-8',
            'Authorization: Token ' . ($v2['token'] ?? ''),
        ];
    }

    if ($dry_run) {
        echo "\n--- Würde schreiben an: {$url}\n";
        echo $body . "\n";
        return true;
    }

    $ctx      = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    $status   = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('/^HTTP\/\S+ (\d+)/', $h, $m)) $status = (int)$m[1];
    }

    $ok = in_array($status, [200, 204], true);
    if ($verbose) {
        echo ($ok ? '✓' : '✗') . " InfluxDB HTTP {$status}"
           . ($ok ? '' : " — " . substr((string)$response, 0, 200)) . "\n";
    }
    return $ok;
}

// =============================================================================
//  HAUPTPROGRAMM
// =============================================================================

$timestamp_ns = (int)(microtime(true) * 1e9);
$lines        = [];
$errors       = 0;

if ($verbose) {
    echo date('Y-m-d H:i:s') . " — Fronius Influx Exporter\n";
    echo "Verarbeite " . count($inverters) . " Wechselrichter …\n";
}

foreach ($inverters as $id => $cfg) {
    $ip   = $cfg['ip'];
    $did  = $cfg['device_id'] ?? 1;
    $name = $cfg['name'] ?? "WR{$id}";

    // ── REST API ───────────────────────────────────────────────────────────
    if ($verbose) echo "  [{$name}] REST …";

    $realtime   = fronius_get($ip, "GetInverterRealtimeData.cgi?Scope=Device&DeviceId={$did}&DataCollection=CommonInverterData", $api_timeout);
    $powerflow  = fronius_get($ip, 'GetPowerFlowRealtimeData.fcgi', $api_timeout);
    $phase3_raw = fronius_get($ip, "GetInverterRealtimeData.cgi?Scope=Device&DeviceId={$did}&DataCollection=3PInverterData", $api_timeout);
    $minmax_raw = fronius_get($ip, "GetInverterRealtimeData.cgi?Scope=Device&DeviceId={$did}&DataCollection=MinMaxInverterData", $api_timeout);

    if ($realtime === null) {
        if ($verbose) echo " OFFLINE\n";
        $errors++;
        $line = build_line($measurement,
            ['inverter_id' => (string)$id, 'inverter_name' => $name, 'ip' => $ip],
            ['online' => false, 'pac_w' => 0.0],
            $timestamp_ns
        );
        if ($line) $lines[] = $line;
        continue;
    }

    $body    = val($realtime,   ['Body', 'Data']);
    $pf_site = val($powerflow,  ['Body', 'Data', 'Site']);
    $pf_inv  = val($powerflow,  ['Body', 'Data', 'Inverters', '1']);
    $phase3  = val($phase3_raw, ['Body', 'Data']);
    $minmax  = val($minmax_raw, ['Body', 'Data']);

    $pac         = unit_val($body, ['PAC']);
    $dc_udc      = unit_val($body, ['UDC']);
    $dc_idc      = unit_val($body, ['IDC']);
    $status_code = (int)(val($body, ['DeviceStatus', 'StatusCode']) ?? -1);
    $error_code  = (int)(val($body, ['DeviceStatus', 'ErrorCode'])  ?? 0);

    if ($verbose) echo " OK ({$pac} W)";

    // ── Modbus TCP: per-MPPT-Daten ─────────────────────────────────────────
    $mb_cfg      = $cfg['modbus'] ?? [];
    $mb_enabled  = !$no_modbus && (bool)($mb_cfg['enabled'] ?? false);
    $mb_port     = (int)($config['modbus']['port']    ?? 502);
    $mb_timeout  = (int)($config['modbus']['timeout'] ?? 3);
    $mb_unit     = (int)($mb_cfg['unit_id'] ?? 1);

    $mppt_data   = [];  // [1 => ['idc','udc','pdc','label'], ...]
    $mb_ok       = false;

    if ($mb_enabled) {
        if ($verbose) echo ", Modbus …";
        $sock = mb_open($ip, $mb_port, $mb_timeout);
        if ($sock) {
            $models    = sunspec_discover($sock, $mb_unit);
            $mppt_data = sunspec_read_mppt($sock, $mb_unit, $models);
            $mb_ok     = !empty($mppt_data);
            mb_close($sock);
            if ($verbose) echo ($mb_ok ? " " . count($mppt_data) . " MPPT" : " kein Model 160");
        } else {
            if ($verbose) echo " Verbindung fehlgeschlagen";
        }
    }

    // Fallback: DC-Gesamtwert wenn Modbus deaktiviert oder fehlgeschlagen
    if (empty($mppt_data) && ($dc_udc !== null || $dc_idc !== null)) {
        $mppt_data = [1 => [
            'idc'   => $dc_idc,
            'udc'   => $dc_udc,
            'pdc'   => ($dc_udc !== null && $dc_idc !== null) ? round($dc_udc * $dc_idc, 1) : null,
            'label' => 'DC_gesamt',
        ]];
    }

    if ($verbose) echo "\n";

    // ── Haupt-Measurement: fronius_inverter ────────────────────────────────
    $fields_main = [
        'online'          => true,
        'status_code'     => $status_code,
        'error_code'      => $error_code,
        'pac_w'           => $pac,
        'uac_v'           => unit_val($body, ['UAC']),
        'iac_a'           => unit_val($body, ['IAC']),
        'fac_hz'          => unit_val($body, ['FAC']),
        'udc_v'           => $dc_udc,
        'idc_a'           => $dc_idc,
        'energy_day_wh'   => unit_val($body, ['DAY_ENERGY']),
        'energy_year_wh'  => unit_val($body, ['YEAR_ENERGY']),
        'energy_total_wh' => unit_val($body, ['TOTAL_ENERGY']),
        // MinMax
        'pac_max_w'       => unit_val($minmax, ['PAC_Max']),
        'udc_max_v'       => unit_val($minmax, ['UDC_Max']),
        'udc_min_v'       => unit_val($minmax, ['UDC_Min']),
        // Modbus-Status als Feld — nützlich für Grafana-Monitoring
        'modbus_enabled'  => $mb_enabled,
        'modbus_ok'       => $mb_ok,
    ];

    // Leistungsfluss
    if ($pf_site !== null) {
        $fields_main['p_grid_w'] = is_numeric($pf_site['P_Grid'] ?? null) ? (float)$pf_site['P_Grid'] : null;
        $fields_main['p_load_w'] = is_numeric($pf_site['P_Load'] ?? null) ? (float)$pf_site['P_Load'] : null;
        $fields_main['p_pv_w']   = is_numeric($pf_site['P_PV']   ?? null) ? (float)$pf_site['P_PV']   : null;
    }
    if ($pf_inv !== null && isset($pf_inv['SOC'])) {
        $fields_main['battery_soc_pct'] = (float)$pf_inv['SOC'];
    }

    // 3-Phasen
    if ($phase3 !== null) {
        foreach ([1, 2, 3] as $ph) {
            $fields_main["uac_l{$ph}_v"] = unit_val($phase3, ["UAC_L{$ph}"]);
            $fields_main["iac_l{$ph}_a"] = unit_val($phase3, ["IAC_L{$ph}"]);
        }
    }

    $line = build_line($measurement,
        ['inverter_id' => (string)$id, 'inverter_name' => $name, 'ip' => $ip],
        $fields_main, $timestamp_ns
    );
    if ($line) $lines[] = $line;

    // ── MPPT-Measurement: fronius_inverter_mppt ────────────────────────────
    // Tag 'source': 'modbus' = echte Einzelwerte, 'rest_fallback' = DC-Gesamt
    $mppt_source = ($mb_enabled && $mb_ok) ? 'modbus' : 'rest_fallback';

    foreach ($mppt_data as $mppt_id => $m) {
        $mppt_fields = [
            'udc_v' => $m['udc'],
            'idc_a' => $m['idc'],
            'pdc_w' => $m['pdc'],
        ];
        $line = build_line(
            $measurement . '_mppt',
            [
                'inverter_id'   => (string)$id,
                'inverter_name' => $name,
                'mppt_id'       => (string)$mppt_id,
                'mppt_label'    => $m['label'],
                'source'        => $mppt_source,
            ],
            $mppt_fields,
            $timestamp_ns
        );
        if ($line) $lines[] = $line;
    }
}

// =============================================================================
//  SCHREIBEN
// =============================================================================

if (empty($lines)) {
    fwrite(STDERR, "WARNUNG: Keine Datenpunkte gesammelt.\n");
    exit(1);
}

$payload = implode("\n", $lines);

if ($verbose && !$dry_run) {
    echo "\nSchreibe " . count($lines) . " Zeilen in InfluxDB v"
       . ($influx_cfg['version'] ?? 2) . " …\n";
}

$ok = influx_write($influx_cfg, $payload, $dry_run, $verbose);

if ($verbose && !$dry_run) {
    echo $ok ? "Fertig.\n" : "FEHLER beim Schreiben in InfluxDB.\n";
}

if ($errors > 0 && $verbose) {
    echo "Hinweis: {$errors} Wechselrichter offline / nicht erreichbar.\n";
}

exit($ok ? 0 : 1);
