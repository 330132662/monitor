<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('domain');
            $table->boolean('is_online')->default(0)->comment('是否在线');
            $table->boolean('is_new')->default(0)->comment('是否最新');
            $table->enum('spider_type', \App\Enums\SpiderTypeEnum::getValues())->comment('爬虫或者 API');
            $table->string('date_xpath')->nullable()->comment('最新日期的 xpath');
            $table->string('date_format')->nullable()->comment('最新日期的格式');
            $table->string('need_string')->comment('需要包含字符串');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
