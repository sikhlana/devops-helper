<?php

namespace App\Commands\Database;

use PDO;
use Exception;
use UnexpectedValueException;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;

class Create extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'db:create';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Creates a MySQL database and an associated user with the given name';

    /**
     * Execute the console command.
     *
     * @param ExceptionHandler $exceptionHandler
     * @return mixed
     */
    public function handle(ExceptionHandler $exceptionHandler)
    {
        $hostname = $this->ask('What is the hostname of the database server?', 'localhost');
        $port = intval($this->ask('What is the port of the database server?', '3306'));

        if (empty($password = $this->secret('What is the password of the root user?'))) {
            $this->error('You didn\'t enter the password of the root user 😔');

            return 1;
        }

        try {
            $connection = null;

            try {
                $this->task('Connection to the database server', function () use (&$connection, $hostname, $port, $password) {
                    $connection = new PDO("mysql:host={$hostname};port={$port}", 'root', $password, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                });
            } catch (Exception $e) {
                throw new UnexpectedValueException('Unable to connect to the database server.', 0, $e);
            }

            $database = $this->ask('What is the name of the database?');
            $charset = $this->ask('What should be the charset of the database?', 'utf8mb4');
            $collation = $this->ask('What should be the collation of the database?', 'utf8mb4_unicode_ci');

            $this->task('Creating the database', function () use ($connection, $database, $charset, $collation) {
                $stmt = $connection->prepare("CREATE DATABASE `{$database}` CHARACTER SET :charset COLLATE :collation");

                $stmt->execute([
                    ':charset' => $charset,
                    ':collation' => $collation,
                ]);
            });

            $hostname = $this->ask('What is the hostname from where the user will access the database?', 'localhost');

            if (empty($password = $this->secret("What should be the password for the user <comment>{$database}</comment>?"))) {
                $this->error('You didn\'t enter a password 😔');

                return 1;
            }

            $this->task('Creating the user', function () use ($connection, $database, $hostname, $password) {
                $stmt = $connection->prepare("CREATE USER '{$database}'@'{$hostname}' IDENTIFIED WITH mysql_native_password BY :password");

                $stmt->execute([
                    ':password' => $password,
                ]);
            });

            $this->task('Granting user privileges', function () use ($connection, $database, $hostname) {
                $stmt = $connection->prepare("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$database}'@'{$hostname}'");
                $stmt->execute();
            });

            $this->task('Flushing all privileges', function () use ($connection) {
                $stmt = $connection->prepare('FLUSH PRIVILEGES');
                $stmt->execute();
            });

            $this->info('Done! 😊');
        } catch (Exception $e) {
            $exceptionHandler->renderForConsole($this->getOutput(), $e);
        }

        return 0;
    }
}
