<?php
// logout.php - Handle user logout with popup message
// Purpose: Destroy session, set logout message, and redirect to index page with popup
// Inputs: Session data
// Outputs: Popup message and redirect

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set logout message with username
$username = $_SESSION['username'] ?? 'Unknown User';
$_SESSION['logout_message'] = "User: $username has successfully been logged out";

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out</title>
    <script>
        // Display popup and redirect after user acknowledges
        window.onload = function() {
            alert('<?php echo addslashes($_SESSION['logout_message'] ?? 'User has successfully been logged out'); ?>');
            window.location.href = 'index.php';
        };
    </script>
</head>
<body>
    <!-- Body is empty as the script handles the action -->
  <script src="js/number-stepper.js" defer></script>
</body>
</html>



