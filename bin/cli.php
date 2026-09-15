<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/** @var Slim\App $app */
$app = require __DIR__ . '/../src/app.php';
$container = $app->getContainer();

$argvInput = $_SERVER['argv'] ?? [];
array_shift($argvInput);
$command = array_shift($argvInput);

$usage = <<<TXT
Usage : php bin/cli.php <commande> [--cle=valeur ...]

Commandes :
  platform:report [--from=YYYY-MM-DD --to=YYYY-MM-DD]  Tire le flux de reporting de la regie (revenus)
  stats:rollup [--date=YYYY-MM-DD]                     Agrege les evenements display et recalcule les eCPM
  leads:verify [--limit=200]                           Verifie email / telephone des participants recents
  gdpr:purge [--dry-run]                               Anonymise selon la duree de retention
  admin:create --email= [--name= --role=]              Cree un compte de back-office (mot de passe demande)
  drawing:run --pending                                 Concours clos en attente de tirage
  drawing:run --sweepstake=<id>                         Tire le finaliste d'un concours clos
  drawing:run --grand-prize [--year=YYYY]               Tire le gagnant de l'annee
  readiness:check                                      Reevalue les reserves d'ouverture (code 1 si bloquant)
  admin:2fa-reset --email=                             Retire la double authentification (telephone perdu)

TXT;

if ($command === null || $command === '--help' || $command === '-h') {
    echo $usage;
    exit($command === null ? 1 : 0);
}

$options = [];
foreach ($argvInput as $arg) {
    if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif (str_starts_with($arg, '--')) {
        $options[substr($arg, 2)] = '1';
    }
}

// Registre des taches : commande => [classe, methode]. Une tache absente du
// registre ne peut pas etre appelee depuis la ligne de commande.
/** @var array<string, array{class-string, string}> $registry */
$registry = [
    'admin:create' => [App\Modules\Admin\Tasks\CreateAdminUserTask::class, 'run'],
    'platform:report' => [App\Modules\Platform\Tasks\PlatformReportTask::class, 'run'],
    'stats:rollup' => [App\Modules\Stats\Tasks\StatsRollupTask::class, 'run'],
    'gdpr:purge' => [App\Modules\Leads\Tasks\GdprPurgeTask::class, 'run'],
    'drawing:run' => [App\Modules\Drawings\Tasks\DrawingTask::class, 'run'],
    'readiness:check' => [App\Modules\Admin\Tasks\ReadinessCheckTask::class, 'run'],
    'admin:2fa-reset' => [App\Modules\Admin\Tasks\ResetTwoFactorTask::class, 'run'],
    // leads:verify attend le choix d'un fournisseur de verification.
];

if (!isset($registry[$command])) {
    fwrite(STDERR, sprintf("Commande inconnue : %s\n\n%s", $command, $usage));
    exit(1);
}

[$class, $method] = $registry[$command];

try {
    $result = $container->get($class)->{$method}($options);
    $ok = is_array($result) ? (bool) ($result['ok'] ?? true) : true;
    $message = is_array($result) ? (string) ($result['message'] ?? '') : (string) $result;
    echo ($ok ? 'OK ' : 'KO ') . $message . "\n";
    exit($ok ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(2);
}
