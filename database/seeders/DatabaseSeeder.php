<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Dispatch;
use App\Models\DispatchLog;
use App\Models\Driver;
use App\Models\FuelLog;
use App\Models\LocationLog;
use App\Models\Reservation;
use App\Models\TripRecord;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $passwordField = User::passwordColumnName();

        User::updateOrCreate(
            ['email' => 'reybie@fleetops.com'],
            ['name' => 'Reybie R.', $passwordField => Hash::make('password123'), 'role' => 'Admin', 'status' => 'active'],
        );

        // $accounts = [
        //     [
        //         'name' => 'Maria Santos',
        //         'email' => 'maria@fleetops.com',
        //         'password' => 'maria123',
        //         'role' => 'Manager',
        //         'status' => 'active',
        //     ],
        //     [
        //         'name' => 'Juan dela Cruz',
        //         'email' => 'juan@fleetops.com',
        //         'password' => 'juan123',
        //         'role' => 'Dispatcher',
        //         'status' => 'active',
        //     ],
        //     [
        //         'name' => 'Ana Garcia',
        //         'email' => 'ana@fleetops.com',
        //         'password' => 'ana123',
        //         'role' => 'Accountant',
        //         'status' => 'active',
        //     ],
        //     [
        //         'name' => 'Pedro Reyes',
        //         'email' => 'pedro@fleetops.com',
        //         'password' => 'pedro123',
        //         'role' => 'Admin',
        //         'status' => 'inactive',
        //     ],
        // ];

        // foreach ($accounts as $account) {
        //     User::updateOrCreate(
        //         ['email' => $account['email']],
        //         [
        //             'name' => $account['name'],
        //             $passwordField => Hash::make($account['password']),
        //             'role' => $account['role'],
        //             'status' => $account['status'],
        //         ]
        //     );
        // }

        // Coherent sample data for local demos and project defense.
        $users = User::factory(9)->create(['role' => 'User', 'status' => 'active']);
        $vehicles = Vehicle::factory(10)->create();
        $drivers = Driver::factory(10)->recycle($users)->create();
        $reservations = Reservation::factory(10)->recycle($users)->create();
        $dispatches = Dispatch::factory(10)
            ->recycle($vehicles)
            ->recycle($drivers)
            ->recycle($reservations)
            ->create();
        $trips = TripRecord::factory(10)
            ->recycle($dispatches)
            ->recycle($vehicles)
            ->recycle($drivers)
            ->create();
        LocationLog::factory(50)->recycle($vehicles)->create();
        FuelLog::factory(20)->recycle($vehicles)->recycle($dispatches)->create();
        Alert::factory(20)->recycle($vehicles)->recycle($trips)->create();
        DispatchLog::factory(20)->recycle($dispatches)->recycle($users)->create();
    }
}
