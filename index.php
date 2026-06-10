<?php
// =============================================================================
//  FRONIUS SOLAR DASHBOARD
//  Konfiguration: config.php (im selben Verzeichnis)
// =============================================================================

$cfg_path = __DIR__ . '/config.php';
if (!file_exists($cfg_path)) {
    die('<pre style="font-family:monospace;color:red;padding:2em">Fehler: config.php nicht gefunden!'
      . "\nBitte config.php im selben Verzeichnis ablegen.</pre>");
}
$config      = require $cfg_path;
$inverters   = $config['inverters'];
$api_timeout = $config['api_timeout'] ?? 5;

// =============================================================================
//  API-Helfer
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

/**
 * Liest per-String-Werte aus NowStringControlData.
 * Fronius liefert je nach Firmware zwei verschiedene Strukturen:
 *
 * Format A (aeltere FW):  { "IDC": { "1": {"Value":4.5}, "2": {"Value":3.2} } }
 * Format B (neuere FW):   { "1": { "IDC": {"Value":4.5}, "UDC": ... }, "2": { ... } }
 *
 * Gibt [string_nr => float] zurück.
 */
function mppt_vals(?array $body, string $field): array
{
    if (!is_array($body)) return [];

    // ── Format A: body[field][index] = {Value: x} ────────────────────────
    $node = $body[$field] ?? null;
    if (is_array($node)) {
        $out = [];
        foreach ($node as $idx => $v) {
            if (is_array($v) && isset($v['Value'])) {
                $out[(int)$idx] = (float)$v['Value'];
            } elseif (is_numeric($v)) {
                $out[(int)$idx] = (float)$v;
            }
        }
        if (!empty($out)) { ksort($out); return $out; }
    }

    // ── Format B: body[index][field] = {Value: x} ────────────────────────
    $out = [];
    foreach ($body as $idx => $channel) {
        if (!is_numeric($idx) || !is_array($channel)) continue;
        $sub = $channel[$field] ?? null;
        if (is_array($sub) && isset($sub['Value'])) {
            $out[(int)$idx] = (float)$sub['Value'];
        } elseif (is_numeric($sub)) {
            $out[(int)$idx] = (float)$sub;
        }
    }
    ksort($out);
    return $out;
}

function fmt(?float $v, int $dec = 1, string $unit = ''): string
{
    if ($v === null) return '<span class="na">—</span>';
    return number_format($v, $dec, ',', '.') . ($unit ? ' <small>' . htmlspecialchars($unit) . '</small>' : '');
}

function fmt_energy(?float $wh): string
{
    if ($wh === null) return '<span class="na">—</span>';
    if ($wh >= 1_000_000) return number_format($wh / 1_000_000, 2, ',', '.') . ' <small>MWh</small>';
    if ($wh >= 1_000)     return number_format($wh / 1_000,     2, ',', '.') . ' <small>kWh</small>';
    return number_format($wh, 0, ',', '.') . ' <small>Wh</small>';
}

function status_label(int $code): array
{
    // Fronius StatusCode-Tabelle (Solar API v1, FW 3.x)
    // StatusCode 7 = normaler Betrieb / Einspeisen (haeufigster Wert tagsüber!)
    // Fehler wird NICHT über StatusCode, sondern über ErrorCode signalisiert.
    $map = [
        0   => ['Hochlauf',        'status-warn'],
        1   => ['Initialisierung', 'status-warn'],
        2   => ['ISO-Test',        'status-warn'],
        3   => ['Netzsync',        'status-warn'],
        4   => ['Anfahren',        'status-warn'],
        5   => ['Einspeisen',      'status-active'],
        6   => ['Einspeisen',      'status-active'],
        7   => ['Einspeisen',      'status-active'],
        8   => ['Standby',         'status-standby'],
        9   => ['Kein Netz',       'status-warn'],
        10  => ['Bereit',          'status-ok'],
        11  => ['Warten',          'status-warn'],
        255 => ['Fehler',          'status-err'],
    ];
    return $map[$code] ?? ["Status $code", 'status-standby'];
}

// =============================================================================
//  MODBUS TCP — Helfer (SunSpec Model 160 für per-MPPT-Daten)
// =============================================================================

/** Öffnet eine Modbus-TCP-Verbindung. Gibt Socket-Handle oder null zurück. */
function mb_open(string $ip, int $port, int $timeout): mixed
{
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!$sock) return null;
    stream_set_timeout($sock, $timeout);
    return $sock;
}

function mb_close(mixed $sock): void { if ($sock) @fclose($sock); }

/** Liest exakt $n Bytes vom Socket (Blocking mit Timeout). */
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

/**
 * Liest $count Holding-Register (FC 03) ab 0-basierter Adresse.
 * Gibt uint16-Array oder null bei Fehler zurück.
 */
function mb_read(mixed $sock, int $unit, int $addr, int $count): ?array
{
    if (!$sock || $count < 1 || $count > 125) return null;
    static $tid = 0;
    $tid = ($tid + 1) & 0xFFFF;

    // MBAP-Header(6) + Unit-ID(1) + FC(1) + Addr(2) + Qty(2) = 12 Bytes
    $req = pack('nnnCCnn', $tid, 0x0000, 0x0006, $unit, 0x03, $addr, $count);
    if (@fwrite($sock, $req) === false) return null;

    $hdr = mb_sock_read($sock, 9); // MBAP(7) + FC(1) + ByteCount(1)
    if (!$hdr) return null;
    if (ord($hdr[7]) & 0x80) return null; // Exception-Response

    $byte_count = ord($hdr[8]);
    $payload    = mb_sock_read($sock, $byte_count);
    if (!$payload) return null;

    $regs = [];
    for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($payload); $i++) {
        $regs[] = unpack('n', substr($payload, $i * 2, 2))[1];
    }
    return count($regs) === $count ? $regs : null;
}

/** uint16 → int16 (Vorzeichen-Korrektur für SunSpec-Skalierungsfaktoren). */
function mb_i16(int $v): int { return $v > 0x7FFF ? $v - 0x10000 : $v; }

/** Wendet SunSpec-Skalierungsfaktor an (Wert × 10^SF). 0x8000 = nicht implementiert. */
function mb_scale(int $raw, int $sf): ?float
{
    if ($raw === 0xFFFF || $sf === -32768) return null; // "not implemented"
    return round($raw * (10 ** mb_i16($sf)), max(0, -mb_i16($sf)));
}

// ── SunSpec Discovery ─────────────────────────────────────────────────────

/**
 * Sucht SunSpec-Modelle auf dem Gerät.
 * Gibt assoziatives Array ['model_id' => ['data_addr'=>int, 'len'=>int]] zurück.
 * Fronius: SunSpec beginnt bei Register 40001 (0-basiert: 40000).
 */
function sunspec_discover(mixed $sock, int $unit): array
{
    foreach ([40000, 0] as $base) {
        $id = mb_read($sock, $unit, $base, 2);
        // SunSpec-Kennung "SunS" = 0x53756E53
        if ($id && $id[0] === 0x5375 && $id[1] === 0x6E53) {
            return sunspec_scan($sock, $unit, $base + 2);
        }
    }
    return [];
}

function sunspec_scan(mixed $sock, int $unit, int $pos): array
{
    $models = [];
    for ($i = 0; $i < 40; $i++) {  // max 40 Modelle
        $hdr = mb_read($sock, $unit, $pos, 2);
        if (!$hdr) break;
        [$model_id, $model_len] = $hdr;
        if ($model_id === 0xFFFF) break; // End-Marker
        if ($model_id > 0 && $model_len > 0) {
            $models[$model_id] = ['data_addr' => $pos + 2, 'len' => $model_len];
        }
        $pos += 2 + $model_len;
    }
    return $models;
}

// ── SunSpec Model 160 — Multiple MPPT ────────────────────────────────────

/**
 * Liest per-MPPT-Tracker-Daten aus SunSpec Model 160.
 *
 * Model-160-Aufbau (nach ID+L):
 *   Header (8 Register): DCA_SF, DCV_SF, DCW_SF, DCWH_SF, Evt(2), N, TmsPer
 *   Je Modul (20 Register): ID, IDStr(8), DCA, DCV, DCW, DCWH(2), Tms(2), Tmp, DCSt, DCEvt(2)
 *
 * Gibt zurück: [1 => ['idc'=>A, 'udc'=>V, 'pdc'=>W, 'label'=>str], 2 => ...]
 */
function sunspec_read_mppt(mixed $sock, int $unit, array $models): array
{
    if (!isset($models[160])) return [];

    $addr = $models[160]['data_addr'];
    $len  = $models[160]['len'];

    // Header lesen
    $hdr = mb_read($sock, $unit, $addr, 8);
    if (!$hdr || count($hdr) < 8) return [];

    $dca_sf = mb_i16($hdr[0]);
    $dcv_sf = mb_i16($hdr[1]);
    $dcw_sf = mb_i16($hdr[2]);
    $n      = $hdr[6]; // Anzahl MPPT-Module

    if ($n === 0 || $n > 16) return [];

    // Modulblöcke: Größe aus Modell-Länge berechnen (robuster als hart kodiert)
    $module_size = (int)(($len - 8) / $n); // üblicherweise 20 Register
    if ($module_size < 12) return [];

    // Alle Module auf einmal lesen (max 125 Register pro Anfrage)
    $total = $n * $module_size;
    $raw   = mb_read($sock, $unit, $addr + 8, min($total, 125));
    if (!$raw) return [];

    $result = [];
    for ($i = 0; $i < $n; $i++) {
        $o = $i * $module_size;
        if ($o + 11 >= count($raw)) break;

        // IDStr: 8 Register = 16 ASCII-Zeichen
        $label = '';
        for ($c = 1; $c <= 8 && ($o + $c) < count($raw); $c++) {
            $w = $raw[$o + $c];
            $h = ($w >> 8) & 0xFF;
            $l = $w & 0xFF;
            if ($h > 0x1F) $label .= chr($h);
            if ($l > 0x1F) $label .= chr($l);
        }
        $label = trim($label) ?: 'MPPT ' . ($i + 1);

        $idc = mb_scale($raw[$o + 9],  $dca_sf);
        $udc = mb_scale($raw[$o + 10], $dcv_sf);
        $pdc = mb_scale($raw[$o + 11], $dcw_sf);

        $result[$i + 1] = compact('idc', 'udc', 'pdc', 'label');
    }
    return $result;
}

// =============================================================================
//  Daten abrufen
// =============================================================================

$data = [];
foreach ($inverters as $id => $cfg) {
    $ip  = $cfg['ip'];
    $did = $cfg['device_id'] ?? 1;

    $realtime    = fronius_get($ip, "GetInverterRealtimeData.cgi?Scope=Device&DeviceId={$did}&DataCollection=CommonInverterData", $api_timeout);
    $powerflow   = fronius_get($ip, 'GetPowerFlowRealtimeData.fcgi', $api_timeout);
    $logger_raw  = fronius_get($ip, 'GetLoggerInfo.cgi', $api_timeout);
    $phase3_raw  = fronius_get($ip, "GetInverterRealtimeData.cgi?Scope=Device&DeviceId={$did}&DataCollection=3PInverterData", $api_timeout);
    $minmax_raw  = fronius_get($ip, "GetInverterRealtimeData.cgi?Scope=Device&DeviceId={$did}&DataCollection=MinMaxInverterData", $api_timeout);
    // NowStringControlData existiert NICHT auf Fronius Symo/Primo mit dieser FW.
    // Gültige DataCollections: CommonInverterData, 3PInverterData, CumulationInverterData, MinMaxInverterData

    $body    = val($realtime,   ['Body', 'Data']);
    $pf_site = val($powerflow,  ['Body', 'Data', 'Site']);
    $pf_inv  = val($powerflow,  ['Body', 'Data', 'Inverters', '1']);
    $log     = val($logger_raw, ['Body', 'LoggerInfo']);
    $phase3  = val($phase3_raw, ['Body', 'Data']);
    $minmax  = val($minmax_raw, ['Body', 'Data']);

    // DC-Werte aus CommonInverterData (Gesamtwert aller Strings zusammen)
    // Per-String-Aufteilung: REST API liefert diese NICHT — nur Modbus TCP
    $dc_udc = unit_val($body, ['UDC']);
    $dc_idc = unit_val($body, ['IDC']);
    $dc_pdc = ($dc_udc !== null && $dc_idc !== null) ? round($dc_udc * $dc_idc, 1) : null;

    // MinMax-Daten (Tages-Min/Max)
    $udc_max = unit_val($minmax, ['UDC_Max']);
    $udc_min = unit_val($minmax, ['UDC_Min']);
    $pac_max = unit_val($minmax, ['PAC_Max']);

    // ── Modbus TCP: per-MPPT String-Daten (SunSpec Model 160) ─────────────
    $mb_cfg     = $cfg['modbus'] ?? [];
    $mb_enabled = (bool)($mb_cfg['enabled'] ?? false);
    $mb_port    = (int)($config['modbus']['port']    ?? 502);
    $mb_timeout = (int)($config['modbus']['timeout'] ?? 3);
    $mb_unit    = (int)($mb_cfg['unit_id'] ?? 1);

    $mppt_data   = [];   // [1 => ['idc','udc','pdc','label'], 2 => ...]
    $mb_status   = '';   // Statusmeldung für die Anzeige
    $mb_models   = [];   // gefundene SunSpec-Modelle (für Debug)

    if ($mb_enabled) {
        $sock = mb_open($ip, $mb_port, $mb_timeout);
        if (!$sock) {
            $mb_status = "Verbindung fehlgeschlagen ({$ip}:{$mb_port}) — Modbus TCP im Fronius Webinterface aktiviert?";
        } else {
            $mb_models = sunspec_discover($sock, $mb_unit);
            if (empty($mb_models)) {
                $mb_status = "SunSpec-Kennung nicht gefunden — Unit-ID in config.php korrekt? (aktuell: {$mb_unit})";
            } elseif (!isset($mb_models[160])) {
                $mb_status = "SunSpec Model 160 (MPPT) nicht gefunden. Gefundene Modelle: " . implode(', ', array_keys($mb_models));
            } else {
                $mppt_data = sunspec_read_mppt($sock, $mb_unit, $mb_models);
                $mb_status = empty($mppt_data)
                    ? "Model 160 gefunden, aber keine Modul-Daten gelesen."
                    : "OK — " . count($mppt_data) . " MPPT-Tracker via SunSpec Model 160";
            }
            mb_close($sock);
        }
    }

    $data[$id] = [
        'cfg'        => $cfg,
        'online'     => ($realtime !== null && $body !== null),
        'pac'        => unit_val($body, ['PAC']),
        'iac'        => unit_val($body, ['IAC']),
        'uac'        => unit_val($body, ['UAC']),
        'fac'        => unit_val($body, ['FAC']),
        'idc'        => $dc_idc,
        'udc'        => $dc_udc,
        'pdc'        => $dc_pdc,
        'e_day'      => unit_val($body, ['DAY_ENERGY']),
        'e_year'     => unit_val($body, ['YEAR_ENERGY']),
        'e_total'    => unit_val($body, ['TOTAL_ENERGY']),
        'status'     => (int)(val($body, ['DeviceStatus', 'StatusCode']) ?? -1),
        'error'      => (int)(val($body, ['DeviceStatus', 'ErrorCode'])  ?? 0),
        'p_grid'     => val($pf_site, ['P_Grid']),
        'p_load'     => val($pf_site, ['P_Load']),
        'p_pv'       => val($pf_site, ['P_PV']),
        'soc'        => val($pf_inv,  ['SOC']),
        'iac_l1'     => unit_val($phase3, ['IAC_L1']),
        'iac_l2'     => unit_val($phase3, ['IAC_L2']),
        'iac_l3'     => unit_val($phase3, ['IAC_L3']),
        'uac_l1'     => unit_val($phase3, ['UAC_L1']),
        'uac_l2'     => unit_val($phase3, ['UAC_L2']),
        'uac_l3'     => unit_val($phase3, ['UAC_L3']),
        'udc_max'    => $udc_max,
        'udc_min'    => $udc_min,
        'pac_max'    => $pac_max,
        // Modbus MPPT-Daten
        'mb_enabled' => $mb_enabled,
        'mb_status'  => $mb_status,
        'mb_models'  => $mb_models,
        'mppt'       => $mppt_data,
        'product'    => val($log, ['ProductName']),
        'serial'     => val($log, ['SerialNumber']),
        'sw'         => val($log, ['SWVersion']),
        'hw'         => val($log, ['HWVersion']),
        '_raw'       => compact('realtime','powerflow','logger_raw','phase3_raw','minmax_raw'),
    ];
}

$total_pac   = array_sum(array_filter(array_column($data, 'pac')));
$total_day   = array_sum(array_filter(array_column($data, 'e_day')));
$total_year  = array_sum(array_filter(array_column($data, 'e_year')));
$total_total = array_sum(array_filter(array_column($data, 'e_total')));
$all_online  = !in_array(false, array_column($data, 'online'), true);

$ts = new DateTime('now', new DateTimeZone('Europe/Berlin'));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fronius Solar Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0f1117;
  --surface:   #181c27;
  --surface2:  #1e2335;
  --border:    #272f45;
  --sun:       #f5a623;
  --sun-dim:   rgba(245,166,35,.14);
  --green:     #3ecf8e;
  --green-dim: rgba(62,207,142,.14);
  --red:       #ff5f57;
  --red-dim:   rgba(255,95,87,.14);
  --blue:      #6aabff;
  --blue-dim:  rgba(106,171,255,.14);
  --muted:     #55637a;
  --sub:       #8896b0;
  --text:      #d4dcef;
  --font:      'Inter', system-ui, -apple-system, sans-serif;
  --r:         10px;
}

body {
  background: var(--bg);
  color: #d4dcef;
  font-family: var(--font);
  font-size: 14px;
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
  min-height: 100vh;
  padding: 2rem 1.5rem 5rem;
}

.page { max-width: 1300px; margin: 0 auto; }

/* ── Header ──────────────────────────────────────── */
header {
  display: flex; align-items: center;
  justify-content: space-between; gap: 1.5rem;
  margin-bottom: 2rem;
  padding-bottom: 1.25rem;
  border-bottom: 1px solid var(--border);
}
.logo { display: flex; align-items: center; gap: .75rem; }
.logo-sun {
  width: 42px; height: 42px; border-radius: 50%;
  background: var(--sun); display: grid; place-items: center; flex-shrink: 0;
}
.logo-sun svg { width: 22px; height: 22px; fill: #0f1117; }
.logo h1 { font-size: 1.3rem; font-weight: 700; letter-spacing: -.025em; color: #d4dcef; }
.logo h1 em { font-style: normal; color: var(--sun); }
.logo p  { font-size: .75rem; color: var(--muted); margin-top: 1px; }
.hdr-right { text-align: right; }
.hdr-time  { font-size: .85rem; font-weight: 600; color: #d4dcef; }
.hdr-date  { font-size: .72rem; color: var(--muted); }
.pill {
  display: inline-flex; align-items: center; gap: .35rem;
  font-size: .68rem; font-weight: 600;
  padding: .2rem .55rem; border-radius: 99px; margin-top: .35rem;
}
.pill::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
.pill.ok  { background: var(--green-dim); color: var(--green); }
.pill.err { background: var(--red-dim);   color: var(--red); }

/* ── Summary ─────────────────────────────────────── */
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: .875rem; margin-bottom: 1.75rem;
}
.sum-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r); padding: 1.1rem 1.25rem; overflow: hidden; position: relative;
}
.sum-card::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: var(--sun);
}
.sum-card.c-green::after { background: var(--green); }
.sum-card.c-blue::after  { background: var(--blue); }
.sum-label {
  font-size: .68rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .07em; color: var(--muted); margin-bottom: .45rem;
}
.sum-val {
  font-size: 1.7rem; font-weight: 700; color: var(--sun); line-height: 1.1;
}
.sum-val.green { color: var(--green); }
.sum-val.blue  { color: var(--blue); }
.sum-val small { font-size: .5em; font-weight: 500; opacity: .75; margin-left: 2px; }

/* ── WR-Grid ─────────────────────────────────────── */
.wr-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
  gap: 1.25rem;
}
@media (max-width: 560px) {
  .wr-grid { grid-template-columns: 1fr; }
  header   { flex-direction: column; align-items: flex-start; }
  .hdr-right { text-align: left; }
}

.wr-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r); overflow: hidden;
}
.wr-card.offline { border-color: var(--red); }

/* Karten-Header */
.wr-hdr {
  display: flex; align-items: center; justify-content: space-between; gap: .75rem;
  padding: .85rem 1.2rem;
  background: var(--surface2); border-bottom: 1px solid var(--border);
}
.wr-hdr-l { display: flex; align-items: center; gap: .55rem; }
.wr-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: var(--green); }
.wr-dot.off { background: var(--red); }
.wr-name { font-size: .95rem; font-weight: 700; color: #d4dcef; }
.wr-ip   { font-size: .69rem; color: var(--muted); font-family: monospace; letter-spacing: .02em; }
.wr-hdr-r { display: flex; align-items: center; gap: .4rem; flex-shrink: 0; }

.badge {
  font-size: .65rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .06em; padding: .18rem .5rem; border-radius: 99px; white-space: nowrap;
}
.status-active  { background: var(--green-dim); color: var(--green);  border: 1px solid rgba(62,207,142,.25); }
.status-ok      { background: var(--blue-dim);  color: var(--blue);   border: 1px solid rgba(106,171,255,.25); }
.status-warn    { background: var(--sun-dim);   color: var(--sun);    border: 1px solid rgba(245,166,35,.25); }
.status-err     { background: var(--red-dim);   color: var(--red);    border: 1px solid rgba(255,95,87,.25); }
.status-standby { background: rgba(85,99,122,.12); color: var(--sub); border: 1px solid rgba(85,99,122,.2); }

/* Leistungsstreifen */
.power-strip {
  display: grid; grid-template-columns: repeat(3,1fr);
  border-bottom: 1px solid var(--border);
}
.pwr-block { padding: 1.1rem .85rem; text-align: center; border-right: 1px solid var(--border); }
.pwr-block:last-child { border-right: none; }
.pwr-val { font-size: 1.8rem; font-weight: 700; color: var(--sun); line-height: 1.1; }
.pwr-val.blue { color: var(--blue); }
.pwr-val small { font-size: .45em; font-weight: 500; opacity: .8; }
.pwr-lbl {
  font-size: .64rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .07em; color: var(--muted); margin-top: .3rem;
}

/* Metriken */
.metrics {
  display: grid; grid-template-columns: repeat(3,1fr);
  border-bottom: 1px solid var(--border);
}
.metric {
  padding: .8rem 1rem;
  border-right: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}
.metrics .metric:nth-child(3n)    { border-right: none; }
.metrics .metric:nth-last-child(1),
.metrics .metric:nth-last-child(2),
.metrics .metric:nth-last-child(3) { border-bottom: none; }
.m-lbl {
  font-size: .66rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .07em; color: var(--muted); margin-bottom: .28rem;
}
.m-val { font-size: .92rem; font-weight: 600; color: #d4dcef; }
.m-val small { color: var(--sub); font-weight: 400; }
.m-val.green { color: var(--green); }
.m-val.red   { color: var(--red); }

/* Abschnitts-Titel */
.sec-title {
  padding: .55rem 1.1rem;
  font-size: .67rem; font-weight: 700; text-transform: uppercase; letter-spacing: .09em;
  color: var(--muted);
  background: var(--surface2); border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: .45rem;
}
.sec-title::before {
  content: ''; display: inline-block; width: 3px; height: 11px;
  background: var(--sun); border-radius: 2px; flex-shrink: 0;
}
.sec-title.green::before { background: var(--green); }

/* MPPT-Grid */
.mppt-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr));
  border-bottom: 1px solid var(--border);
}
.mppt-block {
  padding: .9rem 1.1rem; border-right: 1px solid var(--border);
}
.mppt-block:last-child { border-right: none; }
.mppt-name {
  font-size: .7rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--sun); margin-bottom: .6rem;
}
.mppt-row {
  display: flex; justify-content: space-between; align-items: baseline;
  margin-bottom: .15rem; font-size: .81rem;
}
.mppt-row .mk { color: var(--sub); font-size: .73rem; }
.mppt-row .mv { font-weight: 600; color: #d4dcef; }
.mppt-bar-w { height: 3px; background: var(--border); border-radius: 2px; margin: .45rem 0 .35rem; overflow: hidden; }
.mppt-bar   { height: 100%; border-radius: 2px; background: linear-gradient(90deg, var(--green), var(--sun)); }
.mppt-pwr   { font-size: 1rem; font-weight: 700; color: var(--green); }
.mppt-pwr small { font-size: .6em; font-weight: 500; color: var(--sub); }

/* Phasen */
.phases-grid {
  display: grid; grid-template-columns: repeat(3,1fr);
  border-bottom: 1px solid var(--border);
}
.phase-col { padding: .85rem 1rem; border-right: 1px solid var(--border); }
.phase-col:last-child { border-right: none; }
.phase-name { font-size: .72rem; font-weight: 700; color: var(--sun); margin-bottom: .45rem; }
.phase-item { display: flex; justify-content: space-between; font-size: .8rem; margin-bottom: .12rem; }
.phase-item .pk { color: var(--sub); }
.phase-item .pv { font-weight: 600; }

/* Energie */
.energy-strip {
  display: grid; grid-template-columns: repeat(3,1fr);
  border-bottom: 1px solid var(--border);
}
.e-block { padding: .85rem 1rem; text-align: center; border-right: 1px solid var(--border); }
.e-block:last-child { border-right: none; }
.e-lbl {
  font-size: .64rem; font-weight: 600; text-transform: uppercase;
  letter-spacing: .07em; color: var(--muted); margin-bottom: .35rem;
}
.e-val { font-size: 1.1rem; font-weight: 700; color: var(--green); }
.e-val small { font-size: .62em; font-weight: 500; }

/* Geräteinfo */
.dev-info {
  padding: .6rem 1.1rem; display: flex; flex-wrap: wrap; gap: .25rem 1.5rem;
  border-bottom: 1px solid var(--border);
}
.di { font-size: .71rem; color: var(--sub); }
.di strong { color: #d4dcef; font-weight: 600; }

/* Offline */
.offline-banner { padding: 2.5rem; text-align: center; }
.offline-banner .oi { font-size: 2rem; }
.offline-banner .ot { font-size: .9rem; font-weight: 600; color: var(--red); margin-top: .5rem; }
.offline-banner .os { font-size: .76rem; color: var(--muted); margin-top: .35rem; }

/* Debug */
details.debug {
  margin-top: 1.75rem; background: var(--surface);
  border: 1px solid var(--border); border-radius: var(--r); overflow: hidden;
}
details.debug summary {
  padding: .65rem 1.2rem;
  font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .08em;
  color: var(--muted); background: var(--surface2); cursor: pointer; user-select: none;
  list-style: none; display: flex; align-items: center; gap: .4rem;
}
details.debug summary::-webkit-details-marker { display: none; }
details.debug summary::after { content: ' ▶'; font-size: .6em; opacity: .6; }
details.debug[open] summary::after { content: ' ▼'; }
details.debug summary:hover { color: #d4dcef; }
.debug-body { padding: 1rem 1.2rem; overflow-x: auto; }
.debug-body pre { font-size: .67rem; line-height: 1.6; color: var(--sub); white-space: pre-wrap; word-break: break-all; }
.debug-wr { font-size: .74rem; font-weight: 700; color: var(--sun); margin: .75rem 0 .25rem; }

/* Footer */
.footer { text-align: center; margin-top: 1.75rem; font-size: .71rem; color: var(--muted); }
.footer a { color: var(--sun); text-decoration: none; }
.footer a:hover { text-decoration: underline; }

/* Refresh-Balken */
.refresh-bar { position: fixed; bottom: 0; left: 0; right: 0; height: 2px; background: var(--border); }
.refresh-fill { height: 100%; background: var(--sun); animation: shrink 60s linear forwards; transform-origin: left; }
@keyframes shrink { to { transform: scaleX(0); } }

.na { color: var(--muted); }
</style>
</head>
<body>
<div class="page">

<!-- Header -->
<header>
  <div class="logo">
    <div class="logo-sun">
      <svg viewBox="0 0 24 24"><path d="M12 2.25a.75.75 0 01.75.75v2.25a.75.75 0 01-1.5 0V3a.75.75 0 01.75-.75zM7.5 12a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0zM18.894 6.166a.75.75 0 00-1.06-1.06l-1.591 1.59a.75.75 0 101.06 1.061l1.591-1.59zM21.75 12a.75.75 0 01-.75.75h-2.25a.75.75 0 010-1.5H21a.75.75 0 01.75.75zM17.834 18.894a.75.75 0 001.06-1.06l-1.59-1.591a.75.75 0 10-1.061 1.06l1.59 1.591zM12 18a.75.75 0 01.75.75V21a.75.75 0 01-1.5 0v-2.25A.75.75 0 0112 18zM7.166 17.834a.75.75 0 00-1.06 1.06l1.59 1.591a.75.75 0 001.061-1.06l-1.59-1.591zM6 12a.75.75 0 01-.75.75H3a.75.75 0 010-1.5h2.25A.75.75 0 016 12zM6.166 6.166a.75.75 0 001.06 1.06l-1.59-1.59a.75.75 0 00-1.061 1.06l1.59 1.59z"/></svg>
    </div>
    <div>
      <h1>Fronius <em>Solar</em></h1>
      <p><?= count($inverters) ?> Wechselrichter · Solar API v1</p>
    </div>
  </div>
  <div class="hdr-right">
    <div class="hdr-time"><?= $ts->format('H:i:s') ?> Uhr</div>
    <div class="hdr-date"><?= $ts->format('d.m.Y') ?></div>
    <div class="pill <?= $all_online ? 'ok' : 'err' ?>">
      <?= $all_online ? 'Alle Geräte online' : 'Verbindungsprobleme' ?>
    </div>
  </div>
</header>

<!-- Summary -->
<div class="summary">
  <div class="sum-card">
    <div class="sum-label">Gesamtleistung AC</div>
    <div class="sum-val"><?= fmt($total_pac > 0 ? $total_pac / 1000 : null, 2) ?> <small>kW</small></div>
  </div>
  <div class="sum-card c-green">
    <div class="sum-label">Ertrag heute</div>
    <div class="sum-val green"><?= fmt_energy($total_day ?: null) ?></div>
  </div>
  <div class="sum-card c-green">
    <div class="sum-label">Ertrag dieses Jahr</div>
    <div class="sum-val green"><?= fmt_energy($total_year ?: null) ?></div>
  </div>
  <div class="sum-card c-blue">
    <div class="sum-label">Gesamtertrag</div>
    <div class="sum-val blue"><?= fmt_energy($total_total ?: null) ?></div>
  </div>
</div>

<!-- Wechselrichter-Karten -->
<div class="wr-grid">
<?php foreach ($data as $id => $d):
  [$slabel, $sclass] = ($d['status'] >= 0)
      ? status_label($d['status'])
      : ['Nicht erreichbar', 'status-err'];
  $pac_kw = $d['pac'] !== null ? $d['pac'] / 1000 : null;
  $has_ph = ($d['uac_l1'] !== null || $d['iac_l1'] !== null);
?>
<div class="wr-card <?= $d['online'] ? '' : 'offline' ?>">

  <div class="wr-hdr">
    <div class="wr-hdr-l">
      <span class="wr-dot <?= $d['online'] ? '' : 'off' ?>"></span>
      <span class="wr-name"><?= htmlspecialchars($d['cfg']['name']) ?></span>
      <span class="wr-ip"><?= htmlspecialchars($d['cfg']['ip']) ?></span>
    </div>
    <div class="wr-hdr-r">
      <?php if ($d['error'] > 0): ?>
        <span class="badge status-err">E<?= $d['error'] ?></span>
      <?php endif ?>
      <span class="badge <?= $sclass ?>"><?= $slabel ?></span>
    </div>
  </div>

  <?php if (!$d['online']): ?>
  <div class="offline-banner">
    <div class="oi">⚡</div>
    <div class="ot">Gerät nicht erreichbar</div>
    <div class="os">Keine Antwort von <?= htmlspecialchars($d['cfg']['ip']) ?> · Timeout: <?= $api_timeout ?> s</div>
  </div>
  <?php else: ?>

  <!-- Leistung (groß) -->
  <div class="power-strip">
    <div class="pwr-block">
      <div class="pwr-val"><?= fmt($pac_kw, 2) ?> <small>kW</small></div>
      <div class="pwr-lbl">AC-Leistung</div>
    </div>
    <div class="pwr-block">
      <div class="pwr-val blue"><?= fmt($d['udc'], 0) ?> <small>V DC</small></div>
      <div class="pwr-lbl">DC-Spannung ges.</div>
    </div>
    <div class="pwr-block">
      <div class="pwr-val blue"><?= fmt($d['idc'], 2) ?> <small>A DC</small></div>
      <div class="pwr-lbl">DC-Strom ges.</div>
    </div>
  </div>

  <!-- AC-Metriken -->
  <div class="metrics">
    <div class="metric">
      <div class="m-lbl">AC-Spannung</div>
      <div class="m-val"><?= fmt($d['uac'], 1, 'V') ?></div>
    </div>
    <div class="metric">
      <div class="m-lbl">AC-Strom</div>
      <div class="m-val"><?= fmt($d['iac'], 2, 'A') ?></div>
    </div>
    <div class="metric">
      <div class="m-lbl">Frequenz</div>
      <div class="m-val"><?= fmt($d['fac'], 2, 'Hz') ?></div>
    </div>
    <?php if ($d['p_grid'] !== null): ?>
    <div class="metric">
      <div class="m-lbl">Einspeisung</div>
      <div class="m-val <?= $d['p_grid'] < 0 ? 'red' : 'green' ?>"><?= fmt($d['p_grid'] / 1000, 2, 'kW') ?></div>
    </div>
    <?php endif ?>
    <?php if ($d['p_load'] !== null): ?>
    <div class="metric">
      <div class="m-lbl">Hausverbrauch</div>
      <div class="m-val"><?= fmt(abs($d['p_load']) / 1000, 2, 'kW') ?></div>
    </div>
    <?php endif ?>
    <?php if ($d['soc'] !== null): ?>
    <div class="metric">
      <div class="m-lbl">Speicher SOC</div>
      <div class="m-val green"><?= fmt($d['soc'], 0, '%') ?></div>
    </div>
    <?php endif ?>
  </div>

  <!-- DC-Eingang / MPPT -->
  <?php
    $has_mppt = !empty($d['mppt']);
    $max_pdc  = $has_mppt ? max(array_column($d['mppt'], 'pdc') + [1]) : 1;
  ?>
  <div class="sec-title">
    DC-Eingang<?= $has_mppt ? ' / MPPT (Modbus)' : '' ?>
    <?php if ($d['mb_enabled'] && $has_mppt): ?>
      <span style="font-size:.6rem;font-weight:500;color:var(--green);margin-left:.5rem">● SunSpec Model 160</span>
    <?php elseif ($d['mb_enabled']): ?>
      <span style="font-size:.6rem;font-weight:500;color:var(--red);margin-left:.5rem">✗ Modbus Fehler</span>
    <?php endif ?>
  </div>

  <div class="mppt-grid">

    <?php if ($has_mppt): ?>
      <?php foreach ($d['mppt'] as $si => $m):
        $pct = ($m['pdc'] && $max_pdc > 0) ? min(100, round($m['pdc'] / $max_pdc * 100)) : 0;
      ?>
      <div class="mppt-block">
        <div class="mppt-name"><?= htmlspecialchars($m['label']) ?></div>
        <div class="mppt-row">
          <span class="mk">Spannung</span>
          <span class="mv"><?= $m['udc'] !== null ? number_format($m['udc'], 1, ',', '.') . ' V' : '—' ?></span>
        </div>
        <div class="mppt-row">
          <span class="mk">Strom</span>
          <span class="mv"><?= $m['idc'] !== null ? number_format($m['idc'], 2, ',', '.') . ' A' : '—' ?></span>
        </div>
        <div class="mppt-bar-w"><div class="mppt-bar" style="width:<?= $pct ?>%"></div></div>
        <div class="mppt-pwr"><?= $m['pdc'] !== null ? number_format($m['pdc'], 0, ',', '.') . ' <small>W</small>' : '—' ?></div>
      </div>
      <?php endforeach ?>

    <?php else: ?>
      <!-- Fallback: DC-Gesamtwert aus CommonInverterData -->
      <div class="mppt-block">
        <div class="mppt-name">DC gesamt</div>
        <div class="mppt-row">
          <span class="mk">Spannung</span>
          <span class="mv"><?= $d['udc'] !== null ? number_format($d['udc'], 1, ',', '.') . ' V' : '—' ?></span>
        </div>
        <div class="mppt-row">
          <span class="mk">Strom</span>
          <span class="mv"><?= $d['idc'] !== null ? number_format($d['idc'], 2, ',', '.') . ' A' : '—' ?></span>
        </div>
        <div class="mppt-bar-w"><div class="mppt-bar" style="width:100%"></div></div>
        <div class="mppt-pwr"><?= $d['pdc'] !== null ? number_format($d['pdc'], 0, ',', '.') . ' <small>W</small>' : '—' ?></div>
      </div>
    <?php endif ?>

    <!-- Tages-Min/Max (immer sichtbar) -->
    <?php if ($d['udc_max'] !== null || $d['pac_max'] !== null): ?>
    <div class="mppt-block">
      <div class="mppt-name" style="color:var(--blue)">Tages-Maximum</div>
      <?php if ($d['pac_max'] !== null): ?>
      <div class="mppt-row">
        <span class="mk">PAC max</span>
        <span class="mv" style="color:var(--sun)"><?= number_format($d['pac_max'], 0, ',', '.') ?> W</span>
      </div>
      <?php endif ?>
      <?php if ($d['udc_max'] !== null): ?>
      <div class="mppt-row">
        <span class="mk">UDC max</span>
        <span class="mv"><?= number_format($d['udc_max'], 1, ',', '.') ?> V</span>
      </div>
      <div class="mppt-row">
        <span class="mk">UDC min</span>
        <span class="mv"><?= $d['udc_min'] !== null ? number_format($d['udc_min'], 1, ',', '.') . ' V' : '—' ?></span>
      </div>
      <?php endif ?>
    </div>
    <?php endif ?>

    <!-- Modbus-Statusblock -->
    <?php if (!$d['mb_enabled']): ?>
    <div class="mppt-block" style="opacity:.55">
      <div class="mppt-name" style="color:var(--muted)">Per-MPPT</div>
      <div style="font-size:.71rem;color:var(--muted);line-height:1.65;margin-top:.25rem">
        In <code style="font-size:.85em">config.php</code> aktivieren:<br>
        <code style="font-size:.8em;color:var(--sub)">'modbus' =&gt; ['enabled' =&gt; true]</code>
      </div>
    </div>
    <?php elseif (!$has_mppt && $d['mb_status']): ?>
    <div class="mppt-block">
      <div class="mppt-name" style="color:var(--red)">Modbus Fehler</div>
      <div style="font-size:.71rem;color:var(--muted);line-height:1.65;margin-top:.25rem">
        <?= htmlspecialchars($d['mb_status']) ?>
      </div>
    </div>
    <?php endif ?>

  </div>

  <?php if ($d['mb_enabled'] && $has_mppt): ?>
  <div style="padding:.45rem 1.1rem;font-size:.68rem;color:var(--muted);background:var(--surface2);border-bottom:1px solid var(--border)">
    <?= htmlspecialchars($d['mb_status']) ?>
    · Gefundene SunSpec-Modelle: <?= implode(', ', array_keys($d['mb_models'])) ?>
  </div>
  <?php endif ?>

  <!-- 3-Phasen -->
  <?php if ($has_ph): ?>
  <div class="sec-title green">Dreiphasige AC-Daten</div>
  <div class="phases-grid">
    <?php foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $ln => $n): ?>
    <div class="phase-col">
      <div class="phase-name"><?= $ln ?></div>
      <div class="phase-item">
        <span class="pk">Spannung</span>
        <span class="pv"><?= $d["uac_l{$n}"] !== null ? number_format($d["uac_l{$n}"], 0, ',', '.') . ' V' : '—' ?></span>
      </div>
      <div class="phase-item">
        <span class="pk">Strom</span>
        <span class="pv"><?= $d["iac_l{$n}"] !== null ? number_format($d["iac_l{$n}"], 2, ',', '.') . ' A' : '—' ?></span>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <!-- Energie -->
  <div class="energy-strip">
    <div class="e-block">
      <div class="e-lbl">Heute</div>
      <div class="e-val"><?= fmt_energy($d['e_day']) ?></div>
    </div>
    <div class="e-block">
      <div class="e-lbl">Dieses Jahr</div>
      <div class="e-val"><?= fmt_energy($d['e_year']) ?></div>
    </div>
    <div class="e-block">
      <div class="e-lbl">Gesamt</div>
      <div class="e-val"><?= fmt_energy($d['e_total']) ?></div>
    </div>
  </div>

  <!-- Geräteinfo -->
  <?php if ($d['product'] || $d['serial'] || $d['sw']): ?>
  <div class="dev-info">
    <?php if ($d['product']): ?><span class="di">Gerät: <strong><?= htmlspecialchars($d['product']) ?></strong></span><?php endif ?>
    <?php if ($d['serial']): ?><span class="di">S/N: <strong><?= htmlspecialchars($d['serial']) ?></strong></span><?php endif ?>
    <?php if ($d['sw']): ?>    <span class="di">SW: <strong><?= htmlspecialchars($d['sw']) ?></strong></span><?php endif ?>
    <?php if ($d['hw']): ?>    <span class="di">HW: <strong><?= htmlspecialchars($d['hw']) ?></strong></span><?php endif ?>
  </div>
  <?php endif ?>

  <?php endif // online ?>
</div>
<?php endforeach ?>
</div><!-- /.wr-grid -->

<!-- Debug -->
<details class="debug">
  <summary>Rohdaten / API-Debug</summary>
  <div class="debug-body">
    <?php foreach ($data as $id => $d): ?>
    <div class="debug-wr"><?= htmlspecialchars($d['cfg']['name']) ?> — <?= htmlspecialchars($d['cfg']['ip']) ?></div>
    <pre><?= htmlspecialchars(json_encode($d['_raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php endforeach ?>
  </div>
</details>

<div class="footer">
  Auto-Refresh in 60 s &nbsp;·&nbsp;
  <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/') ?>">Jetzt neu laden</a>
</div>
</div>

<div class="refresh-bar"><div class="refresh-fill"></div></div>
<script>setTimeout(() => location.reload(), 60_000);</script>
</body>
</html>
