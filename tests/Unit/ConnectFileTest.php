<?php

declare(strict_types=1);

namespace Shroud\Spip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shroud\Exception\InvalidConfigurationException;
use Shroud\Spip\ConnectFile;

final class ConnectFileTest extends TestCase
{
    public function testTheConnectionFileIsReadWithoutBeingExecuted(): void
    {
        $connection = ConnectFile::read(__DIR__ . '/../fixtures/connect.php');

        self::assertSame('db', $connection->host);
        self::assertSame('3307', $connection->port);
        self::assertSame('utilisateur', $connection->user);
        self::assertSame('mot de \'passe\' "à" espaces', $connection->password);
        self::assertSame('la_base', $connection->database);
        self::assertSame('prefixe', $connection->prefix);
        self::assertSame('mysql:host=db;dbname=la_base;charset=utf8mb4;port=3307', $connection->settings()->dsn());
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('fichier de connexion introuvable');

        ConnectFile::read(__DIR__ . '/../fixtures/absent.php');
    }

    public function testAFileWithoutConnectionCallIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('spip_connect_db()');

        ConnectFile::read(__FILE__);
    }

    public function testSqliteIsNotSupported(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('type de base non pris en charge : sqlite3');

        new ConnectFile('', '', '', '', 'site', 'sqlite3', 'spip')->settings();
    }
}
