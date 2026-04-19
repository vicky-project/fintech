<?php
namespace Modules\FinTech\Installations;

use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Facades\Artisan;

class PostInstallation
{
  public function handle(string $moduleName) {
    try {
      $modules = array_merge(["telegram"], [$moduleName]);
      foreach ($modules as $modulename) {
        $module = Module::find($modulename);
        $module->enable();
      }

      Artisan::call("migrate");
      Artisan::call("world:install");
    } catch (\Exception $e) {
      logger()->error(
        "Failed to run post installation of fintech module: " .
        $e->getMessage(),
      );

      throw $e;
    }
  }
}