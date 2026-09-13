<?php

require_once __DIR__ . '/email_branding_helper.php';

/**
 * Build a branded authentication email with remote (non-attached) images.
 *
 * @return array{subject:string,html:string,text:string}
 */
function itourBuildVerificationEmail(string $purpose, string $code, string $recipientName = '', array $branding = []): array
{
    $isPasswordReset = $purpose === 'password_reset';
    $logoUrl = htmlspecialchars(
        (string)($branding['logo_url'] ?? itourEmailAssetUrl('img/newlogo.png', 'BOOKING_EMAIL_LOGO_URL')),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $wordmarkUrl = htmlspecialchars(
        (string)($branding['wordmark_url'] ?? itourEmailAssetUrl('img/textlogo2.png', 'BOOKING_EMAIL_TEXT_LOGO_URL')),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $name = trim($recipientName);
    $safeGreeting = $name !== ''
        ? 'Dear ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ','
        : 'Hello,';

    $subject = $isPasswordReset ? 'Your iTour Mercedes password reset code' : 'Verify your email for iTour Mercedes';
    $eyebrow = $isPasswordReset ? 'PASSWORD RESET REQUEST' : 'EMAIL VERIFICATION';
    $heading = $isPasswordReset ? 'Reset your password securely' : 'Complete your account registration';
    $intro = $isPasswordReset
        ? 'We received a request to reset the password for your iTour Mercedes tourist account. Enter the verification code below on the password reset screen to continue.'
        : 'Thank you for creating an iTour Mercedes tourist account. Enter the verification code below on the registration screen to confirm your email and continue setting up your account.';
    $action = $isPasswordReset
        ? 'After the code is verified, you can create a new password and sign in to your account.'
        : 'After verification, complete the remaining registration step. You can then sign in and begin managing your Mercedes travel plans.';
    $unrequested = $isPasswordReset
        ? 'If you did not request a password reset, you may safely ignore this email. Your current password will remain unchanged.'
        : 'If you did not create an iTour Mercedes account, you may safely ignore this email.';
    $preheader = $isPasswordReset
        ? 'Use this secure code to continue resetting your iTour Mercedes password.'
        : 'Use this secure code to verify your email and complete registration.';

    $brand = '<img src="' . $logoUrl . '" width="58" height="58" alt="iTour Mercedes logo" style="display:inline-block;width:58px;height:58px;object-fit:contain;vertical-align:middle;border:0;">'
        . '<img src="' . $wordmarkUrl . '" width="190" alt="iTour Mercedes" style="display:inline-block;width:190px;max-width:64%;height:auto;margin-left:12px;vertical-align:middle;border:0;">';

    $codeCells = '';
    foreach (str_split($code) as $index => $digit) {
        if ($index > 0) {
            $codeCells .= '<td width="8" style="width:8px;font-size:0;line-height:0;">&nbsp;</td>';
        }
        $codeCells .= '<td width="44" height="56" align="center" valign="middle" style="width:44px;height:56px;color:#0e654f;background:#edf8f4;border:1px solid #8fc7b5;border-radius:9px;font-family:Consolas,Monaco,monospace;font-size:28px;font-weight:700;line-height:56px;">'
            . htmlspecialchars($digit, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    }

    $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8')
        . '</title></head><body style="margin:0;padding:0;background:#edf4f1;font-family:Arial,Helvetica,sans-serif;color:#17342d;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#edf4f1;"><tr><td align="center" style="padding:32px 14px;">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#fff;border:1px solid #d5e5df;border-radius:18px;overflow:hidden;box-shadow:0 12px 35px rgba(19,74,59,.12);">'
        . '<tr><td style="height:6px;background:#218065;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td align="center" style="padding:28px 28px 20px;border-bottom:1px solid #e6eeeb;">' . $brand . '</td></tr>'
        . '<tr><td style="padding:34px 42px 12px;"><div style="margin-bottom:10px;color:#176b55;font-size:12px;font-weight:700;letter-spacing:1.5px;text-align:center;">' . $eyebrow . '</div>'
        . '<h1 style="margin:0 0 22px;color:#153b31;font-size:27px;line-height:1.2;text-align:center;">' . $heading . '</h1>'
        . '<p style="margin:0 0 15px;color:#294940;font-size:15px;line-height:1.7;">' . $safeGreeting . '</p>'
        . '<p style="margin:0;color:#526a63;font-size:15px;line-height:1.7;text-align:justify;text-indent:28px;">' . $intro . '</p></td></tr>'
        . '<tr><td align="center" style="padding:22px 32px 24px;"><div style="margin-bottom:10px;color:#71847e;font-size:11px;font-weight:700;letter-spacing:1.4px;">YOUR SIX-DIGIT CODE</div>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center"><tr>' . $codeCells . '</tr></table>'
        . '<p style="margin:14px 0 0;color:#526a63;font-size:13px;line-height:1.6;">This code expires in <strong style="color:#294940;">10 minutes</strong> and can only be used once.</p></td></tr>'
        . '<tr><td style="padding:0 42px 32px;"><div style="padding:16px 18px;background:#f7faf9;border:1px solid #dce9e4;border-radius:12px;color:#526a63;font-size:13px;line-height:1.65;text-align:justify;">' . $action . '</div>'
        . '<div style="margin-top:18px;padding:14px 16px;background:#fff7f5;border-left:4px solid #c75a4e;border-radius:8px;color:#74433d;font-size:12px;line-height:1.6;"><strong>Security reminder:</strong> Never share this code with anyone. iTour Mercedes will never ask you to provide a verification code by phone, text message, or chat.</div>'
        . '<p style="margin:20px 0 0;color:#71817c;font-size:12px;line-height:1.6;">' . $unrequested . '</p></td></tr>'
        . '<tr><td align="center" style="padding:22px 28px;background:#123e33;color:#dcece6;font-size:11px;line-height:1.7;"><strong style="color:#fff;font-size:12px;">iTour Mercedes</strong><br>Mercedes, Camarines Norte<br>This is an automated security email. Please do not reply.<br>&copy; '
        . date('Y') . ' iTour Mercedes. All rights reserved.</td></tr></table></td></tr></table></body></html>';

    $plainGreeting = $name !== '' ? 'Dear ' . $name . ',' : 'Hello,';
    $text = "{$heading}\n\n{$plainGreeting}\n\n{$intro}\n\nYour six-digit verification code: {$code}\n\nThis code expires in 10 minutes and can only be used once.\n\n{$action}\n\nSecurity reminder: Never share this code with anyone. iTour Mercedes will never ask for it by phone, text message, or chat.\n\n{$unrequested}\n\niTour Mercedes\nMercedes, Camarines Norte";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
