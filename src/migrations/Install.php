<?php

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table as CraftTable;
use CraftCms\Cms\Support\Str;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use oncode\rawsearch\db\Table;
use oncode\rawsearch\services\ElementTypeConfigs;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Table::INDEX, function(Blueprint $table) {
            $table->id();
            $table->integer('elementId');
            $table->integer('siteId');
            $table->string('type');
            $table->string('attribute', 50)->nullable();
            $table->integer('fieldId')->nullable();
            $table->mediumText('normalizedWords');
            $table->mediumText('text');
            $table->dateTime('dateIndexed');
            $table->index(['elementId', 'siteId']);
            $table->index(['siteId', 'type']);
            $table->foreign('elementId')->references('id')->on(CraftTable::ELEMENTS)->cascadeOnDelete();
            $table->foreign('siteId')->references('id')->on(CraftTable::SITES)->cascadeOnDelete()->cascadeOnUpdate();
        });

        if (DB::isMysql()) {
            // The stopword list is bound to the fulltext index when it gets created.
            // Without stopwords, common words like "about" or "und" stay searchable.
            DB::statement('SET SESSION innodb_ft_enable_stopword = 0');
            Schema::table(Table::INDEX, fn(Blueprint $table) => $table->fullText('normalizedWords'));
        }

        Schema::create(Table::ELEMENT_TYPE_CONFIGS, function(Blueprint $table) {
            $table->id();
            $table->string('type')->unique();
            $table->boolean('index')->default(true);
            $table->smallInteger('matchWeight')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
        });

        Schema::create(Table::FIELD_CONFIGS, function(Blueprint $table) {
            $table->id();
            $table->integer('fieldId')->unique();
            $table->boolean('index')->default(true);
            $table->smallInteger('matchWeight')->nullable();
            $table->smallInteger('partialMatchWeight')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
            $table->foreign('fieldId')->references('id')->on(CraftTable::FIELDS)->cascadeOnDelete();
        });

        Schema::create(Table::QUERIES, function(Blueprint $table) {
            $table->id();
            $table->integer('siteId');
            $table->string('query');
            $table->boolean('or')->default(false);
            $table->smallInteger('mode');
            $table->integer('results')->default(0);
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
            $table->index(['siteId', 'query']);
            $table->index(['dateCreated']);
            $table->foreign('siteId')->references('id')->on(CraftTable::SITES)->cascadeOnDelete()->cascadeOnUpdate();
        });

        $now = now();

        DB::table(Table::ELEMENT_TYPE_CONFIGS)->insert(array_map(fn($type) => [
            'type' => $type,
            'index' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid7()->toString(),
        ], ElementTypeConfigs::DEFAULT_BLACKLISTED));
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::QUERIES);
        Schema::dropIfExists(Table::FIELD_CONFIGS);
        Schema::dropIfExists(Table::ELEMENT_TYPE_CONFIGS);
        Schema::dropIfExists(Table::INDEX);
    }
};
