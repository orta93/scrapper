<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('social_id');
            $table->string('alter_id')->nullable();
            $table->string('username');
            $table->string('full_name');
            $table->bigInteger('total_followers')->default(0);
            $table->bigInteger('total_following')->default(0);
            $table->bigInteger('total_likes')->default(0);
            $table->bigInteger('total_views')->default(0);
            $table->bigInteger('total_videos')->default(0);
            $table->string('profile_picture_url', 1024);
            $table->longText('bio')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('accounts');
    }
}
