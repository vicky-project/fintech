<?php

namespace Modules\FinTech\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schedule;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Modules\FinTech\Services\BankParserManager;
use Modules\FinTech\Services\Parsers\MandiriPdfParser;
use Modules\FinTech\Services\Parsers\MandiriExcelParser;
use Modules\FinTech\Services\Parsers\MandiriCsvParser;
use Modules\FinTech\Services\Parsers\BniPdfParser;
use Modules\FinTech\Services\Parsers\BriPdfParser;
use Modules\FinTech\Services\Google\GoogleSheetsClient;
use Modules\FinTech\Services\Google\Writers;

class FinTechServiceProvider extends ServiceProvider
{
  use PathNamespace;

  protected string $name = 'FinTech';

  protected string $nameLower = 'fintech';

  /**
  * Boot the application events.
  */
  public function boot(): void
  {
    $this->registerCommands();
    $this->registerCommandSchedules();
    $this->registerTranslations();
    $this->registerConfig();
    $this->registerViews();
    $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

    $this->app['router']->aliasMiddleware('pin.session', \Modules\FinTech\Http\Middleware\VerifyPinSession::class);
  }

  /**
  * Register the service provider.
  */
  public function register(): void
  {
    $this->app->register(EventServiceProvider::class);
    $this->app->register(RouteServiceProvider::class);

    $this->app->singleton(GoogleSheetsClient::class, function($app) {
      return new GoogleSheetsClient();
    });

    $this->app->singleton(Writers\ValueWriter::class);
    $this->app->singleton(Writers\BorderApplier::class);
    $this->app->singleton(Writers\ChartWriter::class);
    $this->app->singleton(Writers\ClearWriter::class);
    $this->app->singleton(Writers\ColorApplier::class);
    $this->app->singleton(Writers\CurrencyFormatter::class);
    $this->app->singleton(Writers\DataWriter::class);
    $this->app->singleton(Writers\FilterApplier::class);
    $this->app->singleton(Writers\FooterWriter::class);
    $this->app->singleton(Writers\HeaderWriter::class);
    $this->app->singleton(Writers\MetadataWriter::class);
    $this->app->singleton(Writers\SheetResizer::class);
    $this->app->singleton(Writers\StyleBuilder::class);
    $this->app->singleton(Writers\SummaryWriter::class);
    $this->app->singleton(Writers\TitleWriter::class);

    $this->app->make("config")->set("world.migrations.countries.table_name", "world_countries");
    $this->app->make("config")->set("world.migrations.states.table_name", "world_states");
    $this->app->make("config")->set("world.migrations.cities.table_name", "world_cities");
    $this->app->make("config")->set("world.migrations.currencies.table_name", "world_currencies");
    $this->app->make("config")->set("world.migrations.languages.table_name", "world_languages");
    $this->app->make("config")->set("world.migrations.timezones.table_name", "world_timezones");

    $this->app->singleton(BankParserManager::class, function($app) {
      $manager = new BankParserManager();
      $manager->addParser(new MandiriPdfParser());
      $manager->addParser(new MandiriExcelParser());
      $manager->addParser(new MandiriCsvParser());
      $manager->addParser(new BniPdfParser());
      // $manager->addParser(new BriPdfParser()); // Belum ada contoh file
      return $manager;
    });
  }

  /**
  * Register commands in the format of Command::class
  */
  protected function registerCommands(): void
  {
    $this->commands([
      \Modules\FinTech\Console\FetchExchangeRates::class,
      \Modules\FinTech\Console\GenerateNotifications::class,
    ]);
  }

  /**
  * Register command Schedules.
  */
  protected function registerCommandSchedules(): void
  {
    $this->app->booted(function () {
      //     $schedule = $this->app->make(Schedule::class);
      //     $schedule->command('inspire')->hourly();
      Schedule::command('app:exchange-rates')
      ->everySixHours()
      ->withoutOverlapping()
      ->timezone(config("app.timezone"));
      Schedule::command('app:notifications')
      ->everyThreeHours()
      ->withoutOverlapping()
      ->timezone(config("app.timezone"));
    });
  }

  /**
  * Register translations.
  */
  public function registerTranslations(): void
  {
    $langPath = resource_path('lang/modules/'.$this->nameLower);

    if (is_dir($langPath)) {
      $this->loadTranslationsFrom($langPath, $this->nameLower);
      $this->loadJsonTranslationsFrom($langPath);
    } else {
      $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
      $this->loadJsonTranslationsFrom(module_path($this->name, 'lang'));
    }
  }

  /**
  * Register config.
  */
  protected function registerConfig(): void
  {
    $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

    if (is_dir($configPath)) {
      $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

      foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
          $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
          $config_key = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
          $segments = explode('.', $this->nameLower.'.'.$config_key);

          // Remove duplicated adjacent segments
          $normalized = [];
          foreach ($segments as $segment) {
            if (end($normalized) !== $segment) {
              $normalized[] = $segment;
            }
          }

          $key = ($config === 'config.php') ? $this->nameLower : implode('.', $normalized);

          $this->publishes([$file->getPathname() => config_path($config)], 'config');
          $this->merge_config_from($file->getPathname(), $key);
        }
      }
    }
  }

  /**
  * Merge config from the given path recursively.
  */
  protected function merge_config_from(string $path, string $key): void
  {
    $existing = config($key, []);
    $module_config = require $path;

    config([$key => array_replace_recursive($existing, $module_config)]);
  }

  /**
  * Register views.
  */
  public function registerViews(): void
  {
    $viewPath = resource_path('views/modules/'.$this->nameLower);
    $sourcePath = module_path($this->name, 'resources/views');

    $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

    $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

    Blade::componentNamespace(config('modules.namespace').'\\' . $this->name . '\\View\\Components', $this->nameLower);
  }

  /**
  * Get the services provided by the provider.
  */
  public function provides(): array
  {
    return [];
  }

  private function getPublishableViewPaths(): array
  {
    $paths = [];
    foreach (config('view.paths') as $path) {
      if (is_dir($path.'/modules/'.$this->nameLower)) {
        $paths[] = $path.'/modules/'.$this->nameLower;
      }
    }

    return $paths;
  }
}