<?php
namespace App\Traits;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

trait LoggingTrait
{
    protected $logger;

    public function initializeLogger($name)
    {
        $this->logger = new Logger($name);
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/' . $name . '-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function logInfo($message, array $context = [])
    {
        $this->logger->info($message, $context);
    }
}