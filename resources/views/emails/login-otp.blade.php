<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Code de connexion</title>
</head>
<body style="margin:0; padding:0; background-color:#FAF7F5; font-family: Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#FAF7F5; padding: 30px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#FFFFFF; border-radius:16px; overflow:hidden; border:1px solid #EEE5DE;">
                    <tr>
                        <td style="background-color:#3D2E22; padding: 24px 32px;">
                            <span style="color:#FFFFFF; font-size:18px; font-weight:bold;">DexSchool Manager</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px;">
                            <p style="color:#221911; font-size:15px; margin: 0 0 8px;">Bonjour {{ $user->name }},</p>
                            <p style="color:#5D6D7E; font-size:14px; line-height:1.6; margin: 0 0 24px;">
                                Voici votre code de vérification pour vous connecter à votre espace DexSchool Manager.
                                Ce code est valable <strong>10 minutes</strong>.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="background-color:#FAF7F5; border:1px solid #EEE5DE; border-radius:12px; padding: 20px;">
                                        <span style="font-family: 'Courier New', monospace; font-size: 32px; font-weight: bold; letter-spacing: 8px; color:#E67E22;">
                                            {{ $code }}
                                        </span>
                                    </td>
                                </tr>
                            </table>

                            <p style="color:#8C7B6B; font-size:12.5px; line-height:1.6; margin: 24px 0 0;">
                                Si vous n'êtes pas à l'origine de cette tentative de connexion, ignorez simplement cet
                                email — aucune action n'est requise de votre part et votre compte reste sécurisé.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 32px; border-top:1px solid #EEE5DE; background-color:#FAF7F5;">
                            <p style="color:#BBA98A; font-size:11px; margin:0; text-align:center;">
                                Cet email a été envoyé automatiquement par DexSchool Manager. Merci de ne pas y répondre.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>