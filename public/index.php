<?php
// public/index.php

// 1) Démarrage de la session et génération du token CSRF
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2) Chargement de l’autoloader Composer et du .env
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// 3) Vérification reCAPTCHA (avant tout traitement POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    $secret            = $_ENV['RECAPTCHA_SECRET'] ?? '';
    $verifyUrl         = "https://www.google.com/recaptcha/api/siteverify?secret={$secret}&response={$recaptchaResponse}";
    $verifyResp        = file_get_contents($verifyUrl);
    $json              = json_decode($verifyResp, true);
    if (empty($json['success'])) {
        throw new \Exception('Merci de cocher “Je ne suis pas un robot”.');
    }
}

// 4) Récupération des variables d’environnement pour la BDD
$host    = $_ENV['DB_HOST'];
$db      = $_ENV['DB_NAME'];
$user    = $_ENV['DB_USER'];
$pass    = $_ENV['DB_PASS'];
$charset = $_ENV['DB_CHARSET'];

// 5) Configuration PDO
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$message_envoye = false;
$erreur_message = '';

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf'] ?? '')) {
            throw new \Exception('Requête invalide (CSRF).');
        }
        // Honeypot
        if (!empty($_POST['hp_email'])) {
            throw new \Exception('Spam détecté.');
        }

        // Lecture & nettoyage
        $civilite   = htmlspecialchars($_POST['civilite']    ?? '');
        $nom        = htmlspecialchars($_POST['nom']         ?? '');
        $prenom     = htmlspecialchars($_POST['prenom']      ?? '');
        $email      = htmlspecialchars($_POST['email']       ?? '');
        $telephone  = htmlspecialchars($_POST['telephone']   ?? '');
        $type       = htmlspecialchars($_POST['type_demande'] ?? '');
        $messageTxt = htmlspecialchars($_POST['message']     ?? '');

        // Validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Email invalide.');
        }
        if (!preg_match('/^0[1-9](?:[ .-]?\d{2}){4}$/', $telephone)) {
            throw new \Exception('Téléphone invalide.');
        }
        if (!$civilite || !$nom || !$prenom || !$type || !$messageTxt) {
            throw new \Exception('Champs obligatoires manquants.');
        }

        // Transaction et insertions
        $pdo->beginTransaction();

        // Insert contact
        $stmt = $pdo->prepare(
            'INSERT INTO contact
               (civilite, nom, prenom, email, telephone, type_demande, message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $civilite, $nom, $prenom,
            $email, $telephone,
            $type, $messageTxt
        ]);
        $contactId = $pdo->lastInsertId();

        // Insert disponibilités
        if (!empty($_POST['disponibilites']) && is_array($_POST['disponibilites'])) {
            $stmtDispo = $pdo->prepare(
                'INSERT INTO dispo (contact_id, jour, heure, minute)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($_POST['disponibilites'] as $raw) {
                list($jour, $heure, $minute) = explode('|', $raw);
                $stmtDispo->execute([$contactId, $jour, (int)$heure, (int)$minute]);
            }
        }

        $pdo->commit();

        // Envoi des e-mails (modulaire)
        require __DIR__ . '/../src/mailer.php';
        $emailData = [
            'civilite'       => $civilite,
            'nom'            => $nom,
            'prenom'         => $prenom,
            'email'          => $email,
            'telephone'      => $telephone,
            'type'           => $type,
            'messageTxt'     => $messageTxt,
            'disponibilites' => $_POST['disponibilites'] ?? [],
        ];
        sendNotificationEmails($emailData);

        // PRG (Post–Redirect–Get)
        header('Location: /?success=1');
        exit;
    }

    // Affichage du message de succès après redirection
    if (isset($_GET['success']) && $_GET['success'] === '1') {
        $message_envoye = true;
    }

} catch (\Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $erreur_message = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Contactez l’agence</title>
  <link rel="stylesheet" href="style.css">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body>

<?php if ($erreur_message): ?>
  <div class="error-message"><?= htmlspecialchars($erreur_message) ?></div>
<?php endif; ?>

<?php if ($message_envoye): ?>
  <div class="success-message">Votre message a bien été envoyé !</div>
<?php endif; ?>

<div class="contact-container">
  <form method="post" class="contact-form" novalidate>
    <!-- CSRF -->
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <!-- Honeypot -->
    <div class="hp-field">
      <input type="text" name="hp_email" autocomplete="off" tabindex="-1">
    </div>

    <h2>CONTACTEZ L’AGENCE</h2>
    <div class="form-section">
      <!-- Gauche : coordonnées + dispos -->
      <div class="form-left">
        <h3>VOS COORDONNÉES</h3>
        <fieldset class="civilite">
          <legend>Vous êtes</legend>
          <label><input type="radio" name="civilite" value="Mme" required> Mme</label>
          <label><input type="radio" name="civilite" value="M"   required> M.</label>
        </fieldset>

        <div class="row">
          <div class="form-group">
            <label for="nom">Nom</label>
            <input id="nom" type="text" name="nom" placeholder="Nom" required>
          </div>
          <div class="form-group">
            <label for="prenom">Prénom</label>
            <input id="prenom" type="text" name="prenom" placeholder="Prénom" required>
          </div>
        </div>

        <div class="form-group">
          <label for="email">Adresse mail</label>
          <input id="email" type="email" name="email" placeholder="Adresse mail" required>
        </div>

        <div class="form-group">
          <label for="telephone">Téléphone</label>
          <input id="telephone" type="tel" name="telephone" placeholder="Téléphone" required>
        </div>

        <h4>DISPONIBILITÉS POUR UNE VISITE</h4>
        <div class="dispos">
          <label class="visually-hidden" for="jour">Jour</label>
          <select id="jour" name="jour" required>
            <option value="Lundi">Lundi</option>
            <option value="Mardi">Mardi</option>
            <option value="Mercredi">Mercredi</option>
            <option value="Jeudi">Jeudi</option>
            <option value="Vendredi">Vendredi</option>
            <option value="Samedi">Samedi</option>
          </select>

          <label class="visually-hidden" for="heure">Heure</label>
          <select id="heure" name="heure" required>
            <?php for ($h = 7; $h <= 20; $h++): ?>
              <option value="<?= $h ?>"><?= $h ?>h</option>
            <?php endfor; ?>
          </select>

          <label class="visually-hidden" for="minute">Minute</label>
          <select id="minute" name="minute" required>
            <option value="00">00</option>
            <option value="15">15</option>
            <option value="30">30</option>
            <option value="45">45</option>
          </select>

          <button type="button" class="ajouter-dispo">AJOUTER DISPO</button>
        </div>
        <div class="liste-dispos" aria-live="polite"></div>
      </div>

      <!-- Droite : type et message -->
      <div class="form-right">
        <h3>VOTRE MESSAGE</h3>
        <fieldset class="demande">
          <legend>Type de demande</legend>
          <label><input type="radio" name="type_demande" value="Demande de visite" required> Demande de visite</label>
          <label><input type="radio" name="type_demande" value="Être rappelé.e" required> Être rappelé.e</label>
          <label><input type="radio" name="type_demande" value="Plus de photos" required> Plus de photos</label>
        </fieldset>

        <div class="form-group">
          <label for="message">Votre message</label>
          <textarea id="message" name="message" placeholder="Votre message" rows="6" required></textarea>
        </div>

        <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($_ENV['RECAPTCHA_SITEKEY'] ?? '') ?>"></div>
        <button type="submit" class="envoyer-btn">ENVOYER</button>
      </div>
    </div>
  </form>
</div>

<script src="script.js"></script>
</body>
</html>
