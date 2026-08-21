<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Code de réinitialisation</title>
</head>
<body style="margin:0; padding:0; background:#FAF8F5; font-family: 'Nunito', Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF8F5; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background:#FFFFFF; border-radius:20px; overflow:hidden; box-shadow:0 4px 24px rgba(61,46,34,0.08);">

        <tr>
          <td style="background:linear-gradient(120deg, #3D2E22 0%, #5A4636 60%, #E67E22 160%); padding:28px 32px;">
            <div style="display:inline-flex; align-items:center; gap:10px;">
              <span style="font-size:20px;">🔐</span>
              <span style="font-family: Georgia, 'Playfair Display', serif; font-size:18px; font-weight:700; color:#ffffff;">
                DexSchool Manager
              </span>
            </div>
          </td>
        </tr>

        <tr>
          <td style="padding:36px;">
            <h1 style="font-family: Georgia, 'Playfair Display', serif; font-size:20px; color:#3D2E22; margin:0 0 8px;">
              Bonjour {{ $user->name }},
            </h1>
            <p style="color:#8C7B6B; font-size:14px; margin:0 0 28px; line-height:1.6;">
              Voici le code à saisir pour réinitialiser votre mot de passe. Il est valable
              <strong style="color:#3D2E22;">10 minutes</strong> et ne peut être utilisé qu'une seule fois.
            </p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
              style="background:#FAF7F5; border:1px solid #EEE5DE; border-radius:14px; margin-bottom:24px;">
              <tr>
                <td align="center" style="padding:24px;">
                  <span style="font-family: monospace; font-size:34px; font-weight:800; letter-spacing:10px; color:#E67E22;">
                    {{ $code }}
                  </span>
                </td>
              </tr>
            </table>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
              style="background:#FFF5ED; border-left:3px solid #E67E22; border-radius:10px;">
              <tr>
                <td style="padding:14px 16px; font-size:12.5px; color:#8A6D0C; line-height:1.5;">
                  ⚠️ Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email —
                  votre mot de passe actuel reste inchangé.
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 36px 28px; border-top:1px solid #F5EFE9;">
            <p style="color:#C4B5AA; font-size:11.5px; margin:0; line-height:1.6;">
              Email automatique DexSchool Manager — ne pas répondre.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>