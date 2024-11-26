<?php

namespace Tests\Unit;

use Tests\TestCase;
use DTApi\Helpers\TeHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TeHelperTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * Test willExpireAt method with due_time within 90 minutes.
     */
    public function testWillExpireAtWithin90Minutes()
    {
        $dueTime = Carbon::now()->addMinutes(45);
        $createdAt = Carbon::now();

        $expectedTime = $dueTime->format('Y-m-d H:i:s');
        $actualTime = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expectedTime, $actualTime);
    }

    /**
     * Test willExpireAt method with due_time less than 24 hours.
     */
    public function testWillExpireAtWithin24Hours()
    {
        $dueTime = Carbon::now()->addHours(10);
        $createdAt = Carbon::now();

        $expectedTime = $createdAt->copy()->addMinutes(90)->format('Y-m-d H:i:s');
        $actualTime = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expectedTime, $actualTime);
    }

    /**
     * Test willExpireAt method with due_time between 24 and 72 hours.
     */
    public function testWillExpireAtBetween24And72Hours()
    {
        $dueTime = Carbon::now()->addHours(48);
        $createdAt = Carbon::now();

        $expectedTime = $createdAt->copy()->addHours(16)->format('Y-m-d H:i:s');
        $actualTime = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expectedTime, $actualTime);
    }

    /**
     * Test willExpireAt method with due_time greater than 72 hours.
     */
    public function testWillExpireAtGreaterThan72Hours()
    {
        $dueTime = Carbon::now()->addHours(100);
        $createdAt = Carbon::now();

        $expectedTime = $dueTime->copy()->subHours(48)->format('Y-m-d H:i:s');
        $actualTime = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expectedTime, $actualTime);
    }

    /**
     * Test willExpireAt method with edge case of exact 24 hours.
     */
    public function testWillExpireAtExact24Hours()
    {
        $dueTime = Carbon::now()->addHours(24);
        $createdAt = Carbon::now();

        $expectedTime = $createdAt->copy()->addMinutes(90)->format('Y-m-d H:i:s');
        $actualTime = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($expectedTime, $actualTime);
    }
}
