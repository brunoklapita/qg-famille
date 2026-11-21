<?php
/** QG de la famille — première configuration : choix des codes. Se verrouille une fois fait. */
declare(strict_types=1);
const DATA_DIR = __DIR__ . '/data';
$cfgFile = DATA_DIR . '/config.json';
$msg = ''; $done = is_file($cfgFile);

if (!$done && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p = (string)($_POST['parent'] ?? ''); $k = (string)($_POST['kid'] ?? '');
    if (mb_strlen($p) < 6)        $msg = 'Le code parents doit faire au moins 6 caractères.';
    elseif (mb_strlen($k) < 4)    $msg = 'Le code enfants doit faire au moins 4 caractères.';
    elseif ($p === $k)            $msg = 'Les deux codes doivent être différents.';
    elseif (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0700, true)) $msg = 'Impossible de créer le dossier data/.';
    elseif (!is_writable(DATA_DIR)) $msg = 'Le dossier data/ n\'est pas accessible en écriture (droits 700 ou 755).';
    else {
        $ok = @file_put_contents($cfgFile, json_encode([
            'parent_hash' => password_hash($p, PASSWORD_DEFAULT),
            'kid_hash'    => password_hash($k, PASSWORD_DEFAULT),
            'created'     => date('c'),
        ]), LOCK_EX);
        if ($ok === false) $msg = 'Écriture impossible dans data/.';
        else { @chmod($cfgFile, 0600); $done = true; $msg = 'ok'; }
    }
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installation du QG</title>
<style>body{font-family:system-ui,sans-serif;background:#f6f1e7;color:#1f2a2e;display:grid;place-items:center;min-height:100vh;margin:0}
.c{background:#fffdf8;border:1px solid #e6dccb;border-radius:18px;padding:24px;max-width:380px;width:92%}
label{display:block;font-weight:700;margin-top:14px}input{width:100%;box-sizing:border-box;padding:10px;border-radius:10px;border:1.5px solid #e6dccb;font-size:1rem;margin-top:4px}
button{margin-top:18px;width:100%;padding:12px;border:0;border-radius:12px;background:#1d6f84;color:#fff;font-weight:800;font-size:1rem}
.err{color:#e2614b;font-weight:700}.ok{color:#4f8a4b;font-weight:700}</style></head><body><div class="c">
<h1>🏠 Installation du QG</h1>
<?php if ($done): ?>
  <p class="ok"><?= $msg === 'ok' ? '✅ Codes enregistrés !' : '🔒 Le QG est déjà configuré.' ?></p>
  <p>Par sécurité, <b>supprime maintenant le fichier <code>setup.php</code></b> du serveur, puis ouvre <a href="./">le QG</a>.</p>
  <p style="font-size:.9rem;color:#6b7478">Pour changer les codes plus tard : supprime <code>data/config.json</code>, renvoie <code>setup.php</code> et recommence.</p>
<?php else: ?>
  <p>Choisis les deux codes d'accès. Le code enfants ne donne pas accès au coin Noël 🎁.</p>
  <?php if ($msg): ?><p class="err"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
  <form method="post">
    <label>Code parents (6 caractères min.)<input type="password" name="parent" required minlength="6" autocomplete="new-password"></label>
    <label>Code enfants (4 caractères min.)<input type="password" name="kid" required minlength="4" autocomplete="new-password"></label>
    <button>Enregistrer</button>
  </form>
<?php endif; ?>
</div></body></html>
