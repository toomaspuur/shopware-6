<?php

declare(strict_types=1);

/*
 * (c) WIZMO GmbH <plugins@shopentwickler.berlin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WizmoGmbh\IvyPayment\Components\Config;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Monolog\Logger;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigHandler
{
    private const FULL_PLUGIN_CONFIG = 'WizmoGmbhIvyPayment.config';
    public const MCC_DEFAULT = '5712';
    public const PROD_API_URL = 'https://api.stage.getivy.de/api/service/';
    public const SAND_API_URL = 'https://api.dev.getivy.de/api/service/';
    public const PROD_BANNER_URL = 'https://cdn.stage.getivy.de/banner.js';
    public const SAND_BANNER_URL = 'https://cdn.dev.getivy.de/banner.js';
    public const PROD_BUTTON_URL = 'https://cdn.stage.getivy.de/button.js';
    public const SAND_BUTTON_URL = 'https://cdn.dev.getivy.de/button.js';


    private ?array $config;

    private SystemConfigService $configService;

    private Connection $connection;

    /*
    <input-field type="text">
    <name>ProductionIvyApiUrl</name>
    <label>Ivy Api Url</label>
    <label lang="de-DE">Ivy Api Url</label>
    <disabled>true</disabled>
    <defaultValue>https://api.getivy.de/api/service/</defaultValue>
    </input-field>
    <input-field>
    <name>SandboxIvyApiUrl</name>
    <label>Ivy Api Sandbox Url</label>
    <label lang="de-DE">Ivy Api Sandbox Url</label>
    <disabled>true</disabled>
    <defaultValue>https://api.sand.getivy.de/api/service/</defaultValue>
    </input-field>
    <input-field>
    <name>ProductionIvyBannerUrl</name>
    <label>Ivy Banner Url</label>
    <label lang="de-DE">Ivy Banner Url</label>
    <disabled>true</disabled>
    <defaultValue>https://cdn.getivy.de/banner.js</defaultValue>
    </input-field>
    <input-field>
    <name>SandboxIvyBannerUrl</name>
    <label>Ivy Banner Sandbox Url</label>
    <label lang="de-DE">Ivy Banner Sandbox Url</label>
    <disabled>true</disabled>
    <defaultValue>https://cdn.sand.getivy.de/banner.js</defaultValue>
    </input-field>
    <input-field type="text">
    <name>ProductionIvyButtonUrl</name>
    <label>Ivy Button Url</label>
    <label lang="de-DE">Ivy Button Url</label>
    <disabled>true</disabled>
    <defaultValue>https://cdn.getivy.de/button.js</defaultValue>
    </input-field>
    <input-field type="text">
    <name>SandboxIvyButtonUrl</name>
    <label>Ivy Button Sandbox Url</label>
    <label lang="de-DE">Ivy Button Sandbox Url</label>
    <disabled>true</disabled>
    <defaultValue>https://cdn.sand.getivy.de/button.js</defaultValue>
    </input-field>
    */
    public function __construct(SystemConfigService $configService, Connection $connection)
    {
        $this->configService = $configService;
        $this->connection = $connection;
    }

    /**
     * @param SalesChannelContext $context
     * @return array
     * @throws Exception
     */
    public function getFullConfig(SalesChannelContext $context): array
    {
        $salesChannelId = $context->getSalesChannel()->getId();
        return $this->getFullConfigBySalesChannelId($salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @param bool|null $isSandbox
     * @param bool $force
     * @return array
     * @throws Exception
     */
    public function getFullConfigBySalesChannelId(?string $salesChannelId, ?bool $isSandbox = null, bool $force = false): array
    {
        if ($force === false && isset($this->config[$salesChannelId])) {
            return $this->config[$salesChannelId];
        }

        $this->config[$salesChannelId] = $this->configService->get(self::FULL_PLUGIN_CONFIG, $salesChannelId);

        $this->config[$salesChannelId]['privacyPage'] = $this->configService->get('core.basicInformation.privacyPage', $salesChannelId);
        $this->config[$salesChannelId]['tosPage'] = $this->configService->get('core.basicInformation.tosPage', $salesChannelId);

        if (\is_array($this->config[$salesChannelId]) === false) {
            throw new InvalidConfigurationException('Cannot read ' . self::FULL_PLUGIN_CONFIG);
        }

        $this->config[$salesChannelId]['IvyServiceUrl'] = self::PROD_API_URL;
        $this->config[$salesChannelId]['IvyBannerUrl'] = self::PROD_BANNER_URL;
        $this->config[$salesChannelId]['IvyButtonUrl'] = self::PROD_BUTTON_URL;
        $this->config[$salesChannelId]['IvyApiKey'] = $this->config[$salesChannelId]['ProductionIvyApiKey'] ?? '';
        $this->config[$salesChannelId]['IvyWebhookSecret'] = $this->config[$salesChannelId]['ProductionIvyWebhookSecret'] ?? '';

        if ($isSandbox === null) {
            $isSandbox = $this->config[$salesChannelId]['isSandboxActive'];
        }

        if ($isSandbox) {
            $this->config[$salesChannelId]['IvyServiceUrl'] = self::SAND_API_URL;
            $this->config[$salesChannelId]['IvyBannerUrl'] = self::SAND_BANNER_URL;
            $this->config[$salesChannelId]['IvyButtonUrl'] = self::SAND_BUTTON_URL;
            $this->config[$salesChannelId]['IvyApiKey'] = $this->config[$salesChannelId]['SandboxIvyApiKey'] ?? '';
            $this->config[$salesChannelId]['IvyWebhookSecret'] = $this->config[$salesChannelId]['SandboxIvyWebhookSecret'] ?? '';
        }

        $this->config[$salesChannelId]['IvyMcc'] = self::MCC_DEFAULT;
        $notSpecifySalutationId = $this->connection
            ->fetchOne("SELECT LOWER(HEX(s.id)) FROM salutation s WHERE s.salutation_key = 'not_specified'");
        if (!$notSpecifySalutationId) {
            $notSpecifySalutationId = $this->connection
                ->fetchOne("SELECT LOWER(HEX(s.id)) FROM salutation s LIMIT 1");
        }

        $this->config[$salesChannelId]['defaultSalutation'] = $notSpecifySalutationId;
        return $this->config[$salesChannelId];
    }

    /**
     * @param SalesChannelContext $context
     * @return int
     */
    public function getLogLevel(SalesChannelContext $context): int
    {
        return $this->configService->getInt(self::FULL_PLUGIN_CONFIG . '.logLevel', $context->getSalesChannelId()) ?? Logger::INFO;
    }
}