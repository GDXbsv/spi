<?php declare(strict_types=1);
namespace Nevay\SPI\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Nevay\SPI\ServiceLoader;
use function array_fill_keys;
use function array_unique;
use function class_exists;
use function implode;
use function preg_match;
use function sprintf;

final class Plugin implements PluginInterface, EventSubscriberInterface {

    private const FQCN_REGEX = '/^\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/';

    public static function getSubscribedEvents(): array {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'preAutoloadDump',
        ];
    }

    public function activate(Composer $composer, IOInterface $io): void {
        // no-op
    }

    public function deactivate(Composer $composer, IOInterface $io): void {
        // no-op
    }

    public function uninstall(Composer $composer, IOInterface $io): void {
        $filesystem = new Filesystem();
        $vendorDir = $this->vendorDir($composer, $filesystem);
        $filesystem->remove($vendorDir . '/composer/GeneratedServiceProviderData.php');
    }

    public function preAutoloadDump(Event $event): void {
        $package = $event->getComposer()->getPackage();
        $packages = $event->getComposer()->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $packageMap = $event->getComposer()->getAutoloadGenerator()->buildPackageMap($event->getComposer()->getInstallationManager(), $package, $packages);
        $map = $event->getComposer()->getAutoloadGenerator()->parseAutoloads($packageMap, $package);
        $loader = $event->getComposer()->getAutoloadGenerator()->createLoader($map, $event->getComposer()->getConfig()->get('vendor-dir'));
        $loader->register();

        try {
            $this->dumpGeneratedServiceProviderData($event);
        } finally {
            $loader->unregister();
        }
    }

    private function dumpGeneratedServiceProviderData(Event $event): void {
        $match = '';
        foreach ($this->serviceProviders($event->getComposer()) as $service => $providers) {
            if (!preg_match(self::FQCN_REGEX, $service)) {
                $event->getIO()->warning(sprintf('Invalid extra.spi configuration, expected class name, got "%s" (%s)', $service, implode(', ', array_unique($providers))));
                continue;
            }
            if (!ServiceLoader::serviceAvailable($service)) {
                $event->getIO()->info(sprintf('Skipping extra.spi service "%s", service not available (%s)', $service, implode(', ', array_unique($providers))));
                continue;
            }

            if ($service[0] !== '\\') {
                $service = '\\' . $service;
            }

            $match .= "\n            $service::class => [";
            foreach ($providers as $provider => $package) {
                if (!preg_match(self::FQCN_REGEX, $provider)) {
                    $event->getIO()->warning(sprintf('Invalid extra.spi configuration, expected class name, got "%s" for "%s" (%s)', $provider, $service, $package));
                    continue;
                }
                if (!class_exists($provider)) {
                    $event->getIO()->info(sprintf('Skipping extra.spi configuration, provider class "%s" for "%s" does not exist (%s)', $provider, $service, $package));
                    continue;
                }
                if (!ServiceLoader::providerAvailable($provider)) {
                    $event->getIO()->info(sprintf('Skipping extra.spi provider "%s" for "%s", provider not available (%s)', $provider, $service, $package));
                    continue;
                }

                if ($provider[0] !== '\\') {
                    $provider = '\\' . $provider;
                }

                $match .= "\n                $provider::class, // $package";
            }
            $match .= "\n            ],";
        }
        $code = <<<PHP
            <?php declare(strict_types=1);
            namespace Nevay\SPI;
            
            /**
             * @internal 
             */
            final class GeneratedServiceProviderData {
            
                public const VERSION = 1;
            
                /**
                 * @param class-string \$service
                 * @return list<class-string>
                 */
                public static function providers(string \$service): array {
                    return match (\$service) {
                        default => [],$match
                    };
                }
            }
            PHP;

        $filesystem = new Filesystem();
        $vendorDir = $this->vendorDir($event->getComposer(), $filesystem);
        $filesystem->ensureDirectoryExists($vendorDir . '/composer');
        $filesystem->filePutContentsIfModified($vendorDir . '/composer/GeneratedServiceProviderData.php', $code);

        $package = $event->getComposer()->getPackage();
        $autoload = $package->getAutoload();
        $autoload['classmap'][] = $vendorDir . '/composer/GeneratedServiceProviderData.php';
        $package->setAutoload($autoload);
    }

    private function vendorDir(Composer $composer, Filesystem $filesystem): string {
        return $filesystem->normalizePath($composer->getConfig()->get('vendor-dir'));
    }

    /**
     * @return array<class-string, array<class-string, string>>
     */
    private function serviceProviders(Composer $composer): array {
        $mappings = [];
        foreach ($composer->getPackage()->getExtra()['spi'] ?? [] as $service => $providers) {
            $mappings[$service] ??= [];
            $mappings[$service] += array_fill_keys($providers, $composer->getPackage()->getPrettyString());
        }
        foreach ($composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            foreach ($package->getExtra()['spi'] ?? [] as $service => $providers) {
                $providers = (array) $providers;
                $mappings[$service] ??= [];
                $mappings[$service] += array_fill_keys($providers, $package->getPrettyString());
            }
        }

        return $mappings;
    }
}
