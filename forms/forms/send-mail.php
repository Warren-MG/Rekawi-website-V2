<?php
/**
 * Contact form handler — POSTs from the "Send us a message" form.
 * Returns JSON: {"status":"success|error","message":"..."}
 */

declare(strict_types=1);

ini_set('display_errors', '0');   // never leak notices into the JSON body
error_reporting(E_ALL);

require_once __DIR__ . '/includes/mailer.php';

use PHPMailer\PHPMailer\Exception as MailException;

rk_guard();

/* ---- Collect + validate ------------------------------------------------ */
$name    = rk_clean_line(rk_field('name', 120));
$email   = rk_clean_line(rk_field('email', 254));
$subject = rk_clean_line(rk_field('subject', 180));
$service = rk_clean_line(rk_field('service', 80));
$message = rk_field('message', 5000);

$errors = [];
if ($name === '' || mb_strlen($name) < 2)   { $errors[] = 'your name'; }
if (!rk_valid_email($email))                { $errors[] = 'a valid email address'; }
if ($subject === '')                        { $errors[] = 'a subject'; }
if (mb_strlen($message) < 10)               { $errors[] = 'a message of at least 10 characters'; }

if ($errors) {
    rk_json('error', 'Please provide ' . implode(', ', $errors) . '.', 422);
}

$SERVICES = [
    'building'   => 'Building & Construction',
    'water'      => 'Water Works',
    'civil'      => 'Civil Works',
    'automation' => 'Automation & Control',
];
$serviceLabel = $SERVICES[$service] ?? ($service !== '' ? $service : 'Not specified');

$cfg = rk_config();
$ref = 'RK-C' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

/* ---- Send -------------------------------------------------------------- */
try {
    $mail = rk_mailer();
    // Inbox list shows the enquirer's name; the address stays on our own
    // domain so SPF/DKIM still pass.
    $mail->setFrom((string) $cfg['from_email'], rk_from_name($name) . ' (via rekawi.com)');

    $mail->addAddress((string) $cfg['to_email'], (string) ($cfg['to_name'] ?? ''));
    foreach ((array) ($cfg['cc'] ?? []) as $cc) {
        if (rk_valid_email((string) $cc)) { $mail->addCC((string) $cc); }
    }

    // Replying in the mail client goes straight to the enquirer.
    $mail->addReplyTo($email, $name !== '' ? $name : $email);

    $mail->Subject = 'New enquiry — ' . $subject . ' [' . $ref . ']';
    $mail->isHTML(true);

    $inner = rk_rows([
        'Reference' => $ref,
        'Name'      => $name,
        'Email'     => $email,
        'Service'   => $serviceLabel,
        'Subject'   => $subject,
        'Message'   => $message,
        'Received'  => date('D, j M Y, H:i') . ' EAT',
        'Source IP' => rk_client_ip(),
    ]) . rk_button('mailto:' . rawurlencode($email) . '?subject=' . rawurlencode('Re: ' . $subject . ' [' . $ref . ']'), 'Reply to ' . $name);

    $mail->Body = rk_email_shell(
        'New enquiry from ' . $name . ' — ' . $subject,
        rk_esc($subject),
        'Submitted through the contact form on rekawi.com. Reply directly to this email to reach the sender.',
        $inner,
        '',
        'New enquiry'
    );
    $mail->AltBody = "NEW WEBSITE ENQUIRY\n\n"
        . "Reference: {$ref}\nName: {$name}\nEmail: {$email}\n"
        . "Service: {$serviceLabel}\nSubject: {$subject}\n\nMessage:\n{$message}\n";

    $mail->send();

    /* ---- Autoresponse -------------------------------------------------- */
    if (!empty($cfg['send_autoreply'])) {
        try {
            $mail->clearAllRecipients();
            $mail->clearReplyTos();
            // Back to the company identity for the customer's copy
            $mail->setFrom((string) $cfg['from_email'], (string) ($cfg['from_name'] ?? 'Rekawi'));
            $mail->addAddress($email, $name);
            $mail->addReplyTo((string) $cfg['from_email'], (string) ($cfg['from_name'] ?? ''));
            $mail->Subject = 'We received your enquiry — ' . $cfg['company_name'];

            $summary = rk_rows([
                'Reference' => $ref,
                'Subject'   => $subject,
                'Service'   => $serviceLabel,
                'Message'   => $message,
            ]);

            $mail->Body = rk_email_shell(
                'Thanks for contacting Rekawi — we will reply within one business day.',
                'Thank you, ' . rk_esc($name),
                'We have your enquiry and a member of our team will respond within one business day. Here is a copy for your records.',
                $summary,
                'Quote your reference <strong>' . rk_esc($ref) . '</strong> if you follow up by phone.',
                'Enquiry received'
            );
            $mail->AltBody = "Dear {$name},\n\n"
                . "Thank you for contacting {$cfg['company_name']}. We have received your enquiry and will respond within one business day.\n\n"
                . "Reference: {$ref}\nSubject: {$subject}\nService: {$serviceLabel}\n\nYour message:\n{$message}\n\n"
                . "{$cfg['company_name']}\n{$cfg['company_phone']}\n{$cfg['site_url']}\n";
            $mail->send();
        } catch (MailException $e) {
            // Autoresponse is non-critical — the enquiry already reached the team.
            rk_log('Autoreply failed for ' . $email . ': ' . $mail->ErrorInfo);
        }
    }

    $mail->smtpClose();
    rk_json('success', 'Thank you. Your message has been sent — we will reply within one business day.');

} catch (MailException $e) {
    rk_log('Contact send failed [' . $ref . ']: ' . $e->getMessage());
    rk_json('error', 'We could not send your message right now. Please email info@rekawi.com or call +254 740 685581.', 500);
} catch (Throwable $e) {
    rk_log('Contact fatal [' . $ref . ']: ' . $e->getMessage());
    rk_json('error', 'Something went wrong on our side. Please email info@rekawi.com.', 500);
}
