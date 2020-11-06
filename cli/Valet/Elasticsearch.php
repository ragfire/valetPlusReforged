<?php

namespace Valet;

use DomainException;

class Elasticsearch
{
    const NGINX_CONFIGURATION_STUB = __DIR__ . '/../stubs/elasticsearch.conf';
    const NGINX_CONFIGURATION_PATH = '/usr/local/etc/nginx/valet/elasticsearch.conf';

    const ES_CONFIG_YAML = '/usr/local/etc/elasticsearch/elasticsearch.yml';
    const ES_CONFIG_DATA_PATH = 'path.data';
    const ES_CONFIG_DATA_BASEPATH = '/usr/local/var/';

    const ES_FORMULA_NAME = 'elasticsearch';
    const ES_V68_VERSION = '6.8';
    const ES_V78_VERSION = '7.8';
    const ES_DEFAULT_VERSION = self::ES_V68_VERSION;

    const SUPPORTED_ES_FORMULAE = [
        self::ES_V68_VERSION => self::ES_FORMULA_NAME,
        self::ES_V78_VERSION => self::ES_FORMULA_NAME . '@' . self::ES_V78_VERSION,
    ];

    public $brew;
    public $cli;
    public $files;
    public $configuration;
    public $site;
    public $phpFpm;

    /**
     * Elasticsearch constructor.
     *
     * @param Brew $brew
     * @param CommandLine $cli
     * @param Filesystem $files
     * @param Configuration $configuration
     * @param Site $site
     * @param PhpFpm $phpFpm
     */
    public function __construct(
        Brew $brew,
        CommandLine $cli,
        Filesystem $files,
        Configuration $configuration,
        Site $site,
        PhpFpm $phpFpm
    ) {
        $this->cli = $cli;
        $this->brew = $brew;
        $this->site = $site;
        $this->files = $files;
        $this->configuration = $configuration;
        $this->phpFpm = $phpFpm;
    }

    /**
     * Install the service.
     *
     * @param string $version
     *
     * @return void
     */
    public function install(string $version = self::ES_DEFAULT_VERSION): void
    {
        if (!$this->files->exists(self::NGINX_CONFIGURATION_PATH)) {
            $domain = $this->configuration->read()['domain'];

            $this->files->putAsUser(
                self::NGINX_CONFIGURATION_PATH,
                str_replace(
                    ['VALET_DOMAIN'],
                    [$domain],
                    $this->files->get(self::NGINX_CONFIGURATION_STUB)
                )
            );
        }

        if (!array_key_exists($version, self::SUPPORTED_ES_FORMULAE)) {
            warning('The Elasticsearch version you\'re installing is not supported.');

            return;
        }

        if ($this->installed($version)) {
            info('[' . self::SUPPORTED_ES_FORMULAE[$version] . '] already installed');

            return;
        }

        // Install dependencies
        $this->cli->quietlyAsUser('brew cask install java');
        $this->cli->quietlyAsUser('brew cask install homebrew/cask-versions/adoptopenjdk8');
        $this->brew->installOrFail('libyaml');
        $this->brew->installOrFail(self::SUPPORTED_ES_FORMULAE[$version]);
        $this->restart($version);
    }

    /**
     * Returns wether Elasticsearch is installed.
     *
     * @param string $version
     *
     * @return bool
     */
    public function installed(string $version = null): bool
    {
        $versions = ($version ? [$version] : array_keys(self::SUPPORTED_ES_FORMULAE));
        foreach ($versions as $version) {
            if ($this->brew->installed(self::SUPPORTED_ES_FORMULAE[$version])) {
                return $version;
            }
        }

        return false;
    }

    /**
     * Restart the service.
     *
     * @param string $version
     *
     * @return void
     */
    public function restart(?string $version = null): void
    {
        $version = $version ?: $this->getCurrentVersion();
        if (!$this->installed($version) || (string)$version === '') {
            return;
        }

        if ($version = null) {
            return;
        }

        if (array_key_exists($version, self::SUPPORTED_ES_FORMULAE) === false) {
            warning(sprintf('No assigned Elastic Version found for: "%s"', $version));

            return;
        }

        info('[' . self::SUPPORTED_ES_FORMULAE[$version] . '] Restarting');
        $this->cli->quietlyAsUser('brew services restart ' . self::SUPPORTED_ES_FORMULAE[$version]);
    }

    /**
     * Returns the current running version.
     *
     * @return string
     */
    public function getCurrentVersion()
    {
        $currentVersion = false;
        foreach (self::SUPPORTED_ES_FORMULAE as $version => $formula) {
            if ($this->brew->isStartedService($formula)) {
                $currentVersion = $version;
            }
        }

        return $currentVersion;
    }

    /**
     * Prepare for uninstallation.
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->stop();
    }

    /**
     * Stop the service.
     *
     * @param string $version
     *
     * @return void
     */
    public function stop(?string $version = null): void
    {
        $version = ($version ?: $this->getCurrentVersion());
        if (!$this->installed($version)) {
            return;
        }

        if ($version = null) {
            return;
        }

        if (array_key_exists($version, self::SUPPORTED_ES_FORMULAE) === false) {
            warning(sprintf('No assigned Elastic Version found for: "%s"', $version));

            return;
        }

        info('[' . self::SUPPORTED_ES_FORMULAE[$version] . '] Stopping');
        $this->cli->quietly('sudo brew services stop ' . self::SUPPORTED_ES_FORMULAE[$version]);
        $this->cli->quietlyAsUser('brew services stop ' . self::SUPPORTED_ES_FORMULAE[$version]);
    }

    /**
     * @param $domain
     */
    public function updateDomain(string $domain): void
    {
        if ($this->files->exists(self::NGINX_CONFIGURATION_PATH)) {
            $this->files->putAsUser(
                self::NGINX_CONFIGURATION_PATH,
                str_replace(
                    ['VALET_DOMAIN'],
                    [$domain],
                    $this->files->get(self::NGINX_CONFIGURATION_PATH)
                )
            );
        }
    }

    /**
     * Switch between versions of installed Elasticsearch. Switch to the provided version.
     *
     * @param string $version
     */
    public function switchTo(string $version): void
    {
        $currentVersion = $this->getCurrentVersion();

        if (!array_key_exists($version, self::SUPPORTED_ES_FORMULAE)) {
            throw new DomainException('This version of Elasticsearch is not supported. The following versions are supported: ' . implode(', ', array_keys(self::SUPPORTED_ES_FORMULAE)) . ($currentVersion ? "\nCurrent version is " . $currentVersion : ''));
        }

        // If the current version equals that of the current PHP version, do not switch.
        if ($version === $currentVersion) {
            info('Already on this version');

            return;
        }

        // Make sure the requested version is installed.
        $installed = $this->brew->installed(self::SUPPORTED_ES_FORMULAE[$version]);
        if (!$installed) {
            $this->brew->ensureInstalled(self::SUPPORTED_ES_FORMULAE[$version]);
        }

        // Stop all versions.
        $this->stop($currentVersion);

        // Alter elasticsearch data path in config yaml.
        if (extension_loaded('yaml')) {
            $config = yaml_parse_file(self::ES_CONFIG_YAML);
            $config[self::ES_CONFIG_DATA_PATH] = self::ES_CONFIG_DATA_BASEPATH . self::ES_FORMULA_NAME . '@' . $version . '/';
            yaml_emit_file(self::ES_CONFIG_YAML, $config);
        } else {
            // Install PHP dependencies through installation of PHP.
            $this->phpFpm->install();
            warning('Switching Elasticsearch requires PECL extension yaml. Try switching again.');

            return;
        }

        // Start requested version.
        $this->restart($version);

        info('Valet is now using ' . self::SUPPORTED_ES_FORMULAE[$version] . '. You might need to reindex your data.');
    }
}
