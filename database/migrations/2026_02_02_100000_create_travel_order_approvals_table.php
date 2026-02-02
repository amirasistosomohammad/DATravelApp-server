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
        Schema::create('travel_order_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('travel_order_id')->constrained('travel_orders')->cascadeOnDelete();
            $table->foreignId('director_id')->constrained('directors')->cascadeOnDelete();
            $table->unsignedTinyInteger('step_order')->default(1); // 1 = recommend, 2 = approve
            $table->string('status', 50)->default('pending'); // pending | recommended | approved | rejected
            $table->text('remarks')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->unique(['travel_order_id', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('travel_order_approvals');
    }
};
