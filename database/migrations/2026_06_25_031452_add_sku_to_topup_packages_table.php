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
        Schema::table('topup_packages', function (Blueprint $table) {
            // 🎯 ថែម Column sku ជាប្រភេទ String ឬ Integer និងអនុញ្ញាតឱ្យវា Nullable (ទទេបាន)
            // ->after('price') មានន័យថាឱ្យវាទៅឈរនៅបន្ទាប់ពីប្រអប់តម្លៃ price ដើម្បីឱ្យងាយស្រួលមើល
            $table->string('sku')->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('topup_packages', function (Blueprint $table) {
            // 🎯 សម្រាប់លុប Column sku នេះចេញវិញ ប្រសិនបើបងរត់ Rollback
            $table->dropColumn('sku');
        });
    }
};