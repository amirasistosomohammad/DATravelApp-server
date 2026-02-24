<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Editors can be invited by the TO owner to edit a travel order (draft or rejected).
     */
    public function up(): void
    {
        Schema::create('travel_order_editors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_order_id')->constrained('travel_orders')->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('personnel')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['travel_order_id', 'personnel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_order_editors');
    }
};
