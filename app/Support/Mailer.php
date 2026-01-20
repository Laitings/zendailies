<?php

namespace App\Support;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class Mailer
{
    /**
     * PHPMailer factory (Office365)
     */
    public static function create(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet     = 'UTF-8';
        $mail->Encoding    = 'base64';
        $mail->isSMTP();
        $mail->Host        = 'smtp.office365.com';
        $mail->Port        = 587;
        $mail->SMTPAuth    = true;
        $mail->SMTPSecure  = 'tls';
        $mail->SMTPAutoTLS = true;

        $mail->Username    = 'noreply@filmbyen.dk';
        $mail->Password    = '###Rand0mG3nerat3###PA55W0rd###';

        // Updated for the Zentropa Dailies identity
        $mail->setFrom('noreply@filmbyen.dk', 'Zentropa Dailies');

        $mail->SMTPDebug   = 0;
        $mail->Debugoutput = function ($str, $level) {
            error_log("PHPMailer[$level] $str");
        };

        return $mail;
    }
}
