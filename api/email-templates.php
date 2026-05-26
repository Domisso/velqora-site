<!DOCTYPE html>
<!-- ═══════════════════════════════════════════════════
     VELQORA — Szablony e-mail HTML
     Zapisz jako: /api/email-templates.php
     ═══════════════════════════════════════════════════ -->
<?php
// Użycie: EmailTemplates::verification($name, $link)
//         EmailTemplates::resetPassword($name, $link)
//         EmailTemplates::invoiceSent($invoiceNumber, $amount, $dueDate)
//         EmailTemplates::paymentConfirmed($name, $plan, $expiresAt)

class EmailTemplates {

    private static function wrap(string $content, string $preheader = ''): string {
        return '<!DOCTYPE html>
<html lang="pl"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Velqora</title>
<style>
body{margin:0;padding:0;background:#0a0b0f;font-family:Arial,sans-serif}
.wrap{max-width:580px;margin:0 auto;padding:32px 16px}
.card{background:#0d1018;border-radius:16px;overflow:hidden;border:1px solid rgba(79,127,255,.15)}
.header{background:linear-gradient(135deg,#4f7fff,#7b5fff);padding:32px;text-align:center}
.logo{font-size:26px;font-weight:900;color:#fff;letter-spacing:-.01em}
.body{padding:36px 32px}
h2{color:#eef0f6;font-size:22px;margin:0 0 12px;font-weight:700}
p{color:#8a90a8;font-size:15px;line-height:1.7;margin:0 0 16px}
.btn{display:inline-block;background:linear-gradient(135deg,#4f7fff,#7b5fff);color:#fff!important;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:600;font-size:15px;margin:8px 0 16px}
.info-box{background:rgba(79,127,255,.07);border:1px solid rgba(79,127,255,.15);border-radius:10px;padding:16px 20px;margin:16px 0}
.info-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:14px}
.info-row:last-child{border-bottom:none}
.ik{color:#5a6080}
.iv{color:#eef0f6;font-weight:600}
.divider{height:1px;background:rgba(255,255,255,.06);margin:20px 0}
.footer{padding:24px 32px;text-align:center;border-top:1px solid rgba(255,255,255,.06)}
.ft{font-size:12px;color:#3a4060;line-height:1.7}
.ft a{color:#4f7fff;text-decoration:none}
.link-box{background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:12px 16px;margin:12px 0;word-break:break-all;font-size:12px;color:#5a6080}
</style>
</head>
<body>
<!--[if mso]><table><tr><td><![endif]-->
<div class="wrap">
  <div class="card">
    <div class="header">
      <div class="logo">⚖ Velqora</div>
      <div style="color:rgba(255,255,255,.7);font-size:13px;margin-top:4px">Inteligentna platforma księgowa</div>
    </div>
    <div class="body">' . $content . '</div>
    <div class="footer">
      <p class="ft">
        Velqora Sp. z o.o. · ul. Złota 59a, 00-120 Warszawa<br/>
        <a href="https://velqora.pl">velqora.pl</a> · <a href="https://velqora.pl/polityka-prywatnosci">Polityka prywatności</a><br/>
        Ta wiadomość została wysłana automatycznie — prosimy na nią nie odpowiadać.
      </p>
    </div>
  </div>
</div>
<!--[if mso]></td></tr></table><![endif]-->
</body></html>';
    }

    // ── WERYFIKACJA E-MAIL ────────────────────────────
    public static function verification(string $name, string $link): string {
        $content = "
<h2>Witaj, $name! 👋</h2>
<p>Dziękujemy za rejestrację w Velqora. Kliknij poniższy przycisk, aby potwierdzić swój adres e-mail i aktywować konto.</p>
<div style='text-align:center;margin:24px 0'>
  <a href='$link' class='btn'>✓ Potwierdź adres e-mail</a>
</div>
<p style='font-size:13px;color:#3a4060'>Lub skopiuj ten link do przeglądarki:</p>
<div class='link-box'>$link</div>
<div class='divider'></div>
<p style='font-size:13px;color:#3a4060'>Link jest ważny przez 24 godziny. Jeśli nie rejestrujesz się w Velqora, zignoruj tę wiadomość.</p>";
        return self::wrap($content, "Potwierdź adres e-mail, aby aktywować konto Velqora");
    }

    // ── RESET HASŁA ───────────────────────────────────
    public static function resetPassword(string $name, string $link): string {
        $content = "
<h2>Reset hasła 🔐</h2>
<p>Cześć $name,</p>
<p>Otrzymaliśmy prośbę o reset hasła do Twojego konta Velqora. Kliknij poniższy przycisk, aby ustawić nowe hasło.</p>
<div style='text-align:center;margin:24px 0'>
  <a href='$link' class='btn'>Ustaw nowe hasło →</a>
</div>
<div class='link-box'>$link</div>
<div class='divider'></div>
<p style='font-size:13px;color:#ef4444'>⚠️ Link wygasa po 1 godzinie.</p>
<p style='font-size:13px;color:#3a4060'>Jeśli to nie Ty wysłałeś tę prośbę, zignoruj tę wiadomość — Twoje hasło nie zostanie zmienione.</p>";
        return self::wrap($content, "Zresetuj hasło do swojego konta Velqora");
    }

    // ── FAKTURA WYSŁANA ───────────────────────────────
    public static function invoiceSent(string $sellerName, string $number, float $gross, string $dueDate, string $iban = ''): string {
        $grossFmt = number_format($gross, 2, ',', ' ') . ' PLN';
        $content  = "
<h2>Nowa faktura 📄</h2>
<p>Przesyłamy fakturę VAT wystawioną przez <strong style='color:#eef0f6'>$sellerName</strong>.</p>
<div class='info-box'>
  <div class='info-row'><span class='ik'>Nr faktury</span><span class='iv'>$number</span></div>
  <div class='info-row'><span class='ik'>Kwota brutto</span><span class='iv' style='color:#4f7fff'>$grossFmt</span></div>
  <div class='info-row'><span class='ik'>Termin płatności</span><span class='iv'>$dueDate</span></div>" .
  ($iban ? "<div class='info-row'><span class='ik'>Nr konta</span><span class='iv' style='font-size:13px'>$iban</span></div>" : '') . "
</div>
<p style='font-size:13px;color:#3a4060'>W tytule przelewu wpisz numer faktury: <strong>$number</strong></p>";
        return self::wrap($content, "Faktura $number na kwotę $grossFmt — termin $dueDate");
    }

    // ── POTWIERDZENIE PŁATNOŚCI ───────────────────────
    public static function paymentConfirmed(string $name, string $plan, string $expiresAt, float $amount): string {
        $planName  = ucfirst($plan);
        $amountFmt = number_format($amount, 2, ',', ' ') . ' PLN';
        $content   = "
<h2>Płatność potwierdzona ✅</h2>
<p>Cześć $name,</p>
<p>Twoja subskrypcja Velqora została pomyślnie odnowiona. Dziękujemy!</p>
<div class='info-box'>
  <div class='info-row'><span class='ik'>Plan</span><span class='iv' style='color:#4f7fff'>$planName</span></div>
  <div class='info-row'><span class='ik'>Kwota</span><span class='iv'>$amountFmt</span></div>
  <div class='info-row'><span class='ik'>Aktywny do</span><span class='iv'>$expiresAt</span></div>
</div>
<div style='text-align:center;margin:24px 0'>
  <a href='https://velqora.pl/dashboard.html' class='btn'>Przejdź do aplikacji →</a>
</div>
<p style='font-size:13px;color:#3a4060'>Fakturę VAT za subskrypcję znajdziesz w panelu: Ustawienia → Płatności.</p>";
        return self::wrap($content, "Plan $planName aktywny do $expiresAt");
    }

    // ── FAKTURA PRZETERMINOWANA ───────────────────────
    public static function invoiceOverdue(string $clientName, string $number, float $gross, int $daysOverdue): string {
        $grossFmt = number_format($gross, 2, ',', ' ') . ' PLN';
        $content  = "
<h2>Przypomnienie o płatności ⚠️</h2>
<p>Szanowni Państwo,</p>
<p>Pragniemy przypomnieć o nieuregulowanej płatności za fakturę <strong style='color:#eef0f6'>$number</strong>.</p>
<div class='info-box'>
  <div class='info-row'><span class='ik'>Nr faktury</span><span class='iv'>$number</span></div>
  <div class='info-row'><span class='ik'>Kwota</span><span class='iv' style='color:#ef4444'>$grossFmt</span></div>
  <div class='info-row'><span class='ik'>Opóźnienie</span><span class='iv' style='color:#ef4444'>$daysOverdue dni</span></div>
</div>
<p>Prosimy o dokonanie wpłaty w możliwie najkrótszym terminie. W przypadku pytań prosimy o kontakt.</p>
<p style='font-size:13px;color:#3a4060'>Jeśli płatność została już dokonana, prosimy o zignorowanie tej wiadomości.</p>";
        return self::wrap($content, "Przypomnienie: faktura $number — $grossFmt — opóźnienie $daysOverdue dni");
    }

    // ── WYŚLIJ E-MAIL ─────────────────────────────────
    public static function send(string $to, string $subject, string $htmlBody): bool {
        $boundary = md5(uniqid());
        $headers  = implode("\r\n", [
            "From: Velqora <" . SMTP_FROM . ">",
            "Reply-To: " . SMTP_FROM,
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
            "X-Mailer: Velqora/1.0",
        ]);
        $plain = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody));
        $message = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode($plain))
                 . "--$boundary\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode($htmlBody))
                 . "--$boundary--";
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);
    }
}
