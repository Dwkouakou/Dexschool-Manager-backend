<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bienvenue sur DexSchool Manager</title>
</head>
<body style="margin:0; padding:0; background:#FAF8F5; font-family: 'Nunito', Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAF8F5; padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#FFFFFF; border-radius:20px; overflow:hidden; box-shadow:0 4px 24px rgba(61,46,34,0.08);">

        <!-- En-tête -->
        <tr>
          <td style="background:linear-gradient(120deg, #3D2E22 0%, #5A4636 60%, #E67E22 160%); padding:32px 36px;">
            <div style="display:inline-flex; align-items:center; gap:10px;">
              <span style="font-size:22px;">📚</span>
              <span style="font-family: Georgia, 'Playfair Display', serif; font-size:20px; font-weight:700; color:#ffffff;">
                DexSchool Manager
              </span>
            </div>
            <p style="color:rgba(255,255,255,0.75); font-size:12px; letter-spacing:0.08em; text-transform:uppercase; margin:10px 0 0; font-weight:700;">
              Confirmation de création d'établissement
            </p>
          </td>
        </tr>

        <!-- Corps -->
        <tr>
          <td style="padding:36px;">
            <h1 style="font-family: Georgia, 'Playfair Display', serif; font-size:22px; color:#3D2E22; margin:0 0 6px;">
              Bienvenue, {{ $admin->name }} 👋
            </h1>
            <p style="color:#8C7B6B; font-size:14px; margin:0 0 24px; line-height:1.6;">
              Votre établissement <strong style="color:#3D2E22;">{{ $establishment->name }}</strong> a été créé avec succès
              sur DexSchool Manager. Voici vos informations de connexion.
            </p>

            <!-- Bloc identifiants -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
              style="background:#FAF7F5; border:1px solid #EEE5DE; border-radius:14px; margin-bottom:24px;">
              <tr>
                <td style="padding:20px 22px;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding:8px 0; font-size:12px; color:#A6998C; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; width:40%;">
                        Code établissement
                      </td>
                      <td style="padding:8px 0; font-size:14px; color:#3D2E22; font-weight:700; font-family: monospace;">
                        {{ $establishment->code }}
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:8px 0; font-size:12px; color:#A6998C; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; border-top:1px solid #EEE5DE;">
                        Email de connexion
                      </td>
                      <td style="padding:8px 0; font-size:14px; color:#3D2E22; font-weight:700; border-top:1px solid #EEE5DE;">
                        {{ $admin->email }}
                      </td>
                    </tr>
                    <tr>
                      <td style="padding:8px 0; font-size:12px; color:#A6998C; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; border-top:1px solid #EEE5DE;">
                        Mot de passe
                      </td>
                      <td style="padding:8px 0; font-size:14px; color:#3D2E22; font-weight:700; font-family: monospace; border-top:1px solid #EEE5DE;">
                        {{ $password }}
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <!-- Bouton -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
              <tr>
                <td align="center">
                  <a href="{{ $loginUrl }}"
                    style="display:inline-block; background:#E67E22; color:#ffffff; text-decoration:none;
                    font-weight:800; font-size:14px; padding:14px 32px; border-radius:12px;
                    box-shadow:0 4px 14px rgba(230,126,34,0.3);">
                    Se connecter à mon espace
                  </a>
                </td>
              </tr>
            </table>

            <!-- Avertissement sécurité -->
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
              style="background:#FFF5ED; border-left:3px solid #E67E22; border-radius:10px;">
              <tr>
                <td style="padding:14px 16px; font-size:12.5px; color:#8A6D0C; line-height:1.5;">
                  ⚠️ Pour votre sécurité, nous vous recommandons de <strong>changer ce mot de passe</strong>
                  dès votre première connexion, depuis votre espace « Mon profil ».
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Pied de page -->
        <tr>
          <td style="padding:20px 36px 32px; border-top:1px solid #F5EFE9;">
            <p style="color:#C4B5AA; font-size:11.5px; margin:0; line-height:1.6;">
              Cet email a été généré automatiquement par DexSchool Manager suite à la création de votre établissement.
              Si vous n'êtes pas à l'origine de cette demande, contactez immédiatement le support.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>