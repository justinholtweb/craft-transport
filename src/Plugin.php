<?php

namespace justinholtweb\transport;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\transport\models\Settings;
use justinholtweb\transport\services\AssetTransfer;
use justinholtweb\transport\services\DependencyResolver;
use justinholtweb\transport\services\Differ;
use justinholtweb\transport\services\ElementRegistry;
use justinholtweb\transport\services\Export;
use justinholtweb\transport\services\FieldRegistry;
use justinholtweb\transport\services\Import;
use justinholtweb\transport\services\Merger;
use justinholtweb\transport\services\Normalizer;
use justinholtweb\transport\services\PackageManager;
use justinholtweb\transport\services\Serializer;
use justinholtweb\transport\services\Snapshotter;
use justinholtweb\transport\services\ValidationService;
use yii\base\Event;

/**
 * Transport — safely migrate content between Craft environments.
 *
 * @property-read Export $export
 * @property-read Import $import
 * @property-read Serializer $serializer
 * @property-read Normalizer $normalizer
 * @property-read PackageManager $packages
 * @property-read DependencyResolver $dependencies
 * @property-read AssetTransfer $assets
 * @property-read Differ $differ
 * @property-read Merger $merger
 * @property-read Snapshotter $snapshots
 * @property-read ValidationService $validation
 * @property-read FieldRegistry $fieldRegistry
 * @property-read ElementRegistry $elementRegistry
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_EXPORT = 'transport:export';
    public const PERMISSION_IMPORT = 'transport:import';
    public const PERMISSION_ROLLBACK = 'transport:rollback';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'export' => Export::class,
                'import' => Import::class,
                'serializer' => Serializer::class,
                'normalizer' => Normalizer::class,
                'packages' => PackageManager::class,
                'dependencies' => DependencyResolver::class,
                'assets' => AssetTransfer::class,
                'differ' => Differ::class,
                'merger' => Merger::class,
                'snapshots' => Snapshotter::class,
                'validation' => ValidationService::class,
                'fieldRegistry' => FieldRegistry::class,
                'elementRegistry' => ElementRegistry::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerPermissions();
    }

    /**
     * Sends Transport's log entries (category "transport") to a dedicated
     * storage/logs/transport.log target.
     */
    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new \craft\log\MonologTarget([
            'name' => 'transport',
            'categories' => ['transport'],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [];
        if ($user->checkPermission(self::PERMISSION_EXPORT)) {
            $subnav['export'] = ['label' => Craft::t('transport', 'Export'), 'url' => 'transport/export'];
        }
        if ($user->checkPermission(self::PERMISSION_IMPORT)) {
            $subnav['import'] = ['label' => Craft::t('transport', 'Import'), 'url' => 'transport/import'];
        }
        $subnav['history'] = ['label' => Craft::t('transport', 'History'), 'url' => 'transport/history'];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('transport', 'Settings'), 'url' => 'transport/settings'];
        }

        $item['subnav'] = $subnav;
        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('transport/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('transport/settings')
        );
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['transport'] = 'transport/export/index';
                $event->rules['transport/export'] = 'transport/export/index';
                $event->rules['transport/import'] = 'transport/import/upload';
                $event->rules['transport/import/run'] = 'transport/import/run';
                $event->rules['transport/history'] = 'transport/history/index';
                $event->rules['transport/history/<id:\d+>'] = 'transport/history/detail';
                $event->rules['transport/settings'] = 'transport/settings/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('transport', 'Transport'),
                    'permissions' => [
                        self::PERMISSION_EXPORT => [
                            'label' => Craft::t('transport', 'Export content'),
                        ],
                        self::PERMISSION_IMPORT => [
                            'label' => Craft::t('transport', 'Import content'),
                        ],
                        self::PERMISSION_ROLLBACK => [
                            'label' => Craft::t('transport', 'Roll back imports'),
                        ],
                    ],
                ];
            }
        );
    }
}
