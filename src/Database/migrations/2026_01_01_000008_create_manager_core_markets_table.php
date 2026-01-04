<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ManagerCore\Models\Market;

class CreateManagerCoreMarketsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('manager_core_markets')) {
            Schema::create('manager_core_markets', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->bigInteger('region_id');
                $table->json('system_ids');
                $table->boolean('is_enabled')->default(true);
                $table->boolean('is_custom')->default(false);
                $table->timestamps();

                $table->index(['is_enabled', 'is_custom']);
            });

            // Seed default markets
            $this->seedDefaultMarkets();
        }
    }

    /**
     * Seed default markets from config
     */
    protected function seedDefaultMarkets()
    {
        $defaultMarkets = [
            'jita' => [
                'name' => 'Jita (The Forge)',
                'region_id' => 10000002,
                'system_ids' => [30000142],
            ],
            'amarr' => [
                'name' => 'Amarr (Domain)',
                'region_id' => 10000043,
                'system_ids' => [30002187],
            ],
            'dodixie' => [
                'name' => 'Dodixie (Sinq Laison)',
                'region_id' => 10000032,
                'system_ids' => [30002659],
            ],
            'hek' => [
                'name' => 'Hek (Metropolis)',
                'region_id' => 10000042,
                'system_ids' => [30002053],
            ],
            'rens' => [
                'name' => 'Rens (Heimatar)',
                'region_id' => 10000030,
                'system_ids' => [30002510],
            ],
        ];

        foreach ($defaultMarkets as $key => $market) {
            Market::create([
                'key' => $key,
                'name' => $market['name'],
                'region_id' => $market['region_id'],
                'system_ids' => $market['system_ids'],
                'is_enabled' => true,
                'is_custom' => false,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('manager_core_markets');
    }
}
