<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('geocoded_places', function (Blueprint $table): void {
            $table->id();
            $table->string('precision_tier', 16);
            $table->unsignedTinyInteger('zoom');
            $table->integer('lat_bucket');
            $table->integer('lng_bucket');
            $table->double('lat');
            $table->double('lng');
            $table->string('provider_host', 120);
            $table->string('osm_type', 16)->nullable();
            $table->unsignedBigInteger('osm_id')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('type', 64)->nullable();
            $table->string('feature_name', 160)->nullable();
            $table->string('road', 160)->nullable();
            $table->string('neighbourhood', 160)->nullable();
            $table->string('suburb', 160)->nullable();
            $table->string('city', 160)->nullable();
            $table->string('region', 160)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('display_label', 160)->nullable();
            $table->string('area_label', 160)->nullable();
            $table->json('raw_address')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(
                ['precision_tier', 'lat_bucket', 'lng_bucket'],
                'geocoded_places_tier_bucket_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geocoded_places');
    }
};
