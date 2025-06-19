<?php
// src/mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envoie notification à l’agence et accusé au client.
 *
 * @param array $data {
 *   @type string $civilite
 *   @type string $nom
 *   @type string $prenom
 *   @type string $email
 *   @type string $telephone
 *   @type string $type
 *   @type string $messageTxt
 *   @type array  $disponibilites  Tableau de "Jour|Heure|Minute"
 * }
 */
function sendNotificationEmails(array $data): void
{
    // Construire le texte des dispos
    $disposTexte = '';
    if (!empty($data['disponibilites'])) {
        $t = [];
        foreach ($data['disponibilites'] as $raw) {
            list($j, $h, $m) = explode('|', $raw);
            $t[] = "$j à {$h}h{$m}";
        }
        $disposTexte = implode(', ', $t);
    }

    // Paramètres d’expéditeur et destinataire
    $fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@monsite.test';
    $fromName    = $_ENV['MAIL_FROM_NAME']    ?? 'Agence Immobilière';
    $agencyEmail = $_ENV['AGENCY_EMAIL'];

    //
    // 1) Mail à l’agence
    //
    try {
        $mail = new PHPMailer(true);
        $mail->AuthType    = 'LOGIN';
        $mail->isSMTP();
        $mail->Host        = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth    = true;
        $mail->Username    = $_ENV['SMTP_USER'];
        $mail->Password    = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port        = (int) $_ENV['SMTP_PORT'];

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($agencyEmail);

        $mail->Subject = 'Nouvelle demande de visite';
        $mail->Body    = <<<EOD
Vous avez reçu une nouvelle demande de visite :
• Civilité    : {$data['civilite']}
• Nom / Prénom: {$data['nom']} {$data['prenom']}
• Email       : {$data['email']}
• Téléphone   : {$data['telephone']}
• Type        : {$data['type']}
• Message     : {$data['messageTxt']}
• Dispos      : $disposTexte
EOD;

        $mail->send();
    } catch (Exception $e) {
        error_log('Erreur mail agence : ' . $e->getMessage());
    }

    //
    // 2) Mail d’accusé au client
    //
    try {
        $mail2 = new PHPMailer(true);
        $mail2->AuthType    = 'LOGIN';
        $mail2->isSMTP();
        $mail2->Host        = $_ENV['SMTP_HOST'];
        $mail2->SMTPAuth    = true;
        $mail2->Username    = $_ENV['SMTP_USER'];
        $mail2->Password    = $_ENV['SMTP_PASS'];
        $mail2->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
        $mail2->Port        = (int) $_ENV['SMTP_PORT'];

        $mail2->setFrom($fromAddress, $fromName);
        $mail2->addAddress($data['email'], "{$data['civilite']} {$data['nom']}");

        $mail2->Subject = 'Accusé de réception de votre demande';
        $mail2->Body    = <<<EOD
Bonjour {$data['prenom']},

Merci d'avoir contacté notre agence. Nous avons bien reçu votre demande de visite.
Nous reviendrons vers vous sous 24 heures.

Cordialement,
L'équipe de l'agence immobilière
EOD;

        $mail2->send();
    } catch (Exception $e) {
        error_log('Erreur mail client : ' . $e->getMessage());
    }
}
