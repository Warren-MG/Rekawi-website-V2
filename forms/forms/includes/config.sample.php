<?php
/**
 * Rekawi Company Limited — mail configuration
 *
 * SETUP
 * 1. Copy this file to `config.php` in the same directory.
 * 2. Fill in the SMTP password (and host/user if they differ).
 * 3. Keep `config.php` OUT of version control. Never commit real credentials.
 *
 * SECURITY
 * This directory ships with an .htaccess that denies direct web access.
 * If you can, move this folder ABOVE your public_html and update the
 * require path at the top of send-mail.php / send-quote.php to match.
 */

return [

    // ---- SMTP transport -------------------------------------------------
    // cPanel: Email Accounts -> Connect Devices shows the exact host.
    'smtp_host'    => 'mail.rekawi.com',
    'smtp_user'    => 'info@rekawi.com',

    // Leave blank here. Set the real value in config.php only.
    'smtp_pass'    => '',

    // 'tls' on port 587, or 'ssl' on port 465. Prefer 465/ssl if offered.
    // ('none' disables encryption — for local testing only, never in production.)
    'smtp_secure'  => 'tls',
    'smtp_port'    => 587,

    // Set to 2 temporarily to log the SMTP conversation while debugging.
    'smtp_debug'   => 0,

    // ---- Addresses ------------------------------------------------------
    // From address MUST be a mailbox on your own domain, or SPF/DKIM fails
    // and mail lands in spam. Never send "from" the visitor's address.
    'from_email'   => 'info@rekawi.com',
    'from_name'    => 'Rekawi Company Limited',

    // Where enquiries are delivered. Add more addresses to CC the team.
    'to_email'     => 'info@rekawi.com',
    'to_name'      => 'Rekawi Company Limited',
    'cc'           => [],

    // ---- Behaviour ------------------------------------------------------
    'send_autoreply'  => true,
    'company_name'    => 'Rekawi Company Limited',
    'company_phone'   => '+254 740 685581',
    'company_address' => 'Roscoe Centre, Old Msa Rd, 19936-00100, Nairobi, Kenya',
    'site_url'        => 'https://rekawi.com',
    'logo_url'        => 'https://rekawi.com/assets/images/logo.jpg',

    // Max submissions per IP per hour. 0 disables rate limiting.
    'rate_limit'      => 6,

    // Absolute path for the error log. Must be writable by PHP.
    'log_file'        => __DIR__ . '/mail-error.log',
];
