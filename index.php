<?php
// ==========================================
// index.php — entry-point redirect
// login/session guards across the app redirect logged-out
// users to index.php; the actual login form is login.php.
// ==========================================
header("Location: login.php");
exit();
