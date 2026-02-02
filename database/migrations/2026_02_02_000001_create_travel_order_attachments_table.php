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
        Schema::create('travel_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_order_id')->constrained('travel_orders')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('type', 100)->nullable(); // itinerary | memorandum | invitation | other
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_order_attachments');
    }
};
