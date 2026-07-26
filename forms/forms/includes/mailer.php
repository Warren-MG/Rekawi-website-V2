<?php
/**
 * Rekawi Company Limited — shared mail helpers
 * Loaded by send-mail.php and send-quote.php.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/../phpmailer/Exception.php';
require_once __DIR__ . '/../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/SMTP.php';

/* ---------------------------------------------------------------------
   mbstring fallbacks — some shared hosts ship without the extension.
   --------------------------------------------------------------------- */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        return $length === null ? substr((string) $s, $start) : substr((string) $s, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen((string) $s); }
}

/* ---------------------------------------------------------------------
   Config
   --------------------------------------------------------------------- */
function rk_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $local  = __DIR__ . '/config.php';
    $sample = __DIR__ . '/config.sample.php';
    $cfg = is_readable($local) ? require $local : (is_readable($sample) ? require $sample : []);
    return $cfg = is_array($cfg) ? $cfg : [];
}

/* ---------------------------------------------------------------------
   JSON response — always exits
   --------------------------------------------------------------------- */
function rk_json(string $status, string $message, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function rk_log(string $line): void
{
    $cfg  = rk_config();
    $file = $cfg['log_file'] ?? (__DIR__ . '/mail-error.log');
    @error_log('[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $line . PHP_EOL, 3, $file);
}

/* ---------------------------------------------------------------------
   Input helpers
   --------------------------------------------------------------------- */
function rk_field(string $key, int $max = 2000): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = str_replace(["\r\n", "\r"], "\n", trim($v));
    $v = strip_tags($v);
    // Strip control chars except newline/tab
    $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    $v = $stripped ?? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';

    if (function_exists('mb_substr') && extension_loaded('mbstring')) {
        return mb_substr($v, 0, $max, 'UTF-8');
    }
    // Truncate without splitting a multi-byte sequence
    if (strlen($v) <= $max) {
        return $v;
    }
    $cut = substr($v, 0, $max);
    while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
        $cut = substr($cut, 0, -1);
    }
    return $cut;
}

/** Reject header-injection attempts in single-line fields. */
function rk_clean_line(string $v): string
{
    return trim(preg_replace('/[\n\t]+/', ' ', $v) ?? '');
}

/**
 * Safe display name for the From header.
 * The value comes from a public form, so everything outside a small
 * allow-list is stripped — a mail header is not a place for free text.
 */
function rk_from_name(string $name, string $fallback = 'Website enquiry'): string
{
    $clean = preg_replace('/[^\p{L}\p{N} \.\'\-]/u', '', $name);
    if ($clean === null) {
        $clean = preg_replace('/[^A-Za-z0-9 \.\'\-]/', '', $name) ?? '';
    }
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    if ($clean === '') {
        $clean = $fallback;
    }
    return function_exists('mb_substr') ? mb_substr($clean, 0, 60) : substr($clean, 0, 60);
}

function rk_valid_email(string $e): bool
{
    return (bool) filter_var($e, FILTER_VALIDATE_EMAIL) && mb_strlen($e) <= 254;
}

function rk_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string) $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/* ---------------------------------------------------------------------
   Guards: method, honeypot, timing, rate limit
   --------------------------------------------------------------------- */
function rk_guard(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        rk_json('error', 'This endpoint only accepts form submissions.', 405);
    }

    // Honeypot — bots fill hidden fields, humans never see them.
    if (rk_field('website') !== '' || rk_field('company_url') !== '') {
        rk_json('success', 'Thank you. Your message has been received.');
    }

    // Submitted impossibly fast => almost certainly a bot.
    $ts = (int) ($_POST['ts'] ?? 0);
    if ($ts > 0 && (time() - (int) floor($ts / 1000)) < 2) {
        rk_json('success', 'Thank you. Your message has been received.');
    }

    rk_rate_limit();
}

function rk_rate_limit(): void
{
    $cfg = rk_config();
    $max = (int) ($cfg['rate_limit'] ?? 0);
    if ($max <= 0) {
        return;
    }
    $file = sys_get_temp_dir() . '/rk_rl_' . md5(rk_client_ip()) . '.json';
    $now  = time();
    $hits = [];
    if (is_readable($file)) {
        $raw  = json_decode((string) @file_get_contents($file), true);
        $hits = is_array($raw) ? $raw : [];
    }
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && ($now - $t) < 3600));
    if (count($hits) >= $max) {
        rk_json('error', 'You have sent several messages recently. Please try again later, or call +254 740 685581.', 429);
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
}

/* ---------------------------------------------------------------------
   Transport
   --------------------------------------------------------------------- */
function rk_mailer(): PHPMailer
{
    $cfg  = rk_config();
    $mail = new PHPMailer(true);

    $pass = (string) ($cfg['smtp_pass'] ?? '');
    if ($pass === '') {
        rk_log('SMTP password is empty — set smtp_pass in includes/config.php');
        rk_json('error', 'Email is not configured on the server yet. Please email info@rekawi.com directly.', 500);
    }

    $mail->isSMTP();
    $mail->Host       = (string) ($cfg['smtp_host'] ?? '');
    $mail->SMTPAuth   = true;
    $mail->Username   = (string) ($cfg['smtp_user'] ?? '');
    $mail->Password   = $pass;
    $mail->Port       = (int) ($cfg['smtp_port'] ?? 587);
    $mail->CharSet    = 'UTF-8';
    $mail->Encoding   = 'base64';
    $mail->Timeout    = 15;
    $mail->SMTPKeepAlive = true;

    $secure = strtolower((string) ($cfg['smtp_secure'] ?? 'tls'));
    if ($secure === 'none') {
        $mail->SMTPSecure = false;
        $mail->SMTPAutoTLS = false;
    } else {
        $mail->SMTPSecure = $secure === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
    }

    $debug = (int) ($cfg['smtp_debug'] ?? 0);
    if ($debug > 0) {
        $mail->SMTPDebug  = $debug;
        $mail->Debugoutput = static fn($str) => rk_log('SMTP: ' . trim((string) $str));
    }

    // Send AS the domain mailbox. Using the visitor's address here breaks SPF.
    $mail->setFrom((string) ($cfg['from_email'] ?? ''), (string) ($cfg['from_name'] ?? 'Rekawi'));

    return $mail;
}

/* ---------------------------------------------------------------------
   Branded HTML email shell
   --------------------------------------------------------------------- */
function rk_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rk_email_shell(string $preheader, string $heading, string $intro, string $inner, string $footNote = '', string $eyebrow = ''): string
{
    $cfg   = rk_config();
    $name  = rk_esc((string) ($cfg['company_name'] ?? 'Rekawi Company Limited'));
    $site  = rk_esc((string) ($cfg['site_url'] ?? 'https://rekawi.com'));
    $logo  = rk_esc((string) ($cfg['logo_url'] ?? ''));
    $phone = rk_esc((string) ($cfg['company_phone'] ?? ''));
    $addr  = rk_esc((string) ($cfg['company_address'] ?? ''));
    $year  = date('Y');

    /* Site palette — matches assets/css/app.css tokens */
    $GREEN900 = '#04301F';
    $GREEN800 = '#06402B';
    $GREEN700 = '#0A5537';
    $CLAY500  = '#D14B39';
    $CLAY400  = '#E86B55';
    $INK      = '#12201A';
    $INK70    = '#435049';
    $INK50    = '#6C7A73';
    $LINE     = '#DFE6E1';
    $PAPER    = '#FBFAF7';
    $PAPER2   = '#F2F1EB';

    $logoBlock = $logo !== ''
        ? '<img src="' . $logo . '" width="124" alt="' . $name . '" style="display:block;border:0;outline:none;text-decoration:none;height:auto;border-radius:6px">'
        : '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;color:#ffffff;font-weight:bold;letter-spacing:-.4px">' . $name . '</span>';

    $eyebrowHtml = $eyebrow !== ''
        ? '<p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;letter-spacing:2.6px;text-transform:uppercase;color:' . $CLAY400 . '">' . $eyebrow . '</p>'
        : '';

    $footNoteHtml = $footNote !== ''
        ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px">'
          . '<tr><td style="background:' . $PAPER2 . ';border-left:3px solid ' . $CLAY500 . ';border-radius:0 8px 8px 0;padding:13px 16px;'
          . 'font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:' . $INK70 . '">' . $footNote . '</td></tr></table>'
        : '';

    return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light only" />
<meta name="supported-color-schemes" content="light only" />
<title>{$heading}</title>
<!--[if mso]><style type="text/css">body,table,td{font-family:Arial,Helvetica,sans-serif !important}</style><![endif]-->
<style type="text/css">
  body{margin:0;padding:0;width:100%!important;background:{$PAPER};-webkit-font-smoothing:antialiased}
  table{border-collapse:collapse}
  img{border:0;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}
  a{color:{$CLAY500}}
  @media only screen and (max-width:620px){
    .wrap{width:100%!important}
    .pad{padding-left:22px!important;padding-right:22px!important}
    .stack{display:block!important;width:100%!important;padding:0 0 14px 0!important}
    h1{font-size:21px!important}
  }
</style>
</head>
<body style="margin:0;padding:0;background:{$PAPER}">
<div style="display:none;font-size:1px;color:{$PAPER};line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden">{$preheader}&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;&#8203;</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{$PAPER}">
<tr><td align="center" style="padding:26px 14px">

  <table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px">

    <!-- Brand strip -->
    <tr><td style="background:{$GREEN900};padding:22px 30px;border-radius:14px 14px 0 0">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
        <td align="left" valign="middle">{$logoBlock}</td>
        <td align="right" valign="middle" style="font-family:Arial,Helvetica,sans-serif;font-size:10px;letter-spacing:2.2px;text-transform:uppercase;color:rgba(255,255,255,.55)">Engineering</td>
      </tr></table>
    </td></tr>

    <!-- Title bar: mirrors the modal header on the site -->
    <tr><td class="pad" style="background:{$GREEN800};padding:26px 30px">
      {$eyebrowHtml}
      <h1 style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:25px;line-height:1.2;color:#ffffff;font-weight:normal;letter-spacing:-.5px">{$heading}</h1>
    </td></tr>
    <tr><td style="height:3px;background:{$CLAY500};font-size:0;line-height:0">&nbsp;</td></tr>

    <!-- Body -->
    <tr><td class="pad" style="background:#ffffff;padding:30px">
      <p style="margin:0 0 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:{$INK70}">{$intro}</p>
      {$inner}
    </td></tr>

    <!-- Footer -->
    <tr><td class="pad" style="background:#ffffff;padding:0 30px 28px;border-radius:0 0 14px 14px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr><td style="border-top:1px solid {$LINE};height:22px;font-size:0;line-height:0">&nbsp;</td></tr>
      </table>
      {$footNoteHtml}
      <p style="margin:0 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:{$INK}"><strong>{$name}</strong></p>
      <p style="margin:0 0 3px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:{$INK70}">{$addr}</p>
      <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:{$INK70}">{$phone} &nbsp;&middot;&nbsp; <a href="{$site}" style="color:{$CLAY500};text-decoration:none;font-weight:bold">rekawi.com</a></p>
    </td></tr>

    <tr><td align="center" style="padding:18px 20px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.6;color:#8A968F">
      &copy; {$year} {$name}. All rights reserved.
    </td></tr>

  </table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Label/value table for the internal notification.
 * Styled to echo the .qmeta panels used in the site's modals.
 */
function rk_rows(array $rows): string
{
    $LINE   = '#DFE6E1';
    $PAPER2 = '#F2F1EB';
    $INK50  = '#6C7A73';
    $GREEN7 = '#0A5537';

    $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
         . 'style="background:' . $PAPER2 . ';border-radius:10px;overflow:hidden">';

    $items = [];
    foreach ($rows as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $items[] = [$label, (string) $value];
    }

    $last = count($items) - 1;
    foreach ($items as $i => [$label, $value]) {
        $border = $i === $last ? '' : 'border-bottom:1px solid rgba(6,64,43,.09);';
        $out .= '<tr>'
            . '<td width="150" valign="top" style="padding:13px 16px;' . $border
            . 'font-family:Arial,Helvetica,sans-serif;font-size:10px;letter-spacing:1.6px;'
            . 'text-transform:uppercase;color:' . $INK50 . ';font-weight:bold">'
            . rk_esc($label) . '</td>'
            . '<td valign="top" style="padding:13px 16px;' . $border
            . 'font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;'
            . 'color:' . $GREEN7 . ';font-weight:bold">'
            . nl2br(rk_esc($value)) . '</td>'
            . '</tr>';
    }

    return $out . '</table>';
}

/**
 * Numbered "what happens next" list, matching the site's step styling.
 * @param string[] $steps
 */
function rk_steps(array $steps): string
{
    $CLAY  = '#D14B39';
    $INK70 = '#435049';

    $out  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px">';
    $last = count($steps) - 1;
    foreach ($steps as $i => $line) {
        $pad = $i === $last ? '0' : '0 0 12px';
        $out .= '<tr>'
            . '<td valign="top" width="30" style="padding:' . $pad . '">'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            . '<td align="center" valign="middle" width="22" height="22" style="background:#FBE9E4;border-radius:11px;'
            . 'font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;color:' . $CLAY . '">'
            . ($i + 1) . '</td></tr></table></td>'
            . '<td valign="top" style="padding:' . $pad . ';font-family:Arial,Helvetica,sans-serif;'
            . 'font-size:14px;line-height:1.6;color:' . $INK70 . '">' . $line . '</td>'
            . '</tr>';
    }
    return $out . '</table>';
}

function rk_button(string $href, string $label): string
{
    $href = rk_esc($href);
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0"><tr>'
        . '<td align="center" style="background:#06402B;border-radius:999px">'
        . '<a href="' . $href . '" style="display:inline-block;padding:13px 30px;'
        . 'font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;'
        . 'color:#ffffff;text-decoration:none;letter-spacing:.2px">' . rk_esc($label) . ' &rarr;</a>'
        . '</td></tr></table>';
}
