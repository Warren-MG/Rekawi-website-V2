<?php
/**
 * Quote request handler — POSTs from the "Request a quote" modal.
 * Returns JSON: {"status":"success|error","message":"..."}
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/mailer.php';

use PHPMailer\PHPMailer\Exception as MailException;

rk_guard();

/* ---- Collect + validate ------------------------------------------------ */
$type = rk_clean_line(rk_field('enquiry_type', 20));
$type = in_array($type, ['service', 'product'], true) ? $type : 'general';

$name        = rk_clean_line(rk_field('name', 120));
$email       = rk_clean_line(rk_field('email', 254));
$phone       = rk_clean_line(rk_field('phone', 40));
$company     = rk_clean_line(rk_field('company', 160));
$description = rk_field('description', 6000);

// Service enquiry fields
$service      = rk_clean_line(rk_field('service', 80));
$serviceName  = rk_clean_line(rk_field('service_name', 120));
$budget       = rk_clean_line(rk_field('budget', 60));
$timeline     = rk_clean_line(rk_field('timeline', 60));
$location     = rk_clean_line(rk_field('location', 140));

// Product enquiry fields
$productName     = rk_clean_line(rk_field('product_name', 140));
$quantity        = rk_clean_line(rk_field('quantity', 80));
$deliveryPlace   = rk_clean_line(rk_field('delivery_location', 140));
$deliveryDate    = rk_clean_line(rk_field('delivery_date', 30));

$errors = [];
if ($name === '' || mb_strlen($name) < 2) { $errors[] = 'your name'; }
if (!rk_valid_email($email))              { $errors[] = 'a valid email address'; }
if (mb_strlen($description) < 10)         { $errors[] = 'a short description'; }

if ($type === 'product') {
    if ($productName === '') { $errors[] = 'the product you need'; }
    if ($quantity === '')    { $errors[] = 'the quantity required'; }
} elseif ($type === 'service') {
    if ($serviceName === '') { $errors[] = 'the service you need'; }
} else {
    if ($service === '')     { $errors[] = 'the service you need'; }
}

if ($errors) {
    rk_json('error', 'Please provide ' . implode(', ', $errors) . '.', 422);
}

if ($phone !== '' && !preg_match('/^[0-9 ()+\-]{7,25}$/', $phone)) {
    rk_json('error', 'That phone number does not look right. Use digits, spaces, + or -.', 422);
}

if ($deliveryDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
    $deliveryDate = '';
}

$SERVICES = [
    'building'   => 'Building & Construction',
    'water'      => 'Water Works',
    'civil'      => 'Civil Works',
    'automation' => 'Automation & Control',
];

if ($type === 'product') {
    $subjectLine = "Product quote request \u{2014} " . $productName;
    $itemLabel   = $productName;
} elseif ($type === 'service') {
    $subjectLine = "Service quote request \u{2014} " . $serviceName;
    $itemLabel   = $serviceName;
} else {
    $itemLabel   = $SERVICES[$service] ?? $service;
    $subjectLine = "Quote request \u{2014} " . $itemLabel;
}

$cfg = rk_config();
$ref = 'RK-Q' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

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
    $mail->addReplyTo($email, $name !== '' ? $name : $email);

    $mail->Subject = $subjectLine . ' [' . $ref . ']';
    $mail->isHTML(true);

    if ($type === 'product') {
        $rows = [
            'Reference'   => $ref,
            'Enquiry'     => 'Product quote',
            'Product'     => $productName,
            'Quantity'    => $quantity,
            'Name'        => $name,
            'Company'     => $company,
            'Email'       => $email,
            'Phone'       => $phone,
            'Deliver to'  => $deliveryPlace,
            'Needed by'   => $deliveryDate,
            'Notes'       => $description,
        ];
    } else {
        $rows = [
            'Reference'   => $ref,
            'Enquiry'     => $type === 'service' ? 'Service quote' : 'Quote request',
            'Service'     => $itemLabel,
            'Name'        => $name,
            'Company'     => $company,
            'Email'       => $email,
            'Phone'       => $phone,
            'Site / area' => $location,
            'Budget'      => $budget,
            'Timeline'    => $timeline,
            'Project'     => $description,
        ];
    }
    $rows['Received']  = date('D, j M Y, H:i') . ' EAT';
    $rows['Source IP'] = rk_client_ip();

    $inner = rk_rows($rows)
        . rk_button('mailto:' . rawurlencode($email) . '?subject=' . rawurlencode('Your quote request [' . $ref . ']'), 'Reply to ' . $name);

    $mail->Body = rk_email_shell(
        'Quote request from ' . $name . ' — ' . $itemLabel,
        rk_esc($itemLabel),
        'A quote request was submitted on rekawi.com. Reply directly to this email to reach the client.',
        $inner,
        '',
        $type === 'product' ? 'Product quote request' : 'Service quote request'
    );
    // Only include fields the client actually filled in.
    $altLines = ['NEW QUOTE REQUEST', '', "Reference: {$ref}", "Item: {$itemLabel}"];
    $altPairs = ['Name' => $name, 'Company' => $company, 'Email' => $email, 'Phone' => $phone];
    if ($type === 'product') {
        $altPairs += ['Quantity' => $quantity, 'Deliver to' => $deliveryPlace, 'Needed by' => $deliveryDate];
    } else {
        $altPairs += ['Location' => $location, 'Budget' => $budget, 'Timeline' => $timeline];
    }
    foreach ($altPairs as $k => $val) {
        if ($val !== '') {
            $altLines[] = "{$k}: {$val}";
        }
    }
    $mail->AltBody = implode("\n", $altLines) . "\n\nDescription:\n{$description}\n";

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
            $mail->Subject = 'Your quote request — ' . $cfg['company_name'];

            if ($type === 'product') {
                $stepText = [
                    'We confirm stock, specification and lead time for ' . rk_esc($itemLabel) . '.',
                    'We call you to agree quantity, delivery location and date.',
                    'You receive a written quotation including delivery cost.',
                ];
            } else {
                $stepText = [
                    'An engineer from our ' . rk_esc($itemLabel) . ' team reviews your brief.',
                    'We call you to confirm scope, site conditions and access.',
                    'You receive a written quotation with a costed breakdown.',
                ];
            }
            $steps = rk_steps($stepText);

            $summary = rk_rows([
                'Reference'                              => $ref,
                $type === 'product' ? 'Product' : 'Service' => $itemLabel,
                $type === 'product' ? 'Quantity' : 'Project' => $type === 'product' ? $quantity : $description,
            ]);

            $mail->Body = rk_email_shell(
                'We received your quote request — here is what happens next.',
                'Thank you, ' . rk_esc($name),
                'Your quote request is with our team. Here is what happens next:',
                $steps . $summary,
                'Quote your reference <strong>' . rk_esc($ref) . '</strong> if you follow up by phone.',
                'Quote request received'
            );
            $mail->AltBody = "Dear {$name},\n\n"
                . "Thank you for your quote request regarding {$itemLabel}.\n\n"
                . "What happens next:\n"
                . "1. " . strip_tags($stepText[0]) . "\n"
                . "2. " . strip_tags($stepText[1]) . "\n"
                . "3. " . strip_tags($stepText[2]) . "\n\n"
                . "Reference: {$ref}\n\nYour description:\n{$description}\n\n"
                . "{$cfg['company_name']}\n{$cfg['company_phone']}\n{$cfg['site_url']}\n";
            $mail->send();
        } catch (MailException $e) {
            rk_log('Quote autoreply failed for ' . $email . ': ' . $mail->ErrorInfo);
        }
    }

    $mail->smtpClose();
    rk_json('success', 'Quote request sent. Our team will be in touch within one business day.');

} catch (MailException $e) {
    rk_log('Quote send failed [' . $ref . ']: ' . $e->getMessage());
    rk_json('error', 'We could not send your request right now. Please email info@rekawi.com or call +254 740 685581.', 500);
} catch (Throwable $e) {
    rk_log('Quote fatal [' . $ref . ']: ' . $e->getMessage());
    rk_json('error', 'Something went wrong on our side. Please email info@rekawi.com.', 500);
}
