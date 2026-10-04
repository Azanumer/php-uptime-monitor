#!/usr/bin/env php
<?php
/**
 * php-uptime-monitor — dependency-free website uptime monitor.
 *
 * Checks every site in config.json, alerts (email + Slack/Discord webhook)
 * ONLY when a site's status changes (up -> down or down -> up), and logs
 * every check. Designed to run from cron every 1–5 minutes:
 *
 *   */5 * * * * /usr/bin/php /opt/uptime-monitor/monitor.php >> /dev/null 2>&1
 *
 * Requires: PHP 7.2+ with the JSON extension (bundled by default).
 * No Composer packages, no curl extension needed.
 */
declare(strict_types=1);

define('BASE_DIR', __DIR__);

// ---------------------------------------------------------------- config ---

$configFile = BASE_DIR . '/config.json';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Missing config.json — copy config.json.example to config.json first.\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($configFile), true);
if (!is_array($config) || empty($config['sites']) || !is_array($config['sites'])) {
    fwrite(STDERR, "config.json is invalid: needs a non-empty \"sites\" array.\n");
    exit(1);
}

$timeout    = isset($config['check_timeout']) ? (int) $config['check_timeout'] : 15;
$stateFile  = BASE_DIR . '/' . (isset($config['state_file']) ? $config['state_file'] : 'state.json');
$logFile    = BASE_DIR . '/' . (isset($config['log_file']) ? $config['log_file'] : 'monitor.log');
$alertEmail = isset($config['alert_email']) ? trim((string) $config['alert_email']) : '';
$webhook    = isset($config['slack_webhook']) ? trim((string) $config['slack_webhook']) : '';

// ---------------------------------------------------------------- helpers ---

/** Load previous states: [site_key => 'up'|'down']. */
function loadState($stateFile)
{
    if (!file_exists($stateFile)) {
        return array();
    }
    $data = json_decode((string) file_get_contents($stateFile), true);
    return is_array($data) ? $data : array();
}

function saveState($stateFile, array $state)
{
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
}

function logLine($logFile, $message)
{
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Check one URL. Returns ['ok' => bool, 'code' => int, 'ms' => int, 'error' => string].
 * A site counts as UP when it answers with a 2xx/3xx code and (optionally)
 * the expected keyword is found in the body.
 */
function checkSite($url, $keyword, $timeout)
{
    $ctx = stream_context_create(array(
        'http' => array(
            'method'        => 'GET',
            'timeout'       => $timeout,
            'ignore_errors' => true, // still give us the body on 4xx/5xx
            'user_agent'    => 'php-uptime-monitor/1.0',
            'max_redirects' => 5,
        ),
        'ssl' => array(
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ),
    ));

    $start = microtime(true);
    $body  = @file_get_contents($url, false, $ctx);
    $ms    = (int) round((microtime(true) - $start) * 1000);

    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m)) {
                $code = (int) $m[1];
                break;
            }
        }
    }

    if ($body === false) {
        return array('ok' => false, 'code' => $code, 'ms' => $ms, 'error' => 'connection failed / timed out');
    }
    if ($code < 200 || $code >= 400) {
        return array('ok' => false, 'code' => $code, 'ms' => $ms, 'error' => 'bad HTTP status');
    }
    if ($keyword !== '' && stripos($body, $keyword) === false) {
        return array('ok' => false, 'code' => $code, 'ms' => $ms, 'error' => 'keyword not found in response');
    }
    return array('ok' => true, 'code' => $code, 'ms' => $ms, 'error' => '');
}

/** Send one alert through every configured channel. */
function sendAlert($subject, $message, $alertEmail, $webhook, $logFile)
{
    if ($alertEmail !== '') {
        $headers = "From: uptime-monitor <uptime@" . gethostname() . ">\r\n";
        $sent = @mail($alertEmail, $subject, $message, $headers);
        logLine($logFile, 'email alert to ' . $alertEmail . ': ' . ($sent ? 'sent' : 'FAILED'));
    }
    if ($webhook !== '') {
        $payload = json_encode(array('text' => '*' . $subject . "*\n" . $message));
        $ctx = stream_context_create(array(
            'http' => array(
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
            ),
        ));
        $res = @file_get_contents($webhook, false, $ctx);
        logLine($logFile, 'webhook alert: ' . ($res === false ? 'FAILED' : 'sent'));
    }
}

// ------------------------------------------------------------------- main ---

$state   = loadState($stateFile);
$changed = false;

foreach ($config['sites'] as $site) {
    if (empty($site['url'])) {
        continue;
    }
    $name    = isset($site['name']) ? (string) $site['name'] : $site['url'];
    $url     = (string) $site['url'];
    $keyword = isset($site['keyword']) ? (string) $site['keyword'] : '';
    $key     = md5($url);

    $result    = checkSite($url, $keyword, $timeout);
    $newStatus = $result['ok'] ? 'up' : 'down';
    $oldStatus = isset($state[$key]) ? $state[$key] : null;

    logLine(
        $logFile,
        sprintf('%s (%s): %s — HTTP %d, %d ms%s', $name, $url, $newStatus,
            $result['code'], $result['ms'],
            $result['error'] !== '' ? ' (' . $result['error'] . ')' : '')
    );

    if ($oldStatus === null) {
        // First run: record state silently, don't alert.
        $state[$key] = $newStatus;
        $changed = true;
        continue;
    }

    if ($newStatus !== $oldStatus) {
        $state[$key] = $newStatus;
        $changed = true;

        if ($newStatus === 'down') {
            $subject = 'DOWN: ' . $name;
            $message = "Site is DOWN.\nURL: {$url}\nHTTP: {$result['code']}\n"
                     . "Error: {$result['error']}\nResponse time: {$result['ms']} ms";
        } else {
            $subject = 'RECOVERED: ' . $name;
            $message = "Site is back UP.\nURL: {$url}\nHTTP: {$result['code']}\n"
                     . "Response time: {$result['ms']} ms";
        }
        logLine($logFile, 'STATUS CHANGE: ' . $subject);
        sendAlert($subject, $message, $alertEmail, $webhook, $logFile);
    }
}

if ($changed) {
    saveState($stateFile, $state);
}

echo "Checked " . count($config['sites']) . " site(s).\n";
