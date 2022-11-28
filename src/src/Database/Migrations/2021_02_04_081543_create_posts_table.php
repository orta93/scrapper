<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePostsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('post_identifier')->nullable();
            $table->integer('account_id');
            $table->string('url', 1024);
            $table->timestamp('date')->nullable();
            $table->boolean('is_story')->default(false);
            $table->boolean('is_tv')->default(false);
            $table->boolean('is_reel')->default(false);
            $table->boolean('is_video')->default(false);
            $table->string('display_url', 1024)->nullable();
            $table->string('caption', 2048)->default('');
            $table->longText('description')->nullable();
            $table->bigInteger('likes')->default(0);
            $table->bigInteger('dislikes')->default(0);
            $table->bigInteger('comments')->default(0);
            $table->bigInteger('views')->default(0);
            $table->bigInteger('shares')->default(0);
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
        Schema::dropIfExists('posts');
    }
}
