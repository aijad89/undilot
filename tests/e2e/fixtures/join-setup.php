<?php

// One published event with registration open and nobody on its draw list, so a
// seeded participant can walk the join steps. Prints the event id.
//
// Tenun E2E starts with an empty profile, so its spec fills one in first; Logam
// and Kayu have the plainest complete one, so theirs go straight to joining.

use App\Models\Company;
use App\Models\Event;

// The tenun maker carries its craft code, so its profile asks the quality question.
Company::where('name', 'Tenun E2E')->firstOrFail()->forceFill(['kraf_code' => 'TTN', 'credentials' => null])->save();

foreach (['Logam E2E', 'Kayu E2E'] as $name) {
    Company::where('name', $name)->firstOrFail()->forceFill(['credentials' => [
        'recognition' => 'none', 'classification' => 'no', 'classification_cert' => null,
        'quality' => null, 'quality_cert' => null,
        'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789']], 'product_images' => [],
    ]])->save();
}

$event = Event::create([
    'title' => 'Join Fair '.uniqid(),
    'location' => 'Dewan Sertai',
    'state' => 'Johor',
    'status' => 'open',
    'start_date' => now()->addMonth(),
    'end_date' => now()->addMonth()->addDay(),
    'registration_open_at' => now()->subDay(),
    'registration_close_at' => now()->addWeek(),
    'max_participants' => 10,
]);

echo $event->id, PHP_EOL;
