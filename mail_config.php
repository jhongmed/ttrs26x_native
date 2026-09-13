<?php
/**
 * SMTP Mail Configuration for TTRS (Gmail SMTP).
 *
 * HOW TO CONFIGURE GMAIL SMTP:
 * 1. Ensure 2-Step Verification is enabled on your Google Account:
 *    https://myaccount.google.com/signinoptions/two-step-verification
 * 2. Generate an App Password:
 *    https://myaccount.google.com/apppasswords
 *    - Enter an app name (e.g. "TTRS Login")
 *    - Google will provide a 16-character App Password (e.g. "abcd efgh ijkl mnop")
 * 3. Set SMTP_USER to your full Gmail address and SMTP_PASS to the 16-character App Password below.
 */

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// SMTP Server Settings for Gmail
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587)); // 587 for STARTTLS, 465 for SMTPS
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls'); // 'tls' or 'ssl'
define('SMTP_AUTH', true);

// Gmail Credentials - UPDATE THESE WITH YOUR GMAIL DETAILS
define('SMTP_USER', getenv('SMTP_USER') ?: 'misdept.orchard@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'cbho phrn uqsm fsmh');

// Default Sender Information
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: (SMTP_USER !== 'your-email@gmail.com' ? SMTP_USER : 'no-reply@theorchardgolf.com'));
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'The Orchard Golf & Country Club');

/**
 * Sends an email using PHPMailer and Gmail SMTP.
 *
 * @param string $recipientEmail Target email address
 * @param string $subject Email subject line
 * @param string $htmlBody HTML content
 * @param string $textBody Plain text fallback content (optional)
 * @return bool True if sent successfully, false otherwise
 */
function send_app_mail(string $recipientEmail, string $subject, string $htmlBody, string $textBody = ''): bool
{
    // Safety check for placeholder credentials
    if (SMTP_USER === 'your-email@gmail.com' || SMTP_PASS === 'your-16-char-app-password') {
        error_log('[TTRS Mail] Gmail SMTP credentials are not configured in mail_config.php. Please set your Gmail address and App Password.');
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
        if (strtolower(SMTP_ENCRYPTION) === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = SMTP_PORT ?: 465;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT ?: 587;
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
