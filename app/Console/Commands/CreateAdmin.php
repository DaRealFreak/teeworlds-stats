<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    /**
     * Registration is closed (admin-only app), so admin accounts are provisioned
     * from the CLI instead of a public sign-up form. Credentials are prompted for
     * interactively so they never land in git or shell history.
     */
    protected $signature = 'admin:create';

    protected $description = 'Create an admin user (interactive; public registration is closed)';

    public function handle(): int
    {
        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password (min 8 chars)');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // The User model casts `password` to `hashed`, so the plain value is hashed on save.
        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $this->info("Admin user {$email} created.");

        return self::SUCCESS;
    }
}
