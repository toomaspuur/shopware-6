<?php declare(strict_types=1);

namespace WizmoGmbh\IvyPayment\Logger;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;


class IvyLoggerFactory
{
    private string $rotatingFilePathPattern = '';

    private int $defaultFileRotationCount;

    /**
     * @internal
     */
    public function __construct(string $rotatingFilePathPattern, int $defaultFileRotationCount = 14)
    {
        $this->rotatingFilePathPattern = $rotatingFilePathPattern;
        $this->defaultFileRotationCount = $defaultFileRotationCount;
    }

    public function createRotating(
        string $filePrefix,
        ?int $fileRotationCount = null,
        int $loggerLevel = Logger::DEBUG
    ): LoggerInterface {
        $filepath = sprintf($this->rotatingFilePathPattern, $filePrefix);

        $result = new IvyLogger($filePrefix);
        $result->pushHandler(new RotatingFileHandler($filepath, $fileRotationCount ?? $this->defaultFileRotationCount, $loggerLevel));
        $result->pushProcessor(new PsrLogMessageProcessor());

        return $result;
    }
}
