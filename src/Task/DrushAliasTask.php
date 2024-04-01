<?php

declare(strict_types = 1);

namespace Aneek\Robo\DrushAlias\Task;

use AcquiaCloudApi\Endpoints\Account;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Environments;
use Robo\Task\BaseTask;
use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Response\ApplicationResponse;
use AcquiaCloudApi\Response\EnvironmentsResponse;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Robo\Exception\TaskException;

/**
 * Class DrushAliasTask
 *
 * This class extends the BaseTask class and represents a task for handling Drush aliases.
 * It provides the functionality to run the task.
 */
class DrushAliasTask extends BaseTask
{
    const DOC_ROOT_PREFIX = '/var/www/html';

    const DOC_ROOT_SUFFIX = 'docroot';

    protected ?string $clientKey = null;

    protected ?string $clientSecret = null;

    protected ?string $uuid = null;

    protected ?string $aliasPath = null;

    /**
     * Connector instance.
     *
     * @var \AcquiaCloudApi\Connector\Connector $connector
     */
    protected Connector $connector;

    /**
     * Acquia connector client instance.
     *
     * @var \AcquiaCloudApi\Connector\Client $client
     */
    protected Client $client;

    /**
     * The API endpoint application instance.
     *
     * @var \AcquiaCloudApi\Endpoints\Applications
     */
    protected Applications $applicationInstance;

    /**
     * The API endpoint environment instance.
     *
     * @var \AcquiaCloudApi\Endpoints\Environments
     */
    protected Environments $environmentInstance;

    protected ApplicationResponse $application;

    protected EnvironmentsResponse $environments;

    protected Account $account;

    protected Filesystem $filesystem;

    public function __construct(iterable $config = [])
    {
        // Initialize the variables.
        $this->clientKey = $config['client_key'] ?? null;
        $this->clientSecret = $config['client_secret'] ?? null;
        $this->uuid = $config['application_uuid'] ?? null;
        $this->aliasPath = $config['alias_path'] ?? null;

        // Initialize required elements.
        $this->setConnector($config);
        $this->setClient($this->connector);
        $this->setAccount($this->client);
        $this->setApplicationInstance($this->client);
        $this->setEnvironmentInstance($this->client);
        $this->filesystem = new Filesystem();
    }

    /**
     * {@inheritDoc}
     */
    public function run(): void
    {
        $this->generateSiteAliases();
    }

    /**
     * Generate all the Drush site aliases and save them in a directory.
     *
     * @return void
     */
    public function generateSiteAliases(): void
    {
        // First get the application and then load environments.
        $this->getApplication();
        $this->getEnvironments();
        // If environments available, generate the files.
        if (!empty($this->environments)) {
            $this->getAliasesFilesDump($this->environments, $this->application);
        }
    }

    /**
     * Sets the connector with the provided config.
     *
     * @param array $config
     *   The configuration array for the connector.
     *
     * @return void
     */
    protected function setConnector(array $config): void
    {
        $this->connector = new Connector($config);
    }

    /**
     * Returns the connector object.
     *
     * @return Connector
     *   The connector object used by the application.
     */
    public function getConnector(): Connector
    {
        return $this->connector;
    }

    /**
     * Sets the client with the provided connector.
     *
     * @param Connector $connector
     *   The connector object to be used by the client.
     *
     * @return void
     */
    protected function setClient(Connector $connector): void
    {
        $this->client = Client::factory($connector);
    }

    /**
     * Gets the client.
     *
     * @return Client
     *   The client object.
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    protected function setApplicationInstance(Client $client): void
    {
        $this->applicationInstance = new Applications($client);
    }

    public function getApplicationInstance(): Applications
    {
        return $this->applicationInstance;
    }

    protected function setEnvironmentInstance(Client $client): void
    {
        $this->environmentInstance = new Environments($client);
    }

    public function getEnvironmentInstance(): Environments
    {
        return $this->environmentInstance;
    }

    protected function setAccount(Client $client): void
    {
        $this->account = new Account($this->client);
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    protected function getApplication(): void
    {
        $this->application = $this->applicationInstance->get($this->uuid);
    }

    public function getApplicationResponse(): ApplicationResponse
    {
        return $this->application;
    }

    protected function getEnvironments(): void
    {
        $this->environments = $this->environmentInstance->getAll($this->application->uuid);
    }


    public function getEnvironmentsResponse(): EnvironmentsResponse
    {
        return $this->environments;
    }

    /**
     * Returns the hosting type of the provided application.
     *
     * @param ApplicationResponse $application
     *   The application response object.
     *
     * @return string|null
     *   The hosting type of the application, or null if the hosting type is not available.
     */
    public function getHostingType(ApplicationResponse $application):? string
    {
        return $application->hosting->type;
    }

    /**
     * @throws TaskException
     */
    protected function checkDrushAliasDirectory(): bool
    {
        if (!$this->filesystem->exists($this->aliasPath)) {
            throw new TaskException($this, 'Drush alias directory does not exist. Please create the directory.');
        }
        return true;
    }


    protected function getAliasesFilesDump(
        EnvironmentsResponse $environments,
        ApplicationResponse $application,
    ): void
    {
        try {
            if ($this->checkDrushAliasDirectory() === true) {
                $sites = [];
                $hosting = $application->hosting->type;
                $site_split = explode(':', $application->hosting->id);
                foreach ($environments as $environment) {
                    $domains = $environment->domains;
                    $ssh_split = explode('@', $environment->sshUrl);
                    $envName = $environment->name;
                    $remoteHost = $ssh_split[1];
                    $remoteUser = $ssh_split[0];
                    if (in_array($hosting, ['ace', 'acp'])) {
                        // This is a generic Acquia Cloud application.
                        $siteID = $site_split[1];
                        $uri = $environment->domains[0];
                        $sites[$siteID][$envName] = ['uri' => $uri];
                        $siteAlias = $this->createAliasArray($uri, $envName, $remoteHost, $remoteUser);
                        $sites[$siteID][$envName] = $siteAlias[$envName];
                    }
                    if ($hosting == 'acsf') {
                        // This is an Acquia Cloud Site factory site stack.
                        // Here we will get many domains.
                        foreach ($domains as $domain) {
                            // Do not include wild card domains.
                            if (!str_contains($domain, '*.')) {
                                $domain_split =  explode('.', $domain);
                                $siteID = $domain_split[0];
                                $uri = $domain;
                                $sites[$siteID][$envName] = ['uri' => $uri];
                                $siteAlias = $this->createAliasArray($uri, $envName, $remoteHost, $remoteUser);
                                if (isset($siteAlias[$envName])) {
                                    $sites[$siteID][$envName] = $siteAlias[$envName];
                                }
                            }
                        }
                    }
                }
                if (!empty($sites)) {
                    foreach ($sites as $id => $aliases) {
                        $this->dumpSiteAliasFile($id, $aliases);
                    }
                }
            }
        } catch (TaskException $e) {
            $this->printTaskError($e->getMessage());
        }
    }

    /**
     * Creates an alias array based on the provided parameters.
     *
     * @param string $uri
     *   The URI string for the alias.
     * @param string $envName
     *   The name of the environment.
     * @param string $remoteHost
     *   The remote host for the alias.
     * @param string $remoteUser
     *   The remote user for the alias.
     *
     * @return array
     *   The generated alias array. If $skip is true, an empty array will be returned.
     */
    protected function createAliasArray(
        string $uri,
        string $envName,
        string $remoteHost,
        string $remoteUser
    ) : array {
        $alias = [];
        // We do not need the wild card domains which starts with '*'.
        $skip = false;
        if (str_contains($uri, ':*') || str_contains($uri, '*.')) {
            $skip = true;
        }
        if (false === $skip) {
            // Setup Acquia document root and other parameters.
            $documentRoot = sprintf("%s/%s/%s", self::DOC_ROOT_PREFIX, $remoteUser, self::DOC_ROOT_SUFFIX);
            $alias[$envName]['uri'] = $uri;
            $alias[$envName]['host'] = $remoteHost;
            $alias[$envName]['options'] = [];
            $alias[$envName]['paths'] = ['dump-dir' => '/mnt/tmp'];
            $alias[$envName]['root'] = $documentRoot;
            $alias[$envName]['user'] = $remoteUser;
            $alias[$envName]['ssh'] = ['options' => '-p 22'];
            return $alias;
        }
        return $alias;
    }


    protected function dumpSiteAliasFile(string $id, array $aliases): void
    {
        try {
            $filePath = $this->aliasPath . '/' . $id . '.site.yml';
            $this->filesystem->touch($filePath);
            $this->filesystem->dumpFile($filePath, Yaml::dump($aliases));
        } catch (IOExceptionInterface $e) {
            $this->printTaskError($e->getMessage());
        }

    }
}