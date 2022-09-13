<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Logger;

use Monolog\Logger;

class IvyLogger extends Logger
{
    private int $logLevel;

    /**
     * @param int $logLevel
     * @return void
     */
    public function setLevel(int $logLevel): void
    {
        $this->logLevel = $logLevel;
    }

    /**
     * @param $level
     * @param $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        if ($level < $this->logLevel) {
            return;
        }
        parent::log($level, $message, $context);
    }

}
