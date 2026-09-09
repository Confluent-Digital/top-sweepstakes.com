<?php

declare(strict_types=1);

namespace App\Core;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class Database
{
    private ?Connection $connection = null;

    public function __construct(private Config $config)
    {
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            $this->connection = DriverManager::getConnection([
                'driver' => 'pdo_mysql',
                'host' => $this->config->get('DB_HOST', 'topsweepstakes_mariadb'),
                'port' => $this->config->int('DB_PORT', 3306),
                'user' => $this->config->get('DB_USERNAME'),
                'password' => $this->config->get('DB_PASSWORD'),
                'dbname' => $this->config->get('DB_NAME'),
                'charset' => $this->config->get('DB_CHARSET', 'utf8mb4'),
            ]);
        }
        return $this->connection;
    }
}
