<?php

use App\Models\Category;
use App\Models\Team;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create a "General" category for each existing team
        Team::all()->each(function (Team $team) {
            $category = Category::create([
                'team_id' => $team->id,
                'name' => 'General',
                'color' => '#6B7280', // gray-500
                'description' => 'Default category for uncategorized work',
                'sort_order' => 0,
                'is_default' => true,
            ]);

            // Assign all existing epics for this team to the General category
            $team->epics()->update(['category_id' => $category->id]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove category assignments from epics
        \DB::table('epics')->update(['category_id' => null]);

        // Delete all default categories
        Category::where('is_default', true)->delete();
    }
};
