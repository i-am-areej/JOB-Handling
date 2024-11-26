<?php

namespace Tests\Unit;

use Tests\TestCase;
use DTApi\Repository\UserRepository;
use DTApi\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CreateUpdateUserTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test createOrUpdate method for creating a new user.
     */
    public function testCreateOrUpdateCreatesNewUser()
    {
        $request = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password',
            'company_id' => '',
            'department_id' => '',
            'dob_or_orgid' => $this->faker->date,
            'phone' => $this->faker->phoneNumber,
            'mobile' => $this->faker->phoneNumber,
            'consumer_type' => 'paid',
            'customer_type' => 'individual',
            'username' => $this->faker->userName,
            'post_code' => $this->faker->postcode,
            'address' => $this->faker->address,
            'city' => $this->faker->city,
            'town' => $this->faker->city,
            'country' => $this->faker->country,
        ];

        $userRepository = new UserRepository(new User());
        $user = $userRepository->createOrUpdate(null, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals($request['email'], $user->email);
        $this->assertEquals($request['name'], $user->name);
    }

    /**
     * Test createOrUpdate method for updating an existing user.
     */
    public function testCreateOrUpdateUpdatesExistingUser()
    {
        $existingUser = User::factory()->create();

        $request = [
            'role' => $existingUser->user_type,
            'name' => 'Updated Name',
            'email' => $existingUser->email,
            'password' => null,
            'company_id' => '',
            'department_id' => '',
            'dob_or_orgid' => $this->faker->date,
            'phone' => $existingUser->phone,
            'mobile' => $existingUser->mobile,
            'consumer_type' => 'paid',
            'customer_type' => 'individual',
            'username' => 'UpdatedUsername',
            'post_code' => $this->faker->postcode,
            'address' => $this->faker->address,
            'city' => $this->faker->city,
            'town' => $this->faker->city,
            'country' => $this->faker->country,
        ];

        $userRepository = new UserRepository(new User());
        $updatedUser = $userRepository->createOrUpdate($existingUser->id, $request);

        $this->assertInstanceOf(User::class, $updatedUser);
        $this->assertEquals($request['name'], $updatedUser->name);
        $this->assertEquals('Updated Name', $updatedUser->name);
        $this->assertEquals('UpdatedUsername', $updatedUser->username);
    }

    /**
     * Test createOrUpdate handles missing password for update correctly.
     */
    public function testCreateOrUpdateHandlesMissingPassword()
    {
        $existingUser = User::factory()->create(['password' => bcrypt('existingpassword')]);

        $request = [
            'role' => $existingUser->user_type,
            'name' => $existingUser->name,
            'email' => $existingUser->email,
            'password' => null,
            'company_id' => '',
            'department_id' => '',
            'dob_or_orgid' => $this->faker->date,
            'phone' => $existingUser->phone,
            'mobile' => $existingUser->mobile,
        ];

        $userRepository = new UserRepository(new User());
        $updatedUser = $userRepository->createOrUpdate($existingUser->id, $request);

        $this->assertInstanceOf(User::class, $updatedUser);
        $this->assertTrue(password_verify('existingpassword', $updatedUser->password));
    }
}