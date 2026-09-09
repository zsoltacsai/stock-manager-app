<?php

require_once __DIR__ . '/../vendor/phpmailer/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * SMTP e-mail küldés — a projekt Composer NÉLKÜL fut (lásd tests/bootstrap.php
 * docblockja), ezért egy saját SMTP-implementáció írása helyett (ami könnyen
 * hibás/nem biztonságos lenne — TLS, autentikáció, MIME-encoding mind
 * finomság-érzékeny) a PHPMailer könyvtár 3 forrásfájlja van közvetlenül
 * bevendorolva (`vendor/phpmailer/{Exception,PHPMailer,SMTP}.php`,
 * PHPMailer v7.1.1, github.com/PHPMailer/PHPMailer, LGPL-2.1 licenc) —
 * PONTOSAN ezt a "Composer nélküli, közvetlen include" használatot a
 * PHPMailer saját dokumentációja is explicit támogatja/ajánlja. Frissítés:
 * a 3 fájlt manuálisan kell lecserélni egy újabb release azonos fájljaira
 * (lásd README "SMTP / email" szakasza a pontos verzió/forrás/frissítési
 * útmutatóért).
 *
 * A korábbi, MEGLÉVŐ `webroot/api/send-receipt-email.php` a PHP beépített
 * `mail()` függvényét használta, ami helyi konfigurált MTA-t (sendmail/
 * postfix) igényel — sok fejlesztői/felhő-környezetben ez nincs beállítva.
 * Ez az osztály ETTŐL FÜGGETLEN, önálló SMTP-kliens — a send-receipt-email.php
 * SMTP-t használ, ha be van állítva, egyébként a régi mail()-es útra esik
 * vissza (lásd ott).
 */
class MailerService
{
    /**
     * @param array $smtpConfig {host, port, username, password, encryption: 'none'|'ssl'|'starttls', from_email, from_name}
     * @return array{success:bool, error:?string}
     */
    public static function send(array $smtpConfig, string $toEmail, string $subject, string $html): array
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) $smtpConfig['host'];
            $mail->Port = (int) $smtpConfig['port'];
            $mail->SMTPAuth = $smtpConfig['username'] !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = (string) $smtpConfig['username'];
                $mail->Password = (string) $smtpConfig['password'];
            }
            $mail->SMTPSecure = match ($smtpConfig['encryption']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            if ($smtpConfig['encryption'] === 'none') {
                $mail->SMTPAutoTLS = false;
            }
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 10;

            $mail->setFrom((string) $smtpConfig['from_email'], (string) $smtpConfig['from_name']);
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;

            $mail->send();
            return ['success' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            // A PHPMailer::ErrorInfo tartalmazhat SMTP-szerver-válasz
            // szöveget, DE SOSE a jelszót/hitelesítő adatot — a hívó
            // (nav-incoming-sync-trigger.php mintájára) ezt közvetlenül a
            // felhasználónak mutathatja anélkül, hogy titkot szivárogtatna.
            return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
