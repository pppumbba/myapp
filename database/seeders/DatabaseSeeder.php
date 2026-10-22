<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([MetaTypeSeeder::class, WidgetSeeder::class, ProcurementDemoSeeder::class, CalendarDemoSeeder::class, GanttDemoSeeder::class, LmsDemoSeeder::class, TenderDemoSeeder::class]);

        // User::factory(10)->create();

        User::factory()->admin()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
