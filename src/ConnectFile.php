<?php

declare(strict_types=1);

namespace Shroud\Spip;

use Shroud\Adapter\Pdo\ConnectionSettings;
use Shroud\Exception\InvalidConfigurationException;

/**
 * Lit les paramètres de connexion d'un site SPIP dans son `config/connect.php`, sans l'exécuter.
 *
 * Le fichier ne contient qu'un appel à `spip_connect_db(hôte, port, utilisateur, mot de passe,
 * base, type, préfixe, …)` ; l'analyseur lexical de PHP en livre les chaînes, quelle que soit
 * leur mise en forme.
 */
final readonly class ConnectFile
{
    public function __construct(
        public string $host,
        public string $port,
        public string $user,
        public string $password,
        public string $database,
        public string $type,
        public string $prefix,
    ) {}

    /**
     * Lit le fichier de connexion d'un site et rend ses paramètres.
     */
    public static function read(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidConfigurationException(sprintf('fichier de connexion introuvable : %s', $path));
        }
        $arguments = self::callArguments((string) file_get_contents($path));
        if (count($arguments) < 6) {
            throw new InvalidConfigurationException(sprintf('%s ne contient pas d\'appel lisible à spip_connect_db()', $path));
        }

        return new self(
            host: $arguments[0],
            port: $arguments[1],
            user: $arguments[2],
            password: $arguments[3],
            database: $arguments[4],
            type: $arguments[5],
            prefix: $arguments[6] ?? 'spip',
        );
    }

    /**
     * Rend les paramètres de connexion du noyau ; seul MySQL, et ses dérivés, est pris en charge.
     */
    public function settings(): ConnectionSettings
    {
        if (! in_array($this->type, ['mysql', 'mysqli'], strict: true)) {
            throw new InvalidConfigurationException(sprintf('type de base non pris en charge : %s', $this->type));
        }

        return new ConnectionSettings($this->host, $this->database, $this->user, $this->password, $this->port);
    }

    /**
     * Extrait, dans l'ordre, les chaînes littérales passées à `spip_connect_db()`.
     *
     * @return list<string>
     */
    private static function callArguments(string $source): array
    {
        $arguments = [];
        $inCall = false;
        foreach (token_get_all($source) as $token) {
            if (! $inCall) {
                $inCall = is_array($token) && $token[0] === T_STRING && $token[1] === 'spip_connect_db';
                continue;
            }
            if ($token === ')') {
                break;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $arguments[] = self::unquote($token[1]);
            }
        }

        return $arguments;
    }

    /**
     * Rend la valeur d'un littéral de chaîne PHP, entre apostrophes ou guillemets.
     */
    private static function unquote(string $literal): string
    {
        $inner = substr($literal, 1, -1);
        if (str_starts_with($literal, "'")) {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
    }
}
