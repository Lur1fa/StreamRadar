<?php

test('the application requires authentication', function () {
    // The app is never open: an unauthenticated request is redirected to login.
    $this->get('/')->assertRedirect('/login');
});
