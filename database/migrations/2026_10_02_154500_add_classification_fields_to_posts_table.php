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
        Schema::table('posts', function (Blueprint $table) {
            $table->string('classification_status')->default('pending')->after('presented_at');
            $table->boolean('classification_relevant')->nullable()->after('classification_status');
            $table->float('classification_score')->nullable()->after('classification_relevant');
            $table->string('classification_category')->nullable()->after('classification_score');
            $table->float('classification_content_value_score')->nullable()->after('classification_category');
            $table->float('classification_adaptability_score')->nullable()->after('classification_content_value_score');
            $table->boolean('classification_profile_fit')->nullable()->after('classification_adaptability_score');
            $table->boolean('classification_requires_missing_media')->nullable()->after('classification_profile_fit');
            $table->timestamp('classified_at')->nullable()->after('classification_requires_missing_media');
            $table->text('classification_error')->nullable()->after('classified_at');

            $table->index('classification_status');
            $table->index(['classification_relevant', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['classification_status']);
            $table->dropIndex(['classification_relevant', 'published_at']);
            $table->dropColumn([
                'classification_status',
                'classification_relevant',
                'classification_score',
                'classification_category',
                'classification_content_value_score',
                'classification_adaptability_score',
                'classification_profile_fit',
                'classification_requires_missing_media',
                'classified_at',
                'classification_error',
            ]);
        });
    }
};
