<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(['email' => 'admin@helio.test'], ['name' => 'Helio Admin', 'role' => 'admin', 'password' => Hash::make('password')]);
        $staffUsers = collect([
            ['name' => 'Alex Morgan', 'email' => 'alex@helio.test', 'color' => 'purple'],
            ['name' => 'Jamie Lee', 'email' => 'jamie@helio.test', 'color' => 'blue'],
            ['name' => 'Priya Shah', 'email' => 'priya@helio.test', 'color' => 'orange'],
        ])->map(fn (array $staff) => [$staff, User::query()->updateOrCreate(['email' => $staff['email']], ['name' => $staff['name'], 'role' => 'staff', 'password' => Hash::make('password')])]);

        $staffProfiles = $staffUsers->map(fn (array $entry) => StaffProfile::query()->updateOrCreate(['user_id' => $entry[1]->id], ['display_name' => $entry[0]['name'], 'color' => $entry[0]['color'], 'is_active' => true]));
        $services = Service::factory()->count(3)->create();
        $customers = Customer::factory()->count(8)->create();
        $starts = [
            ['09:00', 0, 'confirmed'], ['10:30', 1, 'pending'], ['13:00', 0, 'confirmed'],
            ['11:30', 2, 'confirmed'], ['15:00', 1, 'cancelled'], ['09:30', 2, 'confirmed'], ['14:00', 0, 'pending'],
        ];

        foreach ($starts as $index => [$time, $staffIndex, $status]) {
            $start = Carbon::parse('next monday '.$time, 'Asia/Ho_Chi_Minh')->addDays(intdiv($index, 2));
            $appointment = Appointment::create([
                'customer_id' => $customers[$index]->id,
                'service_id' => $services[$index % $services->count()]->id,
                'staff_profile_id' => $staffProfiles[$staffIndex]->id,
                'created_by' => $admin->id,
                'title' => ['Brand strategy session', 'Website consultation', 'Product discovery call', 'Onboarding check-in', 'Growth review', 'UX audit handoff', 'Quarterly planning'][$index],
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(45),
                'status' => $status,
                'timezone' => 'Asia/Ho_Chi_Minh',
            ]);
            $appointment->statusHistory()->create(['to_status' => $status, 'changed_by' => $admin->id]);
        }
    }
}
