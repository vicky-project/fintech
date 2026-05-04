<?php

namespace Modules\FinTech\Database\Seeders;

use Illuminate\Database\Seeder;

class FinTechDatabaseSeeder extends Seeder
{
  /**
  * Run the database seeds.
  */
  public function run(): void
  {
    $this->call([CategorySeeder::class]);
  }
}