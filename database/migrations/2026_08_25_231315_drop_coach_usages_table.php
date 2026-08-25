<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('coach_usages');
    }

    /**
     * Deliberately irreversible. The app no longer meters token spend, so
     * recreating an empty table would restore the shape without the meaning.
     */
    public function down(): void
    {
        //
    }
};
