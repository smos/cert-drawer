<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetupAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:setup 
                            {email? : The email of the admin user} 
                            {--password= : The password for the user}
                            {--name= : The name of the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update a local administrator user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        if (!$email) {
            $email = $this->ask('Enter admin email');
        }

        $user = User::where('email', $email)->first();

        $name = $this->option('name');
        if (!$name) {
            $name = $user ? $user->name : $this->ask('Enter admin name', 'Administrator');
        }

        $password = $this->option('password');
        if (!$password) {
            $password = $this->secret('Enter admin password');
        }

        if (!$password) {
            $this->error('Password is required.');
            return 1;
        }

        if ($user) {
            $this->info("Updating existing user: {$email}");
            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
            ]);
        } else {
            $this->info("Creating new admin user: {$email}");
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
        }

        $this->info('Admin user setup successfully.');
        return 0;
    }
}
