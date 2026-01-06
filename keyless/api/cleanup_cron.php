<?php
/**
 * CRON JOB: Cleanup Collected Items from Hardware Sync
 * Deletes all rows where action = 'collected'
 */

// Include database configuration
require_once __DIR__ . '/config.php';

// Log script start
logActivity("Starting cleanup_collected cronjob", "INFO");

try {
    // Prepare delete statement
    $sql = "DELETE FROM hardware_sync WHERE action = 'collected'";
    
    // Execute the deletion
    if ($conn->query($sql) === TRUE) {
        $deleted_rows = $conn->affected_rows;
        
        // Log success
        $message = "Successfully deleted {$deleted_rows} row(s) with action 'collected'";
        logActivity($message, "INFO");
        echo $message . "\n";
        
        // Optional: You can also log to a separate cron log
        if ($deleted_rows > 0) {
            logActivity("Cleanup removed {$deleted_rows} collected items from hardware_sync", "SUCCESS");
        }
    } else {
        // Log error
        $error_message = "Error deleting records: " . $conn->error;
        logActivity($error_message, "ERROR");
        echo $error_message . "\n";
    }
    
} catch (Exception $e) {
    // Catch any exceptions
    $error_message = "Exception in cleanup script: " . $e->getMessage();
    logActivity($error_message, "ERROR");
    echo $error_message . "\n";
}

// Close connection
$conn->close();

// Log script end
logActivity("Cleanup_collected cronjob completed", "INFO");

?>