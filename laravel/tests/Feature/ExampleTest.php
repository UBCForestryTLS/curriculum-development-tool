<?php

it('returns a successful response', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

it('confirms pest is running', function () {
    expect(true)->toBeTrue();
});
