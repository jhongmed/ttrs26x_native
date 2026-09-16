<?php
/**
 * SMTP Mail Configuration for TTRS (Gmail SMTP).
 * Reads configuration from .env via getenv().
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/auth_common.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// SMTP Server Settings
defined('SMTP_HOST') or define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
defined('SMTP_PORT') or define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
defined('SMTP_ENCRYPTION') or define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls');
defined('SMTP_AUTH') or define('SMTP_AUTH', getenv('SMTP_AUTH') !== 'false');

// Gmail / SMTP Credentials
defined('SMTP_USER') or define('SMTP_USER', getenv('SMTP_USER') ?: '');
defined('SMTP_PASS') or define('SMTP_PASS', getenv('SMTP_PASS') ?: '');

// Default Sender Information
defined('MAIL_FROM_ADDRESS') or define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: (SMTP_USER !== '' ? SMTP_USER : 'no-reply@theorchardgolf.com'));
defined('MAIL_FROM_NAME') or define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'The Orchard Golf & Country Club');

/**
 * Checks whether SMTP credentials have been set.
 */
function is_smtp_configured(): bool
{
    return SMTP_USER !== '' && SMTP_PASS !== '';
}

/**
 * Sends an email using PHPMailer and SMTP.
 *
 * @param string $recipientEmail Target email address
 * @param string $subject Email subject line
 * @param string $htmlBody HTML content
 * @param string $textBody Plain text fallback content (optional)
 * @return bool True if sent successfully, false otherwise
 */
function send_app_mail(string $recipientEmail, string $subject, string $htmlBody, string $textBody = ''): bool
{
    if (!is_smtp_configured()) {
        error_log('[TTRS Mail] SMTP_USER and/or SMTP_PASS are not set. Cannot send email to: ' . $recipientEmail);
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->CharSet    = 'UTF-8';

        // Encryption and Port
        $enc = strtolower(SMTP_ENCRYPTION);
        if ($enc === 'ssl' || $enc === 'smtps') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = SMTP_PORT ?: 465;
        } elseif ($enc === 'tls' || $enc === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT ?: 587;
        } else {
            $mail->SMTPSecure  = '';
            $mail->SMTPAutoTLS = false;
            $mail->Port        = SMTP_PORT ?: 25;
        }

        // Recipients
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($recipientEmail);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[TTRS Mail] Message could not be sent. Mailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

