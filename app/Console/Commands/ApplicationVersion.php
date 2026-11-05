<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ApplicationVersion extends Command
{
    protected $signature = 'app:version {--bump : Préparer la prochaine version du mois courant}';

    protected $description = 'Afficher ou incrémenter la version de myABI Translate (AAAA.MM.compteur)';

    public function handle(): int
    {
        if (! $this->option('bump')) {
            $this->line(config('version.number'));

            return self::SUCCESS;
        }

        // Read the tracked source even when Laravel's configuration is cached.
        $path = config_path('version.php');
        $current = (require $path)['number'] ?? null;
        if (! is_string($current) || ! preg_match('/\A([1-9][0-9]{3}\.(?:0[1-9]|1[0-2]))\.([1-9][0-9]*)\z/', $current, $parts)) {
            $this->error('Version invalide dans config/version.php : format attendu AAAA.MM.compteur (à partir de 1).');

            return self::FAILURE;
        }

        $month = now('Europe/Zurich')->format('Y.m');
        if ($parts[1] > $month) {
            $this->error('La version enregistrée est postérieure au mois courant ; aucune modification.');

            return self::FAILURE;
        }

        $counter = filter_var($parts[2], FILTER_VALIDATE_INT);
        if ($counter === false || $counter === PHP_INT_MAX) {
            $this->error('Le compteur de version est trop grand ; aucune modification.');

            return self::FAILURE;
        }

        $next = $month.'.'.($parts[1] === $month ? $counter + 1 : 1);
        File::replace($path, "<?php\n\nreturn [\n    'number' => '$next',\n];\n");
        $this->callSilent('config:clear');
        config(['version.number' => $next]);
        $this->line($next);

        return self::SUCCESS;
    }
}
