<?php

test('home renders the exact FlowTrack template', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertViewIs('flowtrack-template');
});
